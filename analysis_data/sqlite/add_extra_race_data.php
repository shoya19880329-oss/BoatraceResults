<?php

declare(strict_types=1);

$dbPath = __DIR__ . '/boatrace.sqlite';

$date = '2026-01-23';
$stadiumNumber = 13;
$raceNumber = 5;

function cleanText(string $text): string
{
    $text = str_replace("\xc2\xa0", ' ', $text);
    return preg_replace('/\s+/u', ' ', trim($text)) ?? '';
}

function loadDom(string $html): array
{
    libxml_use_internal_errors(true);

    $dom = new DOMDocument();
    $dom->loadHTML($html);

    libxml_clear_errors();

    return [$dom, new DOMXPath($dom)];
}

function raceTimeToSeconds(string $time): ?float
{
    $time = trim($time);

    if ($time === '') {
        return null;
    }

    if (preg_match('/(\d+)\'(\d+)"(\d+)/u', $time, $m)) {
        return ((int)$m[1] * 60)
            + (int)$m[2]
            + ((int)$m[3] / 10);
    }

    return null;
}

$beforeUrl =
    'https://www.boatrace.jp/owpc/pc/race/beforeinfo'
    . '?hd=' . str_replace('-', '', $date)
    . '&jcd=' . sprintf('%02d', $stadiumNumber)
    . '&rno=' . $raceNumber;

$resultUrl =
    'https://www.boatrace.jp/owpc/pc/race/raceresult'
    . '?hd=' . str_replace('-', '', $date)
    . '&jcd=' . sprintf('%02d', $stadiumNumber)
    . '&rno=' . $raceNumber;

echo "追加データ取得開始\n";

$beforeHtml = file_get_contents($beforeUrl);
$resultHtml = file_get_contents($resultUrl);

if ($beforeHtml === false || $resultHtml === false) {
    throw new RuntimeException('公式ページの取得に失敗しました。');
}

[, $beforeXpath] = loadDom($beforeHtml);
[, $resultXpath] = loadDom($resultHtml);

/*
 * 安定板
 */
$stabilizerNodes = $beforeXpath->query(
    '//span[contains(concat(" ",normalize-space(@class)," ")," label2 ")'
    . ' and contains(normalize-space(.),"安定板使用")]'
);

$stabilizerUsed =
    ($stabilizerNodes !== false && $stabilizerNodes->length > 0) ? 1 : 0;

/*
 * プロペラ・部品交換
 */
$boatExtras = [];

$boatBodies = $beforeXpath->query(
    '//tbody[.//td[contains(@class,"is-boatColor")]]'
);

foreach ($boatBodies as $tbody) {
    $boatNode = $beforeXpath
        ->query('.//td[contains(@class,"is-boatColor")]', $tbody)
        ->item(0);

    if (!$boatNode) {
        continue;
    }

    $boatNumber = (int)cleanText($boatNode->textContent);

    if ($boatNumber < 1 || $boatNumber > 6) {
        continue;
    }

    $tds = $beforeXpath->query('./tr[1]/td', $tbody);

    if ($tds->length < 8) {
        throw new RuntimeException(
            "{$boatNumber}号艇の直前情報列数が想定外です。"
        );
    }

    $propellerText = cleanText($tds->item(6)->textContent);
    $partsText = cleanText($tds->item(7)->textContent);

    $boatExtras[$boatNumber] = [
        'propeller_changed' =>
            (mb_strpos($propellerText, '新') !== false) ? 1 : 0,
        'propeller_text' => $propellerText,
        'parts_text' => $partsText,
    ];
}

/*
 * レースタイム
 */
$raceTimes = [];

$timeRows = $resultXpath->query(
    '//table[.//th[contains(normalize-space(.),"レースタイム")]]/tbody/tr'
);

