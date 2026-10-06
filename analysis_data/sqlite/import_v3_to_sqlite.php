<?php
declare(strict_types=1);

$dbFile = __DIR__ . '/boatrace_master.sqlite';
$schemaFile = __DIR__ . '/schema.sql';
$v3Root = dirname(__DIR__, 2) . '/docs/v3';

$startDate = '2024-01-01';
$endDate   = '2026-10-04';

if (!file_exists($schemaFile)) {
    throw new RuntimeException("schema.sql がありません");
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA synchronous = NORMAL');
$pdo->exec('PRAGMA busy_timeout = 30000');

$tableExists = $pdo->query(
    "SELECT 1 FROM sqlite_master WHERE type='table' AND name='races'"
)->fetchColumn();

if (!$tableExists) {
    $pdo->exec(file_get_contents($schemaFile));
    echo "新規DB: schema.sql を作成しました\n";
} else {
    echo "既存DB: schema.sql の作成をスキップします\n";
}

$raceSelect = $pdo->prepare(
    'SELECT race_id
       FROM races
      WHERE race_date = ?
        AND stadium_number = ?
        AND race_number = ?'
);

$raceInsert = $pdo->prepare(
    'INSERT INTO races (
        race_date,
        stadium_number,
        race_number,
        race_status,
        result_wind_speed,
        result_wind_direction_number,
        result_wave_height,
        result_weather_number,
        result_air_temperature,
        result_water_temperature,
        technique_number,
        result_fetched_at,
        result_fetch_status,
        created_at,
        updated_at
    ) VALUES (
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?,
        ?,
        CURRENT_TIMESTAMP,
        ?,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP
    )'
);

$raceUpdate = $pdo->prepare(
    'UPDATE races SET
        result_wind_speed = ?,
        result_wind_direction_number = ?,
        result_wave_height = ?,
        result_weather_number = ?,
        result_air_temperature = ?,
        result_water_temperature = ?,
        technique_number = ?,
        result_fetched_at = CURRENT_TIMESTAMP,
        result_fetch_status = ?,
        updated_at = CURRENT_TIMESTAMP
     WHERE race_id = ?'
);

$entryUpsert = $pdo->prepare(
    'INSERT INTO race_entries (
        race_id,
        boat_number,
        racer_number,
        racer_name,
        actual_course_number,
        actual_start_timing,
        place_number
    ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT(race_id, boat_number) DO UPDATE SET
        racer_number = excluded.racer_number,
        racer_name = excluded.racer_name,
        actual_course_number = excluded.actual_course_number,
        actual_start_timing = excluded.actual_start_timing,
        place_number = excluded.place_number'
);

$racerUpsert = $pdo->prepare(
    'INSERT INTO racers (
        racer_number,
        racer_name,
        first_seen_date,
        last_seen_date,
        created_at,
        updated_at
    ) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ON CONFLICT(racer_number) DO UPDATE SET
        racer_name = CASE
            WHEN excluded.racer_name IS NOT NULL
             AND excluded.racer_name <> ""
            THEN excluded.racer_name
            ELSE racers.racer_name
        END,
        first_seen_date = CASE
            WHEN racers.first_seen_date IS NULL
              OR excluded.first_seen_date < racers.first_seen_date
            THEN excluded.first_seen_date
            ELSE racers.first_seen_date
        END,
        last_seen_date = CASE
            WHEN racers.last_seen_date IS NULL
              OR excluded.last_seen_date > racers.last_seen_date
            THEN excluded.last_seen_date
            ELSE racers.last_seen_date
        END,
        updated_at = CURRENT_TIMESTAMP'
);

$payoutInsert = $pdo->prepare(
    'INSERT OR IGNORE INTO payouts (
        race_id,
        bet_type,
        combination,
        amount,
        popularity
    ) VALUES (?, ?, ?, ?, NULL)'
);

$files = [];

foreach (['2024', '2025', '2026'] as $year) {
    foreach (glob($v3Root . '/' . $year . '/*.json') ?: [] as $file) {
        $ymd = basename($file, '.json');

        if (!preg_match('/^\d{8}$/', $ymd)) {
            continue;
        }

        $date = substr($ymd, 0, 4) . '-' .
                substr($ymd, 4, 2) . '-' .
                substr($ymd, 6, 2);

        if ($date < $startDate || $date > $endDate) {
            continue;
        }

        $files[$date] = $file;
    }
}

ksort($files);

echo "========================================\n";
echo "v3 → SQLite 本番投入\n";
echo "期間: {$startDate} ～ {$endDate}\n";
echo "対象日数: " . count($files) . "\n";
echo "DB: {$dbFile}\n";
echo "========================================\n";

$totalRaces = 0;
$totalEntries = 0;
$totalPayouts = 0;
$totalErrors = 0;
$dayNo = 0;

