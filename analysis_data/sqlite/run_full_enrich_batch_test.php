<?php
declare(strict_types=1);

$dbPath = __DIR__ . '/detail_test.sqlite';
$fetchSource = __DIR__ . '/fetch_html_cache_test.php';
$enrichSource = __DIR__ . '/enrich_full_detail_test.php';

$batchLimit = (int)(getenv('BR_BATCH_LIMIT') ?: 100);
$fetchWorkers = (int)(getenv('BR_FETCH_WORKERS') ?: 10);

if ($batchLimit < 1) {
    throw new RuntimeException('BR_BATCH_LIMIT は1以上にしてください。');
}
if ($fetchWorkers < 1) {
    throw new RuntimeException('BR_FETCH_WORKERS は1以上にしてください。');
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

        $command =
            'BR_DATE=' . escapeshellarg($date) . ' ' .
            'BR_STADIUM=' . escapeshellarg((string)$stadium) . ' ' .
            'BR_RACE=' . escapeshellarg((string)$race) . ' ' .
            'php ' . escapeshellarg($fetchSource);

        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException(
                "HTML取得プロセス起動失敗: {$date} 場{$stadium} {$race}R"
            );
        }

        $processes[] = [
            'process' => $process,
            'pipes' => $pipes,
            'row' => $row,
        ];
    }

    $fetchFailed = false;

    foreach ($processes as $item) {
        $stdout = stream_get_contents($item['pipes'][1]);
        $stderr = stream_get_contents($item['pipes'][2]);

        fclose($item['pipes'][1]);
        fclose($item['pipes'][2]);

        $exitCode = proc_close($item['process']);

        $row = $item['row'];
        $date = $row['race_date'];
        $stadium = (int)$row['stadium_number'];
        $race = (int)$row['race_number'];

        if ($stdout !== '') {
            echo $stdout;
        }
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }

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

        $command =
            'BR_DATE=' . escapeshellarg($date) . ' ' .
            'BR_STADIUM=' . escapeshellarg((string)$stadium) . ' ' .
            'BR_RACE=' . escapeshellarg((string)$race) . ' ' .
            'php ' . escapeshellarg($enrichSource);

        passthru($command, $exitCode);

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
