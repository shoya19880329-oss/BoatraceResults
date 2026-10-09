<?php
declare(strict_types=1);

$dbPath = __DIR__ . '/detail_test.sqlite';
$source = __DIR__ . '/add_extra_detail_test.php';

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = $pdo->query("
    SELECT race_date, stadium_number, race_number
    FROM races
    ORDER BY race_date, stadium_number, race_number
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    throw new RuntimeException('対象レースがありません。');
}

foreach ($rows as $i => $row) {
    $date = $row['race_date'];
    $stadium = (int)$row['stadium_number'];
    $race = (int)$row['race_number'];

    echo "===== [" . ($i + 1) . "/100] "
       . $date . " 場" . $stadium . " "
       . $race . "R =====\n";

    $command =
        'BR_DATE=' . escapeshellarg($date) . ' ' .
        'BR_STADIUM=' . escapeshellarg((string)$stadium) . ' ' .
        'BR_RACE=' . escapeshellarg((string)$race) . ' ' .
        'php ' . escapeshellarg($source);

    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        echo "ERROR: {$date} 場{$stadium} {$race}R\n";
        exit($exitCode);
    }
}

echo "========================================\n";
echo "100レース追加データテスト完了\n";
echo "========================================\n";
