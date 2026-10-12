# Daily coordinator for the existing Windows installation. Windows PowerShell 5.1+.
[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = 'C:\Users\shoya\Desktop\BoatraceData\BoatraceResults'
$dataRoot = 'C:\Users\shoya\Desktop\BoatraceData'
$db = Join-Path $dataRoot 'boatrace_master.sqlite'
$php = 'C:\Users\shoya\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.5_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$sqlite = 'C:\Users\shoya\AppData\Local\Microsoft\WinGet\Packages\SQLite.SQLite_Microsoft.Winget.Source_8wekyb3d8bbwe\sqlite3.exe'
$scraper = Join-Path $root 'scraper_date.php'
$importer = Join-Path $root 'analysis_data\sqlite\update_v3_to_sqlite.php'
$enrichment = Join-Path $root 'analysis_data\sqlite\run_full_enrich_batch.php'
$verifier = Join-Path $root 'verify_boatrace_date.php'
$stateDir = Join-Path $root 'daily_verification'
$unresolved = New-Object 'System.Collections.Generic.List[string]'
$auditedDates = New-Object 'System.Collections.Generic.List[object]'
$lock = $null
$logWriter = $null
$pushed = $false
$exitCode = 1
$oldWorkers = [Environment]::GetEnvironmentVariable('BR_FETCH_WORKERS', 'Process')
$oldLimit = [Environment]::GetEnvironmentVariable('BR_BATCH_LIMIT', 'Process')

function Write-Log([string]$Message) {
    $line = '[{0}] {1}' -f [DateTimeOffset]::Now.ToString('yyyy-MM-ddTHH:mm:ss.fffzzz'), $Message
    if ($null -ne $script:logWriter) { $script:logWriter.WriteLine($line) }
    Write-Host $line
}
function Invoke-Native([string]$Executable, [string[]]$Arguments) {
    Write-Log ('RUN {0} {1}' -f $Executable, ($Arguments -join ' '))
    $saved = $ErrorActionPreference
    $loggingFailure = $null
    try {
        # Native stderr becomes ErrorRecord on PS 5.1. Log it; check exit code.
        $ErrorActionPreference = 'Continue'
        $global:LASTEXITCODE = $null
        & $Executable @Arguments 2>&1 | ForEach-Object {
            try { Write-Log ([string]$_) } catch { $loggingFailure = $_ }
        }
        $code = $global:LASTEXITCODE
    } finally { $ErrorActionPreference = $saved }
    if ($null -ne $loggingFailure) { throw $loggingFailure }
    if ($null -eq $code -or $code -ne 0) { throw "Command failed (exit $code): $Executable" }
}
function Read-Sql([string]$Database, [string]$Sql) {
    $saved = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $global:LASTEXITCODE = $null
        $rows = @(& $script:sqlite -batch -bail -readonly -noheader $Database '.timeout 30000' $Sql 2>&1)
        $code = $global:LASTEXITCODE
    } finally { $ErrorActionPreference = $saved }
    if ($null -eq $code -or $code -ne 0) {
        foreach ($row in $rows) { Write-Log ([string]$row) }
        throw "SQLite query failed (exit $code)."
    }
    foreach ($row in $rows) {
        if ($row -is [System.Management.Automation.ErrorRecord]) { throw "SQLite diagnostic: $row" }
        [string]$row
    }
}
function Assert-Database([string]$Database) {
    $check = @(Read-Sql $Database 'PRAGMA quick_check;')
    if ($check.Count -ne 1 -or $check[0] -cne 'ok') { throw "quick_check failed: $Database ($($check -join '; '))" }
    $fk = @(Read-Sql $Database 'PRAGMA foreign_key_check;')
    if ($fk.Count -ne 0) { throw "foreign_key_check failed: $Database ($($fk -join '; '))" }
    Write-Log "Database checks passed: $Database"
}
function Require-Fields($Value, [string[]]$Names, [string]$Context) {
    if ($null -eq $Value -or $Value -isnot [pscustomobject]) { throw "$Context must be an object." }
    foreach ($name in $Names) {
        if ($null -eq $Value.PSObject.Properties[$name]) { throw "$Context is missing $name." }
    }
}
function Require-Integer($Value, [long]$Min, [long]$Max, [string]$Context) {
    if (($Value -isnot [int] -and $Value -isnot [long]) -or $Value -lt $Min -or $Value -gt $Max) { throw "$Context must be an integer in $Min..$Max." }
}
function Require-Number($Value, [string]$Context) {
    if ($null -eq $Value) { return }
    if ($Value -isnot [int] -and $Value -isnot [long] -and $Value -isnot [double] -and $Value -isnot [decimal]) { throw "$Context must be numeric or null." }
    if ([double]::IsNaN([double]$Value) -or [double]::IsInfinity([double]$Value)) { throw "$Context must be finite." }
}
function Read-ValidatedKeys([string]$Path, [string]$Date) {
    if (!(Test-Path -LiteralPath $Path -PathType Leaf)) { throw "Missing JSON: $Path" }
    # PHP's parser rejects comments, trailing commas, invalid UTF-8, and malformed
    # syntax even on PowerShell versions whose JSON parser is permissive.
    $strictJson = 'try { json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }'
    Invoke-Native $script:php @('-r',$strictJson,$Path)
    $utf8 = New-Object System.Text.UTF8Encoding($false, $true)
    $raw = [IO.File]::ReadAllText($Path, $utf8)
    # Reject duplicate object property names, including escaped equivalents.
    # Syntax has already been checked; strings are matched as complete tokens.
    $stack = New-Object 'System.Collections.Generic.Stack[object]'
    foreach ($token in [regex]::Matches($raw, '"(?:[^"\\]|\\.)*"|[{}\[\]:,]|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null')) {
        $text = $token.Value
        if ($text -eq '{') {
            $stack.Push(@{ Kind='object'; Keys=(New-Object 'System.Collections.Generic.HashSet[string]'); ExpectKey=$true })
        } elseif ($text -eq '[') { $stack.Push(@{ Kind='array' }) }
        elseif ($text -eq '}' -or $text -eq ']') { $null = $stack.Pop() }
        elseif ($stack.Count -gt 0 -and $stack.Peek().Kind -eq 'object') {
            $frame = $stack.Peek()
            if ($text -eq ',') { $frame.ExpectKey = $true }
            elseif ($frame.ExpectKey -and $text.StartsWith('"')) {
                $name = ConvertFrom-Json -InputObject $text -ErrorAction Stop
                if (!$frame.Keys.Add([string]$name)) { throw "Duplicate JSON property: $name" }
                $frame.ExpectKey = $false
            }
        }
    }
    $json = ConvertFrom-Json -InputObject $raw -ErrorAction Stop
    Require-Fields $json @('results') 'JSON root'
    if ($json.results -isnot [array] -or $json.results.Count -eq 0) { throw 'results must be a nonempty array.' }
    $keys = New-Object 'System.Collections.Generic.HashSet[string]'
    foreach ($race in $json.results) {
        Require-Fields $race @('date','stadium_number','number','wind_speed','wind_direction_number','wave_height','weather_number','air_temperature','water_temperature','technique_number','boats','payouts') 'Race'
        if ($race.date -isnot [string] -or $race.date -cne $Date) { throw "Race date must equal $Date." }
        Require-Integer $race.stadium_number 1 24 'stadium_number'
        Require-Integer $race.number 1 12 'race number'
        $key = '{0}|{1}|{2}' -f $Date, $race.stadium_number, $race.number
        if (!$keys.Add($key)) { throw "Duplicate race key: $key" }
        foreach ($field in @('wind_speed','wave_height','air_temperature','water_temperature')) { Require-Number $race.$field "$key $field" }
        foreach ($field in @('wind_direction_number','weather_number','technique_number')) {
            if ($null -ne $race.$field) { Require-Integer $race.$field 0 2147483647 "$key $field" }
        }
        if ($race.boats -isnot [array] -or $race.boats.Count -lt 1 -or $race.boats.Count -gt 6) { throw "$key boats must contain 1..6 entries." }
        $boats = New-Object 'System.Collections.Generic.HashSet[int]'
        foreach ($boat in $race.boats) {
            Require-Fields $boat @('racer_boat_number','racer_course_number','racer_start_timing','racer_place_number','racer_number','racer_name') "$key boat"
            Require-Integer $boat.racer_boat_number 1 6 "$key boat number"
            if (!$boats.Add([int]$boat.racer_boat_number)) { throw "$key duplicate boat number." }
            if ($null -ne $boat.racer_course_number) { Require-Integer $boat.racer_course_number 1 6 "$key course" }
            # Existing nonfinish place codes may exceed six.
            if ($null -ne $boat.racer_place_number) { Require-Integer $boat.racer_place_number 0 2147483647 "$key place" }
            if ($null -ne $boat.racer_number) { Require-Integer $boat.racer_number 1 2147483647 "$key racer" }
            Require-Number $boat.racer_start_timing "$key timing"
            if ($null -ne $boat.racer_name -and $boat.racer_name -isnot [string]) { throw "$key invalid racer_name." }
        }
        Require-Fields $race.payouts @('trifecta','trio','exacta','quinella','quinella_place','win','place') "$key payouts"
        foreach ($property in $race.payouts.PSObject.Properties) {
            $pattern = switch ($property.Name) {
                'trifecta' { '^[1-6]-[1-6]-[1-6]$' }
                'trio' { '^[1-6]=[1-6]=[1-6]$' }
                'exacta' { '^[1-6]-[1-6]$' }
                'quinella' { '^[1-6]=[1-6]$' }
                'quinella_place' { '^[1-6]=[1-6]$' }
                'win' { '^[1-6]$' }
                'place' { '^[1-6]$' }
                default { throw "$key unknown payout type: $($property.Name)" }
            }
            if ($property.Value -isnot [array]) { throw "$key payout items must be arrays." }
            $seen = New-Object 'System.Collections.Generic.HashSet[string]'
            foreach ($item in $property.Value) {
                Require-Fields $item @('combination','amount') "$key payout"
                if ($item.combination -isnot [string] -or $item.combination -cnotmatch $pattern) { throw "$key invalid payout combination." }
                $parts = @($item.combination -split '[-=]')
                if (@($parts | Select-Object -Unique).Count -ne $parts.Count) { throw "$key repeated boat in payout." }
                if (!$seen.Add($item.combination)) { throw "$key duplicate payout." }
                Require-Integer $item.amount 0 2147483647 "$key amount"
            }
        }
    }
    Write-Log "Validated $($keys.Count) race keys: $Path"
    foreach ($key in $keys) { $key }
}
# Backup targets are unique per run and phase. SQLite's backup API includes WAL.
function New-ValidatedBackup([string]$Phase) {
    Assert-Database $script:db
    $target = Join-Path $script:dataRoot "boatrace_master_${Phase}_$($script:runId).sqlite"
    if (Test-Path -LiteralPath $target) { throw "Backup already exists: $target" }
    $cliPath = $target.Replace('\','/')
    Invoke-Native $script:sqlite @('-batch','-bail','-readonly',$script:db,'.timeout 30000',(".backup '{0}'" -f $cliPath))
    if (!(Test-Path -LiteralPath $target -PathType Leaf) -or (Get-Item -LiteralPath $target).Length -eq 0) { throw "Backup missing or empty: $target" }
    Assert-Database $target
    Write-Log "Validated $Phase backup: $target"
}
function Save-DateState([string]$Date, $State) {
    $target = Join-Path $script:stateDir "$Date.json"
    $temp = Join-Path $script:stateDir "$Date.$([guid]::NewGuid().ToString('N')).tmp"
    $bytes = (New-Object Text.UTF8Encoding($false)).GetBytes(($State | ConvertTo-Json -Depth 30))
    $stream = [IO.File]::Open($temp, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try { $stream.Write($bytes, 0, $bytes.Length); $stream.Flush($true) } finally { $stream.Dispose() }
    try {
        if (Test-Path -LiteralPath $target) { [IO.File]::Replace($temp, $target, [System.Management.Automation.Language.NullString]::Value) }
        else { [IO.File]::Move($temp, $target) }
    } finally { if (Test-Path -LiteralPath $temp) { [IO.File]::Delete($temp) } }
}
function Get-JsonHash([string]$Path) {
    if (Test-Path -LiteralPath $Path -PathType Leaf) { return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() }
    return $null
}
function Read-Official([string]$Date, [string]$Path) {
    $saved = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $global:LASTEXITCODE = $null
        $output = @(& $script:php $script:verifier $Date $Path 2>&1)
        $code = $global:LASTEXITCODE
    } finally { $ErrorActionPreference = $saved }
    if ($null -eq $code -or $code -ne 0) { throw "Official verification unresolved/conflict: $Date ($($output -join ' '))" }
    $state = ConvertFrom-Json -InputObject ($output -join "`n") -ErrorAction Stop
    Require-Fields $state @('version','date','status','json_sha256','keys','cancelled_keys','evidence') 'Official evidence'
    if ($state.version -ne 2 -or $state.date -cne $Date -or $state.status -notin @('source_verified','verified_no_races') -or @($state.evidence).Count -eq 0) { throw "Invalid official evidence: $Date" }
    foreach ($item in $state.evidence) {
        if ($item.url -notmatch '^https://www\.boatrace\.jp/owpc/pc/race/(index|resultlist|raceresult)\?' -or $item.sha256 -notmatch '^[a-f0-9]{64}$') { throw "Invalid official source: $Date" }
    }
    if ($state.json_sha256 -cne (Get-JsonHash $Path)) { throw "JSON changed during source verification: $Date" }
    return $state
}
function Sql-Value($Value) {
    if ($null -eq $Value) { return 'NULL' }
    if ($Value -is [string]) { return "'" + $Value.Replace("'", "''") + "'" }
    return ([Convert]::ToString($Value, [Globalization.CultureInfo]::InvariantCulture))
}
function Assert-ImportedContent([string]$Date, [string]$Path, [string[]]$Expected, [bool]$AllowMissing = $false, [string[]]$CancelledKeys = @()) {
    $actual = @(Read-Sql $script:db "SELECT race_date || '|' || stadium_number || '|' || race_number FROM races WHERE race_date='$Date';")
    if (@($actual | Where-Object { $_ -notin $Expected }).Count -gt 0 -or
        (!$AllowMissing -and ($actual.Count -ne $Expected.Count -or
        ($Expected.Count -gt 0 -and @(Compare-Object $Expected $actual).Count -ne 0)))) { throw "DB source race conflict: $Date" }
    if ($Expected.Count -eq 0) { return }
    $json = ConvertFrom-Json ([IO.File]::ReadAllText($Path))
    foreach ($race in $json.results) {
        $key = '{0}|{1}|{2}' -f $Date, $race.stadium_number, $race.number
        if ($AllowMissing -and $key -notin $actual) { continue }
        $where = "race_date='$Date' AND stadium_number=$($race.stadium_number) AND race_number=$($race.number)"
        $checks = @("result_fetch_status IN ('OK','success')")
        foreach ($pair in @(
            @('wind_speed','result_wind_speed'), @('wind_direction_number','result_wind_direction_number'),
            @('wave_height','result_wave_height'), @('weather_number','result_weather_number'),
            @('air_temperature','result_air_temperature'), @('water_temperature','result_water_temperature'),
            @('technique_number','technique_number'))) {
            $checks += "$($pair[1]) IS $(Sql-Value $race.($pair[0]))"
        }
        $count = @(Read-Sql $script:db "SELECT COUNT(*) FROM races WHERE $where AND $($checks -join ' AND ');")
        if ($count.Count -ne 1 -or $count[0] -ne '1') { throw "DB result payload conflict: $Date" }
        $id = "(SELECT race_id FROM races WHERE $where)"
        $entryCount = @(Read-Sql $script:db "SELECT COUNT(*) FROM race_entries WHERE race_id=$id;")
        if ($entryCount.Count -ne 1 -or [int]$entryCount[0] -ne $race.boats.Count) { throw "DB boat coverage conflict: $Date" }
        foreach ($boat in $race.boats) {
            $checks = @("race_id=$id", "boat_number=$($boat.racer_boat_number)")
            foreach ($pair in @(@('racer_number','racer_number'), @('racer_name','racer_name'),
                @('racer_course_number','actual_course_number'), @('racer_start_timing','actual_start_timing'),
                @('racer_place_number','place_number'))) {
                # A verified cancellation can leave result identity placeholders;
                # enrichment fills these from the program. Only null identities
                # are unspecified: all actual result fields remain exact checks.
                if ($key -in $CancelledKeys -and $pair[0] -in @('racer_number','racer_name') -and
                    $null -eq $boat.($pair[0])) { continue }
                $checks += "$($pair[1]) IS $(Sql-Value $boat.($pair[0]))"
            }
            $count = @(Read-Sql $script:db "SELECT COUNT(*) FROM race_entries WHERE $($checks -join ' AND ');")
            if ($count.Count -ne 1 -or $count[0] -ne '1') { throw "DB boat payload conflict: $Date" }
        }
        $payoutCount = 0
        foreach ($type in $race.payouts.PSObject.Properties) {
            foreach ($item in $type.Value) {
                $payoutCount++
                $count = @(Read-Sql $script:db "SELECT COUNT(*) FROM payouts WHERE race_id=$id AND bet_type=$(Sql-Value $type.Name) AND combination=$(Sql-Value $item.combination) AND amount IS $(Sql-Value $item.amount);")
                if ($count.Count -ne 1 -or $count[0] -ne '1') { throw "DB payout payload conflict: $Date" }
            }
        }
        $count = @(Read-Sql $script:db "SELECT COUNT(*) FROM payouts WHERE race_id=$id;")
        if ($count.Count -ne 1 -or [int]$count[0] -ne $payoutCount) { throw "DB payout coverage conflict: $Date" }
    }
}
function Read-UnfinishedCount {
    $rows = @(Read-Sql $script:db "SELECT COUNT(*) FROM races WHERE extra_fetch_status IS NULL OR extra_fetch_status <> 'success';")
    if ($rows.Count -ne 1 -or $rows[0] -notmatch '^\d+$') { throw 'Invalid unfinished count.' }
    [long]$rows[0]
}
try {
    # Never delete the lock file: another process could otherwise lock a different
    # file object. FileShare.None is exclusive and releases on process termination.
    # This lock coordinates this script's instances, not unrelated DB writers.
    $lock = [IO.File]::Open((Join-Path $root 'daily_boatrace.lock'), [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    $runId = '{0}_{1}_{2}' -f [DateTime]::UtcNow.ToString('yyyyMMdd_HHmmss_fffffff'), $PID, [guid]::NewGuid().ToString('N')
    $logDir = Join-Path $root 'logs'
    [IO.Directory]::CreateDirectory($logDir) | Out-Null
    $logPath = Join-Path $logDir "daily_boatrace_$runId.log"
    $stream = [IO.File]::Open($logPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::Read)
    try { $logWriter = New-Object IO.StreamWriter($stream, (New-Object Text.UTF8Encoding($false))) }
    catch { $stream.Dispose(); throw }
    $logWriter.AutoFlush = $true
    Write-Log "Coordinator started; log=$logPath"
    foreach ($path in @($php,$sqlite,$db,$scraper,$importer,$enrichment,$verifier)) {
        if (!(Test-Path -LiteralPath $path -PathType Leaf)) { throw "Required file missing: $path" }
    }
    $importDb = [IO.Path]::GetFullPath((Join-Path $env:USERPROFILE 'Desktop\BoatraceData\boatrace_master.sqlite'))
    if ($importDb -ine $db) { throw "Importer database path mismatch: $importDb" }
    Push-Location -LiteralPath $root
    $pushed = $true
    try { $tokyo = [TimeZoneInfo]::FindSystemTimeZoneById('Asia/Tokyo') }
    catch { $tokyo = [TimeZoneInfo]::FindSystemTimeZoneById('Tokyo Standard Time') }
    $yesterday = [TimeZoneInfo]::ConvertTimeFromUtc([DateTime]::UtcNow, $tokyo).Date.AddDays(-1)
    # Explicit policy: 2024-01-01..2026-10-08 is trusted legacy.
    # Never derive audit coverage from MAX(race_date), JSON presence, or DB keys.
    [IO.Directory]::CreateDirectory($stateDir) | Out-Null
    New-ValidatedBackup 'pre_update'
    Write-Log "Trusted legacy through 2026-10-08; strict audit 2026-10-09 through $($yesterday.ToString('yyyy-MM-dd'))"
    for ($day = [DateTime]::ParseExact('2026-10-09','yyyy-MM-dd',[Globalization.CultureInfo]::InvariantCulture); $day -le $yesterday; $day = $day.AddDays(1)) {
        $date = $day.ToString('yyyy-MM-dd')
        $path = Join-Path $root ('docs\v3\{0}\{1}.json' -f $day.ToString('yyyy'), $day.ToString('yyyyMMdd'))
        $statePath = Join-Path $stateDir "$date.json"
        $cached = $null
        if (Test-Path -LiteralPath $statePath -PathType Leaf) {
            try {
                $candidate = ConvertFrom-Json ([IO.File]::ReadAllText($statePath))
                Require-Fields $candidate @('version','date','status','json_sha256','keys','cancelled_keys','evidence') 'Saved evidence'
                if ($candidate.version -eq 2 -and $candidate.date -ceq $date -and
                    $candidate.status -in @('verified','verified_no_races') -and
                    $candidate.json_sha256 -ceq (Get-JsonHash $path) -and
                    @($candidate.evidence).Count -gt 0) {
                    $validEvidence = $true
                    foreach ($item in $candidate.evidence) {
                        if ($item.url -notmatch '^https://www\.boatrace\.jp/owpc/pc/race/(index|resultlist|raceresult)\?' -or
                            $item.sha256 -notmatch '^[a-f0-9]{64}$') { $validEvidence = $false }
                    }
                    if ($candidate.status -eq 'verified' -and @($candidate.keys).Count -eq 0) { $validEvidence = $false }
                    if ($candidate.status -eq 'verified_no_races' -and @($candidate.keys).Count -ne 0) { $validEvidence = $false }
                    if ($validEvidence) { $cached = $candidate }
                }
            } catch { Write-Log "Saved evidence unusable; re-auditing official source: $date" }
        }
        # Persist a pending marker before attempting verification; failures cannot
        # leave a stale success marker. Every calendar date is revisited each run.
        Save-DateState $date @{version=2; date=$date; status='pending'; run_id=$runId}
        try {
            # Inspect the official source before scraping missing local data.
            # No-race dates therefore do not depend on a scraper producing a file.
            try {
                if ($null -ne $cached) {
                    $state = $cached
                    Write-Log "Reusing hash-bound official evidence; auditing local/DB content: $date"
                } else { $state = Read-Official $date $path }
            }
            catch {
                if (Test-Path -LiteralPath $path) { throw }
                Write-Log "Missing JSON; attempting existing scraper: $date"
                Invoke-Native $php @($scraper,'v3',$date)
                $state = Read-Official $date $path
            }
            $expected = @($state.keys)
            if ($expected.Count -gt 0) {
                $validated = @(Read-ValidatedKeys $path $date)
                if ($validated.Count -ne $expected.Count -or @(Compare-Object $expected $validated).Count -ne 0) { throw "Local/source conflict: $date" }
            }
            $beforeHash = Get-JsonHash $path
            if ($beforeHash -cne $state.json_sha256) { throw "JSON changed after source verification: $date" }
            $actual = @(Read-Sql $db "SELECT race_date || '|' || stadium_number || '|' || race_number FROM races WHERE race_date='$date';")
            foreach ($key in $actual) {
                if ($key -notin $expected) { throw "DB has race absent from official audited results: $key" }
            }
        } catch {
            $reason = $_.Exception.Message
            $unresolved.Add("$date : $reason")
            Save-DateState $date @{version=2; date=$date; status='unresolved_or_conflict'; run_id=$runId; reason=$reason}
            Write-Log "UNRESOLVED/CONFLICT: $date : $reason"
            if ($reason -match '^SQLite (query failed|diagnostic):?') { throw }
            continue
        }
        # Integrity/backup/write failures are fatal, never caught as a date gap.
        Assert-Database $db
        $state.status = 'source_verified'
        Save-DateState $date $state
        try {
            # The importer uses INSERT OR IGNORE for payouts. It cannot repair
            # existing conflicts: audit every existing race before any reimport.
            # Only absent races are eligible for automatic import.
            Assert-ImportedContent $date $path $expected $true @($state.cancelled_keys)
            if ($actual.Count -lt $expected.Count) {
                Invoke-Native $php @($importer,$date,$date)
            }
            Assert-Database $db
            if ((Get-JsonHash $path) -cne $beforeHash) { throw "JSON changed during import: $date" }
            Assert-ImportedContent $date $path $expected $false @($state.cancelled_keys)
            if ((Get-JsonHash $path) -cne $beforeHash) { throw "JSON changed during DB content audit: $date" }
            $state.status = 'source_verified'
            Save-DateState $date $state
            $auditedDates.Add(@{ Date=$date; Path=$path; Hash=$beforeHash; Keys=$expected; State=$state })
            Write-Log "Content audited; awaiting enrichment/final audit: $date"
        } catch {
            $unresolved.Add("$date : $($_.Exception.Message)")
            Save-DateState $date @{version=2; date=$date; status='unresolved_or_conflict'; run_id=$runId; reason=$_.Exception.Message}
            throw
        }
    }
    if ($unresolved.Count -gt 0) { throw "Unresolved/conflict dates: $($unresolved -join '; ')" }
    $env:BR_FETCH_WORKERS = '10'
    $env:BR_BATCH_LIMIT = '100'
    $remaining = Read-UnfinishedCount
    while ($remaining -gt 0) {
        Write-Log "Unfinished enrichment: $remaining"
        Assert-Database $db
        Invoke-Native $php @($enrichment)
        Assert-Database $db
        $next = Read-UnfinishedCount
        if ($next -ge $remaining) { throw "Enrichment made no progress: before=$remaining after=$next" }
        $remaining = $next
    }
    Write-Log 'Unfinished enrichment: 0'
    Assert-Database $db
    # Enrichment writes result fields, entries and payouts. Recheck every date
    # after those writes, before publishing success or taking the success backup.
    foreach ($audit in $auditedDates) {
        try {
            if ((Get-JsonHash $audit.Path) -cne $audit.Hash) { throw "JSON changed during enrichment: $($audit.Date)" }
            Assert-ImportedContent $audit.Date $audit.Path $audit.Keys $false @($audit.State.cancelled_keys)
            if ((Get-JsonHash $audit.Path) -cne $audit.Hash) { throw "JSON changed during final audit: $($audit.Date)" }
        } catch {
            $reason = $_.Exception.Message
            $unresolved.Add("$($audit.Date) : $reason")
            Save-DateState $audit.Date @{version=2; date=$audit.Date; status='unresolved_or_conflict'; run_id=$runId; reason=$reason}
        }
    }
    if ($unresolved.Count -gt 0) { throw "Post-enrichment conflicts: $($unresolved -join '; ')" }
    foreach ($audit in $auditedDates) {
        $audit.State.status = if ($audit.Keys.Count -eq 0) { 'verified_no_races' } else { 'verified' }
        Save-DateState $audit.Date $audit.State
        Write-Log "Verified date after enrichment: $($audit.Date)"
    }
    New-ValidatedBackup 'post_success'

    Write-Log 'Creating latest Excel workbook'
    $excelFinal = Join-Path $env:USERPROFILE 'Desktop\boat_escape_wind_class_stabilizer_latest.xlsx'
    $excelTemp = Join-Path $env:USERPROFILE 'Desktop\boat_escape_wind_class_stabilizer_latest.tmp.xlsx'

    try {
        $env:BR_EXCEL_OUTPUT = $excelTemp
        Invoke-Native 'python' @('analysis_data\make_escape_wind_class_stabilizer_sqlite.py')

        if (-not (Test-Path $excelTemp)) {
            throw 'Excel output file was not created'
        }

        Move-Item -Force $excelTemp $excelFinal
        Write-Log "Latest Excel completed: $excelFinal"
    }
    finally {
        Remove-Item Env:BR_EXCEL_OUTPUT -ErrorAction SilentlyContinue
        if (Test-Path $excelTemp) {
            Remove-Item -Force $excelTemp -ErrorAction SilentlyContinue
        }
    }

    Write-Log 'Coordinator completed; all strict-policy dates verified'
    $exitCode = 0
} catch {
    $failure = $_
    if ($unresolved.Count -gt 0) { Write-Log ('Unresolved/conflict dates: ' + ($unresolved -join '; ')) }
    try { Write-Log "FAILURE: $($failure.Exception.Message)" }
    catch { [Console]::Error.WriteLine("FAILURE: $($failure.Exception.Message); logging failed: $($_.Exception.Message)") }
    $exitCode = 1
} finally {
    # Nested finally blocks ensure cleanup errors cannot prevent lock release.
    try {
        [Environment]::SetEnvironmentVariable('BR_FETCH_WORKERS', $oldWorkers, 'Process')
        [Environment]::SetEnvironmentVariable('BR_BATCH_LIMIT', $oldLimit, 'Process')
        if ($pushed) { Pop-Location }
    } catch { [Console]::Error.WriteLine($_.Exception.Message); $exitCode = 1 }
    finally {
        try { if ($null -ne $logWriter) { $logWriter.Dispose() } }
        catch { [Console]::Error.WriteLine($_.Exception.Message); $exitCode = 1 }
        finally {
            try { if ($null -ne $lock) { $lock.Dispose() } }
            catch { [Console]::Error.WriteLine($_.Exception.Message); $exitCode = 1 }
        }
    }
}
exit $exitCode
