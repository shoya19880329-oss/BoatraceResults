<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use BVP\Scraper\Scraper;

$dbPath = __DIR__ . '/final_test.sqlite';

$date = '2026-01-23';
$stadiumNumber = 13;
$raceNumber = 5;

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

echo "取得開始: {$date} 尼崎 {$raceNumber}R\n";

$programs = Scraper::scrapePrograms($date, $stadiumNumber, $raceNumber);
$previews = Scraper::scrapePreviews($date, $stadiumNumber, $raceNumber);
$results  = Scraper::scrapeResults($date, $stadiumNumber, $raceNumber);

$program = $programs[$stadiumNumber][$raceNumber] ?? null;
$preview = $previews[$stadiumNumber][$raceNumber] ?? null;
$result  = $results[$stadiumNumber][$raceNumber] ?? null;

if (!$program || !$preview || !$result) {
    throw new RuntimeException('Program / Preview / Result の取得に失敗しました。');
}

$pdo->beginTransaction();

try {
    /*
     * 同じテストを再実行しても重複しないよう、
     * 既存レースがあれば削除して入れ直す。
     */
    $stmt = $pdo->prepare(
        'SELECT race_id
         FROM races
         WHERE race_date = ?
           AND stadium_number = ?
           AND race_number = ?'
    );
    $stmt->execute([$date, $stadiumNumber, $raceNumber]);

    $existingRaceId = $stmt->fetchColumn();

    if ($existingRaceId !== false) {
        $stmt = $pdo->prepare('DELETE FROM races WHERE race_id = ?');
        $stmt->execute([(int)$existingRaceId]);
    }

    /*
     * races
     */
    $stmt = $pdo->prepare(
        'INSERT INTO races (
            race_date,
            stadium_number,
            race_number,
            closed_at,
            day_label,
            grade_label,
            grade_number,
            title,
            subtitle,
            distance,

            result_wind_speed,
            result_wind_direction_number,
            result_wave_height,
            result_weather_number,
            result_air_temperature,
            result_water_temperature,

            preview_wind_speed,
            preview_wind_direction_number,
            preview_wave_height,
            preview_weather_number,
            preview_air_temperature,
            preview_water_temperature,

            technique_number
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?
        )'
    );

    $stmt->execute([
        $program['date'],
        $program['stadium_number'],
        $program['number'],
        $program['closed_at'] ?? null,
        $program['day_label'] ?? null,
        $program['grade_label'] ?? null,
        $program['grade_number'] ?? null,
        $program['title'] ?? null,
        $program['subtitle'] ?? null,
        $program['distance'] ?? null,

        $result['wind_speed'] ?? null,
        $result['wind_direction_number'] ?? null,
        $result['wave_height'] ?? null,
        $result['weather_number'] ?? null,
        $result['air_temperature'] ?? null,
        $result['water_temperature'] ?? null,

        $preview['wind_speed'] ?? null,
        $preview['wind_direction_number'] ?? null,
        $preview['wave_height'] ?? null,
        $preview['weather_number'] ?? null,
        $preview['air_temperature'] ?? null,
        $preview['water_temperature'] ?? null,

        $result['technique_number'] ?? null,
    ]);

    $raceId = (int)$pdo->lastInsertId();

    /*
     * race_entries
     */
    $entryStmt = $pdo->prepare(
        'INSERT INTO race_entries (
            race_id,
            boat_number,
            racer_number,
            racer_name,
            racer_class_number,
            racer_branch_number,
            racer_birthplace_number,
            racer_age,
            program_weight,
            flying_count,
            late_count,
            average_start_timing,
            national_win_rate,
            national_top_2_percent,
            national_top_3_percent,
            local_win_rate,
            local_top_2_percent,
            local_top_3_percent,
            motor_number,
            motor_top_2_percent,
            motor_top_3_percent,
            assigned_boat_number,
            assigned_boat_top_2_percent,
            assigned_boat_top_3_percent,
            actual_course_number,
            actual_start_timing,
            place_number
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )'
    );

    /*
     * race_previews
     */
    $previewStmt = $pdo->prepare(
        'INSERT INTO race_previews (
            race_id,
            boat_number,
            exhibition_course_number,
            exhibition_start_timing,
            weight,
            weight_adjustment,
            exhibition_time,
            tilt_adjustment
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    /*
     * racers
     */
    $racerStmt = $pdo->prepare(
        'INSERT INTO racers (
            racer_number,
            racer_name,
            first_seen_date,
            last_seen_date
        ) VALUES (?, ?, ?, ?)
        ON CONFLICT(racer_number) DO UPDATE SET
            racer_name = excluded.racer_name,
            first_seen_date =
                CASE
                    WHEN racers.first_seen_date IS NULL
                      OR excluded.first_seen_date < racers.first_seen_date
                    THEN excluded.first_seen_date
                    ELSE racers.first_seen_date
                END,
            last_seen_date =
                CASE
                    WHEN racers.last_seen_date IS NULL
                      OR excluded.last_seen_date > racers.last_seen_date
                    THEN excluded.last_seen_date
                    ELSE racers.last_seen_date
                END'
    );

    for ($boatNumber = 1; $boatNumber <= 6; $boatNumber++) {
        $p = $program['boats'][$boatNumber] ?? [];
        $v = $preview['boats'][$boatNumber] ?? [];
        $r = $result['boats'][$boatNumber] ?? [];

        $entryStmt->execute([
            $raceId,
            $boatNumber,

            $p['racer_number'] ?? $r['racer_number'] ?? null,
            $p['racer_name'] ?? $r['racer_name'] ?? null,
            $p['racer_class_number'] ?? null,
            $p['racer_branch_number'] ?? null,
            $p['racer_birthplace_number'] ?? null,
            $p['racer_age'] ?? null,
            $p['racer_weight'] ?? null,
            $p['racer_flying_count'] ?? null,
            $p['racer_late_count'] ?? null,
            $p['racer_average_start_timing'] ?? null,

            $p['racer_national_top_1_percent'] ?? null,
            $p['racer_national_top_2_percent'] ?? null,
            $p['racer_national_top_3_percent'] ?? null,

            $p['racer_local_top_1_percent'] ?? null,
            $p['racer_local_top_2_percent'] ?? null,
            $p['racer_local_top_3_percent'] ?? null,

            $p['racer_assigned_motor_number'] ?? null,
            $p['racer_assigned_motor_top_2_percent'] ?? null,
            $p['racer_assigned_motor_top_3_percent'] ?? null,

            $p['racer_assigned_boat_number'] ?? null,
            $p['racer_assigned_boat_top_2_percent'] ?? null,
            $p['racer_assigned_boat_top_3_percent'] ?? null,

            $r['racer_course_number'] ?? null,
            $r['racer_start_timing'] ?? null,
            $r['racer_place_number'] ?? null,
        ]);

        $previewStmt->execute([
            $raceId,
            $boatNumber,
            $v['racer_course_number'] ?? null,
            $v['racer_start_timing'] ?? null,
            $v['racer_weight'] ?? null,
            $v['racer_weight_adjustment'] ?? null,
            $v['racer_exhibition_time'] ?? null,
            $v['racer_tilt_adjustment'] ?? null,
        ]);

        $racerNumber = $p['racer_number'] ?? $r['racer_number'] ?? null;
        $racerName = $p['racer_name'] ?? $r['racer_name'] ?? null;

        if ($racerNumber !== null) {
            $racerStmt->execute([
                $racerNumber,
                $racerName,
                $date,
                $date,
            ]);
        }
    }

    /*
     * payouts
     */
    $payoutStmt = $pdo->prepare(
        'INSERT INTO payouts (
            race_id,
            bet_type,
            combination,
            amount
        ) VALUES (?, ?, ?, ?)'
    );

    foreach (($result['payouts'] ?? []) as $betType => $items) {
        foreach ($items as $item) {
            $payoutStmt->execute([
                $raceId,
                $betType,
                $item['combination'] ?? null,
                $item['amount'] ?? null,
            ]);
        }
    }

    $pdo->commit();

    /*
     * 登録結果の検証
     */
    $raceCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM races'
    )->fetchColumn();

    $entryCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM race_entries'
    )->fetchColumn();

    $previewCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM race_previews'
    )->fetchColumn();

    $payoutCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM payouts'
    )->fetchColumn();

    $racerCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM racers'
    )->fetchColumn();

    echo "登録完了\n";
    echo "race_id: {$raceId}\n";
    echo "races: {$raceCount}\n";
    echo "race_entries: {$entryCount}\n";
    echo "race_previews: {$previewCount}\n";
    echo "payouts: {$payoutCount}\n";
    echo "racers: {$racerCount}\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $e;
}