foreach ($files as $date => $file) {
    $dayNo++;

    try {
        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new RuntimeException("ファイル読込失敗");
        }

        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $races = $json['results'] ?? [];

        if (!is_array($races)) {
            throw new RuntimeException("results が配列ではありません");
        }

        $pdo->beginTransaction();

        $dayRaces = 0;
        $dayEntries = 0;
        $dayPayouts = 0;

        foreach ($races as $race) {
            $raceDate = (string)($race['date'] ?? $date);
            $stadium = (int)($race['stadium_number'] ?? 0);
            $raceNumber = (int)($race['number'] ?? 0);

            if ($stadium < 1 || $stadium > 24) {
                throw new RuntimeException(
                    "{$raceDate} 不正な場番号: {$stadium}"
                );
            }

            if ($raceNumber < 1 || $raceNumber > 12) {
                throw new RuntimeException(
                    "{$raceDate} 場{$stadium} 不正なR: {$raceNumber}"
                );
            }

            $raceSelect->execute([
                $raceDate,
                $stadium,
                $raceNumber
            ]);

            $raceId = $raceSelect->fetchColumn();

            $resultValues = [
                $race['wind_speed'] ?? null,
                $race['wind_direction_number'] ?? null,
                $race['wave_height'] ?? null,
                $race['weather_number'] ?? null,
                $race['air_temperature'] ?? null,
                $race['water_temperature'] ?? null,
                $race['technique_number'] ?? null
            ];

            if ($raceId === false) {
                $raceInsert->execute([
                    $raceDate,
                    $stadium,
                    $raceNumber,
                    'result_available',
                    ...$resultValues,
                    'OK'
                ]);

                $raceId = (int)$pdo->lastInsertId();
            } else {
                $raceId = (int)$raceId;

                $raceUpdate->execute([
                    ...$resultValues,
                    'OK',
                    $raceId
                ]);
            }

            $boats = $race['boats'] ?? [];

            if (!is_array($boats)) {
                throw new RuntimeException(
                    "{$raceDate} 場{$stadium} {$raceNumber}R boats不正"
                );
            }

            foreach ($boats as $boat) {
                $boatNumber = (int)($boat['racer_boat_number'] ?? 0);

                if ($boatNumber < 1 || $boatNumber > 6) {
                    throw new RuntimeException(
                        "{$raceDate} 場{$stadium} {$raceNumber}R 艇番不正"
                    );
                }

                $racerNumber =
                    isset($boat['racer_number']) &&
                    is_numeric($boat['racer_number'])
                        ? (int)$boat['racer_number']
                        : null;

                $racerName =
                    isset($boat['racer_name'])
                        ? trim((string)$boat['racer_name'])
                        : null;

                $course =
                    isset($boat['racer_course_number']) &&
                    is_numeric($boat['racer_course_number'])
                        ? (int)$boat['racer_course_number']
                        : null;

                $startTiming =
                    isset($boat['racer_start_timing']) &&
                    is_numeric($boat['racer_start_timing'])
                        ? (float)$boat['racer_start_timing']
                        : null;

                $place =
                    isset($boat['racer_place_number']) &&
                    is_numeric($boat['racer_place_number'])
                        ? (int)$boat['racer_place_number']
                        : null;

                $entryUpsert->execute([
                    $raceId,
                    $boatNumber,
                    $racerNumber,
                    $racerName,
                    $course,
                    $startTiming,
                    $place
                ]);

                $dayEntries++;

                if ($racerNumber !== null && $racerNumber > 0) {
                    $racerUpsert->execute([
                        $racerNumber,
                        $racerName,
                        $raceDate,
                        $raceDate
                    ]);
                }
            }


            $payouts = $race['payouts'] ?? [];

            if (is_array($payouts)) {
                foreach ($payouts as $betType => $items) {
                    if (!is_array($items)) {
                        continue;
                    }

                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $combination =
                            isset($item['combination'])
                                ? trim((string)$item['combination'])
                                : null;

                        $amount =
                            isset($item['amount']) &&
                            is_numeric($item['amount'])
                                ? (int)$item['amount']
                                : null;

                        if ($combination === null || $combination === '') {
                            continue;
                        }

                        $payoutInsert->execute([
                            $raceId,
                            (string)$betType,
                            $combination,
                            $amount
                        ]);

                        $dayPayouts++;
                    }
                }
            }

            $dayRaces++;
        }

        $pdo->commit();

        $totalRaces += $dayRaces;
        $totalEntries += $dayEntries;
        $totalPayouts += $dayPayouts;

        echo sprintf(
            "[%d/%d] %s | %dR | %d艇 | 払戻%d\n",
            $dayNo,
            count($files),
            $date,
            $dayRaces,
            $dayEntries,
            $dayPayouts
        );

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $totalErrors++;

        echo "ERROR {$date}: " . $e->getMessage() . "\n";
        break;
    }
}

echo "\n========================================\n";
echo "処理終了\n";
echo "レース: {$totalRaces}\n";
echo "艇データ: {$totalEntries}\n";
echo "払戻: {$totalPayouts}\n";
echo "エラー日数: {$totalErrors}\n";
echo "========================================\n";