foreach ($timeRows as $row) {
    $tds = $resultXpath->query('./td', $row);

    if ($tds->length < 4) {
        continue;
    }

    $boatNumber = (int)cleanText($tds->item(1)->textContent);
    $timeText = cleanText($tds->item(3)->textContent);

    if ($boatNumber >= 1 && $boatNumber <= 6) {
        $raceTimes[$boatNumber] = [
            'text' => $timeText !== '' ? $timeText : null,
            'seconds' => raceTimeToSeconds($timeText),
        ];
    }
}

/*
 * 払戻人気
 */
$payoutPopularity = [];

$payoutRows = $resultXpath->query(
    '//table[.//th[normalize-space(.)="勝式"]'
    . ' and .//th[normalize-space(.)="人気"]]//tbody/tr'
);

$currentBetType = '';

$betTypeMap = [
    '3連単' => 'trifecta',
    '3連複' => 'trio',
    '2連単' => 'exacta',
    '2連複' => 'quinella',
    '拡連複' => 'quinella_place',
    '単勝'   => 'win',
    '複勝'   => 'place',
];

foreach ($payoutRows as $row) {
    $tds = $resultXpath->query('./td', $row);

    if ($tds->length < 3) {
        continue;
    }

    $texts = [];

    foreach ($tds as $td) {
        $texts[] = preg_replace(
            '/\s+/u',
            '',
            trim(str_replace("\xc2\xa0", ' ', $td->textContent))
        ) ?? '';
    }

    if (count($texts) === 4) {
        if ($texts[0] !== '') {
            $currentBetType = $texts[0];
        }

        $combination = $texts[1];
        $amountText = $texts[2];
        $popularityText = $texts[3];
    } else {
        $combination = $texts[0] ?? '';
        $amountText = $texts[1] ?? '';
        $popularityText = $texts[2] ?? '';
    }

    if ($combination === '' || $amountText === '') {
        continue;
    }

    $internalBetType = $betTypeMap[$currentBetType] ?? null;

    if ($internalBetType === null) {
        continue;
    }

    $amount = (int)preg_replace('/[^\d]/u', '', $amountText);

    $key = $internalBetType . '|' . $combination . '|' . $amount;

    $payoutPopularity[$key] =
        $popularityText !== '' ? (int)$popularityText : null;
}

/*
 * 返還
 */
$refundNode = $resultXpath->query(
    '//table[.//th[normalize-space(.)="返還"]]/tbody/tr/td'
)->item(0);

$refundText =
    $refundNode ? cleanText($refundNode->textContent) : '';

/*
 * 備考
 */
$remarksNode = $resultXpath->query(
    '//table[.//th[normalize-space(.)="備考"]]/tbody/tr/td'
)->item(0);

$remarks =
    $remarksNode ? cleanText($remarksNode->textContent) : '';

/*
 * SQLite保存
 */
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$stmt = $pdo->prepare(
    'SELECT race_id
     FROM races
     WHERE race_date = ?
       AND stadium_number = ?
       AND race_number = ?'
);

$stmt->execute([$date, $stadiumNumber, $raceNumber]);

$raceId = $stmt->fetchColumn();

if ($raceId === false) {
    throw new RuntimeException('対象レースがSQLiteにありません。');
}

$raceId = (int)$raceId;

$pdo->beginTransaction();

