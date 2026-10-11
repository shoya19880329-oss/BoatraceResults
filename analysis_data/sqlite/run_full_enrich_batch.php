<?php
declare(strict_types=1);

$dbPath = 'C:/Users/shoya/Desktop/BoatraceData/boatrace_master.sqlite';
$fetchSource = __DIR__ . '/fetch_html_cache_test.php';
$enrichSource = __DIR__ . '/enrich_full_detail.php';

// File-backed output avoids pipe deadlocks on native Windows, including
// while other fetch workers are waiting to be collected.
function startRaceProcess(string $source, array $row): array
{
    $environment = getenv();
    if ($environment === false) {
        throw new RuntimeException('Cannot read the inherited environment.');
    }
    $environment['BR_DATE'] = (string)$row['race_date'];
    $environment['BR_STADIUM'] = (string)(int)$row['stadium_number'];
    $environment['BR_RACE'] = (string)(int)$row['race_number'];

    $stdout = tmpfile();
    $stderr = tmpfile();
    $process = null;
    try {
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Cannot create child output files.');
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $source],
            [
                0 => ['file', 'NUL', 'r'],
                1 => $stdout,
                2 => $stderr,
            ],
            $pipes,
            __DIR__,
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start child: ' . $source);
        }
        return compact('process', 'stdout', 'stderr', 'row');
    } finally {
        if (!is_resource($process)) {
            if (is_resource($stdout)) {
                fclose($stdout);
            }
            if (is_resource($stderr)) {
                fclose($stderr);
            }
        }
    }
}

function finishRaceProcess(array $item): int
{
    try {
        $exitCode = proc_close($item['process']);
        rewind($item['stdout']);
        rewind($item['stderr']);
        stream_copy_to_stream($item['stdout'], STDOUT);
        stream_copy_to_stream($item['stderr'], STDERR);
        return $exitCode;
    } finally {
        fclose($item['stdout']);
        fclose($item['stderr']);
    }
}

$batchLimit = (int)(getenv('BR_BATCH_LIMIT') ?: 100);
$fetchWorkers = (int)(getenv('BR_FETCH_WORKERS') ?: 10);

if ($batchLimit < 1) {
    throw new RuntimeException('BR_BATCH_LIMIT は1以上にしてください。');
}
if ($fetchWorkers < 1) {
    throw new RuntimeException('BR_FETCH_WORKERS は1以上にしてください。');
}

if (!is_file($dbPath)) {
    throw new RuntimeException('Database file does not exist: ' . $dbPath);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdo->prepare("
    SELECT race_date, stadium_number, race_number
    FROM races
    WHERE extra_fetch_status IS NULL
       OR extra_fetch_status <> 'success'
    ORDER BY race_date, stadium_number, race_number
    LIMIT :limit
");
$stmt->bindValue(':limit', $batchLimit, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "未完了レースはありません。\n";
    exit(0);
}

$total = count($rows);
$completed = 0;
$startedAt = microtime(true);

echo "========================================\n";
echo "統合補完バッチ開始\n";
echo "対象: {$total}レース\n";
echo "HTML並列レース数: {$fetchWorkers}\n";
echo "========================================\n";

foreach (array_chunk($rows, $fetchWorkers) as $chunkIndex => $chunk) {
    $chunkNo = $chunkIndex + 1;
    $chunkCount = count($chunk);

    echo "\n";
    echo "===== HTML取得 第{$chunkNo}セット ({$chunkCount}レース) =====\n";

    $processes = [];

    foreach ($chunk as $row) {
        $date = $row['race_date'];
        $stadium = (int)$row['stadium_number'];
        $race = (int)$row['race_number'];

        try {
            $processes[] = startRaceProcess($fetchSource, $row);
        } catch (Throwable $e) {
            // Reap workers already started and close their temporary files.
            foreach ($processes as $item) {
                finishRaceProcess($item);
            }
            throw $e;
        }
    }

    $fetchFailed = false;

    foreach ($processes as $item) {
        $exitCode = finishRaceProcess($item);

        $row = $item['row'];
        $date = $row['race_date'];
        $stadium = (int)$row['stadium_number'];
        $race = (int)$row['race_number'];

        if ($exitCode !== 0) {
            echo "HTML取得ERROR: {$date} 場{$stadium} {$race}R\n";
            $fetchFailed = true;
        }
    }

    if ($fetchFailed) {
        echo "HTML取得失敗があるため、このセットのDB登録を中止します。\n";
        exit(1);
    }

    echo "----- SQLite直列登録 第{$chunkNo}セット -----\n";

    foreach ($chunk as $row) {
        $date = $row['race_date'];
        $stadium = (int)$row['stadium_number'];
        $race = (int)$row['race_number'];

        // Wait for each enrichment child before starting the next DB writer.
        $exitCode = finishRaceProcess(startRaceProcess($enrichSource, $row));

        if ($exitCode !== 0) {
            echo "DB登録ERROR: {$date} 場{$stadium} {$race}R\n";
            exit($exitCode);
        }

        $completed++;

        $elapsed = microtime(true) - $startedAt;
        $speed = $elapsed > 0 ? $completed / $elapsed : 0;
        $remaining = $total - $completed;
        $eta = $speed > 0 ? $remaining / $speed : 0;

        echo sprintf(
            "進捗: %d/%d  %.2fレース/秒  残り約%.1f分\n",
            $completed,
            $total,
            $speed,
            $eta / 60
        );
    }
}

$elapsed = microtime(true) - $startedAt;

echo "\n";
echo "========================================\n";
echo "統合補完バッチ完了\n";
echo "完了: {$completed}/{$total}レース\n";
echo sprintf("所要時間: %.1f秒\n", $elapsed);
echo "========================================\n";
