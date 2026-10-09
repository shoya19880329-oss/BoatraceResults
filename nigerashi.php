<?php

$start = '2024-01-01';
$end   = '2026-10-05';

$stats = [];
$files = glob('docs/v3/{2024,2025,2026}/*.json', GLOB_BRACE);

foreach ($files as $file) {
    $date = basename($file, '.json');

    if ($date < str_replace('-', '', $start) ||
        $date > str_replace('-', '', $end)) {
        continue;
    }

    $data = json_decode(file_get_contents($file), true);

    if (!isset($data['results']) || !is_array($data['results'])) {
        continue;
    }

    foreach ($data['results'] as $race) {

        if (!isset($race['boats']) || !is_array($race['boats'])) {
            continue;
        }

        $boat1 = null;
        $boat2 = null;

        foreach ($race['boats'] as $boat) {

            // 1号艇かつ1コース
            if (
                (int)($boat['racer_boat_number'] ?? 0) === 1 &&
                (int)($boat['racer_course_number'] ?? 0) === 1
            ) {
                $boat1 = $boat;
            }

            // 2号艇かつ2コース
            if (
                (int)($boat['racer_boat_number'] ?? 0) === 2 &&
                (int)($boat['racer_course_number'] ?? 0) === 2
            ) {
                $boat2 = $boat;
            }
        }

        // 1号艇=1コース、2号艇=2コースの両方が揃ったレースのみ
        if ($boat1 === null || $boat2 === null) {
            continue;
        }

        $number = (string)($boat2['racer_number'] ?? '');
        $name   = trim((string)($boat2['racer_name'] ?? ''));

        if ($number === '' || $name === '') {
            continue;
        }

        if (!isset($stats[$number])) {
            $stats[$number] = [
                'number' => $number,
                'name' => $name,
                'races' => 0,
                'escaped' => 0
            ];
        }

        $stats[$number]['races']++;

        // 1号艇・1コースが1着なら「逃げられた」
        if ((int)($boat1['racer_place_number'] ?? 0) === 1) {
            $stats[$number]['escaped']++;
        }
    }
}

foreach ($stats as &$s) {
    $s['rate'] = $s['races'] > 0
        ? ($s['escaped'] / $s['races']) * 100
        : 0;
}
unset($s);

// 逃し率が高い順
usort($stats, function ($a, $b) {
    if ($a['rate'] == $b['rate']) {
        return $b['races'] <=> $a['races'];
    }
    return $b['rate'] <=> $a['rate'];
});

// CSV出力
$out = fopen('nigerashi_ranking.csv', 'w');

// Excelで文字化けしないようUTF-8 BOM
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    '順位',
    '登録番号',
    '選手名',
    '2号艇2コース走数',
    '1号艇逃げ回数',
    '逃し率(%)'
]);

$rank = 1;

foreach ($stats as $s) {
    fputcsv($out, [
        $rank++,
        $s['number'],
        $s['name'],
        $s['races'],
        $s['escaped'],
        number_format($s['rate'], 2, '.', '')
    ]);
}

fclose($out);

echo "集計完了\n";
echo "対象期間: {$start} ～ {$end}\n";
echo "選手数: " . count($stats) . "\n";
echo "出力: nigerashi_ranking.csv\n\n";

echo "上位20名\n";
echo "---------------------------------------------\n";

foreach (array_slice($stats, 0, 20) as $i => $s) {
    printf(
        "%2d位  %s  %-12s  %4d走  %4d回  %6.2f%%\n",
        $i + 1,
        $s['number'],
        $s['name'],
        $s['races'],
        $s['escaped'],
        $s['rate']
    );
}