try {
    /*
     * races更新
     */
    $stmt = $pdo->prepare(
        'UPDATE races
         SET stabilizer_used = ?,
             remarks = ?
         WHERE race_id = ?'
    );

    $stmt->execute([
        $stabilizerUsed,
        $remarks !== '' ? $remarks : null,
        $raceId,
    ]);

    /*
     * race_previews更新
     */
    $stmt = $pdo->prepare(
        'UPDATE race_previews
         SET propeller_changed = ?
         WHERE race_id = ?
           AND boat_number = ?'
    );

    foreach ($boatExtras as $boatNumber => $extra) {
        $stmt->execute([
            $extra['propeller_changed'],
            $raceId,
            $boatNumber,
        ]);
    }

    /*
     * 部品交換は再実行時に重複しないよう削除して再登録
     */
    $stmt = $pdo->prepare(
        'DELETE FROM parts_replacements WHERE race_id = ?'
    );
    $stmt->execute([$raceId]);

    $partInsert = $pdo->prepare(
        'INSERT INTO parts_replacements (
            race_id,
            boat_number,
            part_type,
            quantity
        ) VALUES (?, ?, ?, ?)'
    );

    foreach ($boatExtras as $boatNumber => $extra) {
        $partsText = $extra['parts_text'];

        if ($partsText === '') {
            continue;
        }

        /*
         * 例:
         * ピストン×１
         * リング×２
         *
         * 複数部品にも対応する。
         */
        preg_match_all(
            '/(ピストン|リング|電気|キャブ|シリンダ|シャフト|ギヤ|キャリボ)'
            . '(?:\s*×\s*([0-9０-９]+))?/u',
            $partsText,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $quantity = 1;

            if (isset($match[2]) && $match[2] !== '') {
                $quantityText = mb_convert_kana($match[2], 'n', 'UTF-8');
                $quantity = (int)$quantityText;
            }

            $partInsert->execute([
                $raceId,
                $boatNumber,
                $match[1],
                $quantity,
            ]);
        }
    }

    /*
     * レースタイム更新
     */
    $stmt = $pdo->prepare(
        'UPDATE race_entries
         SET race_time_text = ?,
             race_time_seconds = ?
         WHERE race_id = ?
           AND boat_number = ?'
    );

    foreach ($raceTimes as $boatNumber => $time) {
        $stmt->execute([
            $time['text'],
            $time['seconds'],
            $raceId,
            $boatNumber,
        ]);
    }

    /*
     * 払戻人気更新
     */
    $payouts = $pdo->prepare(
        'SELECT payout_id, bet_type, combination, amount
         FROM payouts
         WHERE race_id = ?'
    );

    $payouts->execute([$raceId]);

    $updatePopularity = $pdo->prepare(
        'UPDATE payouts
         SET popularity = ?
         WHERE payout_id = ?'
    );

    foreach ($payouts as $payout) {
        $key =
            $payout['bet_type']
            . '|'
            . $payout['combination']
            . '|'
            . $payout['amount'];

        if (array_key_exists($key, $payoutPopularity)) {
            $updatePopularity->execute([
                $payoutPopularity[$key],
                $payout['payout_id'],
            ]);
        }
    }

    /*
     * 返還
     */
    $stmt = $pdo->prepare(
        'DELETE FROM refunds WHERE race_id = ?'
    );
    $stmt->execute([$raceId]);

    if ($refundText !== '') {
        $stmt = $pdo->prepare(
            'INSERT INTO refunds (
                race_id,
                boat_number,
                refund_text
            ) VALUES (?, NULL, ?)'
        );

        $stmt->execute([
            $raceId,
            $refundText,
        ]);
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $e;
}

/*
 * 結果表示
 */
echo "追加データ登録完了\n";
echo "race_id: {$raceId}\n";
echo "安定板: " . ($stabilizerUsed ? '有' : '無') . "\n";

foreach ($boatExtras as $boatNumber => $extra) {
    echo "{$boatNumber}号艇";
    echo " | プロペラ=";
    echo $extra['propeller_changed'] ? '交換有' : '交換無';
    echo " | 部品=";
    echo $extra['parts_text'] !== '' ? $extra['parts_text'] : 'なし';

    if (isset($raceTimes[$boatNumber])) {
        echo " | タイム=" . ($raceTimes[$boatNumber]['text'] ?? '');
        echo " | 秒=" . ($raceTimes[$boatNumber]['seconds'] ?? '');
    }

    echo "\n";
}

echo "返還: " . ($refundText !== '' ? $refundText : 'なし') . "\n";
echo "備考: " . ($remarks !== '' ? $remarks : 'なし') . "\n";
