<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/ProgramCacheParser_test.php';
require __DIR__ . '/PreviewCacheParser_test.php';
require __DIR__ . '/ResultCacheParser_test.php';

use BoatRace\Cache\ProgramCacheParser;
use BoatRace\Cache\PreviewCacheParser;
use BoatRace\Cache\ResultCacheParser;

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

function fetchGzipCachedHtml(string $url, string $cacheFile): string
{
    if (is_file($cacheFile)) {
        $compressed = file_get_contents($cacheFile);

        if ($compressed === false) {
            throw new RuntimeException(
                'gzipキャッシュを読み込めません: ' . $cacheFile
            );
        }

        $html = gzdecode($compressed);

        if ($html === false) {
            throw new RuntimeException(
                'gzipキャッシュが壊れています: ' . $cacheFile
            );
        }

        return $html;
    }

    $maxRetries = 5;
    $html = false;
    $lastStatus = 0;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' =>
                    "User-Agent: Mozilla/5.0\r\n" .
                    "Accept: text/html,application/xhtml+xml\r\n" .
                    "Connection: close\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $http_response_header = [];
        $body = @file_get_contents($url, false, $context);

        $status = 0;

        if (!empty($http_response_header[0])
            && preg_match(
                '#HTTP/\S+\s+(\d{3})#',
                $http_response_header[0],
                $m
            )
        ) {
            $status = (int)$m[1];
        }

        $lastStatus = $status;

        if ($body !== false
            && $status >= 200
            && $status < 300
            && $body !== ''
        ) {
            $html = $body;
            break;
        }

        if ($attempt < $maxRetries) {
            if ($status === 429) {
                $wait = 30 * $attempt;
            } elseif ($status >= 500 || $status === 0) {
                $wait = 5 * $attempt;
            } else {
                $wait = 3 * $attempt;
            }

            fwrite(
                STDERR,
                "取得再試行 {$attempt}/{$maxRetries}"
                . " HTTP {$status} {$wait}秒待機\n"
            );

            sleep($wait);
        }
    }

    if ($html === false) {
        throw new RuntimeException(
            '公式ページの取得に失敗しました'
            . ' HTTP ' . $lastStatus
            . ': ' . $url
        );
    }

    $compressed = gzencode($html, 6);

    if ($compressed === false) {
        throw new RuntimeException(
            'HTMLのgzip圧縮に失敗しました: ' . $cacheFile
        );
    }

    $cacheDir = dirname($cacheFile);

    if (!is_dir($cacheDir)
        && !mkdir($cacheDir, 0775, true)
        && !is_dir($cacheDir)
    ) {
        throw new RuntimeException(
            'キャッシュディレクトリを作成できません: ' . $cacheDir
        );
    }

    $tmpFile = $cacheFile . '.tmp.' . getmypid();

    if (file_put_contents($tmpFile, $compressed, LOCK_EX) === false) {
        throw new RuntimeException(
            '一時gzipキャッシュの保存に失敗しました: ' . $tmpFile
        );
    }

    if (!rename($tmpFile, $cacheFile)) {
        @unlink($tmpFile);

        throw new RuntimeException(
            'gzipキャッシュの確定に失敗しました: ' . $cacheFile
        );
    }

    return $html;
}


function fetchGzipCachedHtmlMulti(array $requests): array
{
    $results = [];
    $pending = [];

    /*
     * まず既存キャッシュを読む。
     * キャッシュ済みページは通信しない。
     */
    foreach ($requests as $name => $request) {
        $url = $request['url'];
        $cacheFile = $request['cache'];

        if (is_file($cacheFile)) {
            $compressed = file_get_contents($cacheFile);

            if ($compressed === false) {
                throw new RuntimeException(
                    'gzipキャッシュを読み込めません: ' . $cacheFile
                );
            }

            $html = gzdecode($compressed);

            if ($html === false) {
                throw new RuntimeException(
                    'gzipキャッシュが壊れています: ' . $cacheFile
                );
            }

            $results[$name] = $html;
            continue;
        }

        $pending[$name] = [
            'url' => $url,
            'cache' => $cacheFile,
            'attempt' => 0,
        ];
    }

    $maxRetries = 5;

    while ($pending) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($pending as $name => &$item) {
            $item['attempt']++;

            $ch = curl_init($item['url']);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'Mozilla/5.0',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml',
                    'Connection: close',
                ],
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[$name] = $ch;
        }
        unset($item);

        do {
            $status = curl_multi_exec($mh, $running);

            if ($running) {
                $selected = curl_multi_select($mh, 1.0);

                if ($selected === -1) {
                    usleep(100000);
                }
            }
        } while ($running && $status === CURLM_OK);

        $retry = [];

        foreach ($handles as $name => $ch) {
            $body = curl_multi_getcontent($ch);
            $httpStatus = (int)curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );
            $curlError = curl_error($ch);

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $item = $pending[$name];
            $attempt = $item['attempt'];

            if ($body !== false
                && $body !== ''
                && $httpStatus >= 200
                && $httpStatus < 300
            ) {
                $compressed = gzencode($body, 6);

                if ($compressed === false) {
                    throw new RuntimeException(
                        'HTMLのgzip圧縮に失敗しました: '
                        . $item['cache']
                    );
                }

                $cacheDir = dirname($item['cache']);

                if (!is_dir($cacheDir)
                    && !mkdir($cacheDir, 0775, true)
                    && !is_dir($cacheDir)
                ) {
                    throw new RuntimeException(
                        'キャッシュディレクトリを作成できません: '
                        . $cacheDir
                    );
                }

                $tmpFile = $item['cache']
                    . '.tmp.'
                    . getmypid();

                if (file_put_contents(
                    $tmpFile,
                    $compressed,
                    LOCK_EX
                ) === false) {
                    throw new RuntimeException(
                        '一時gzipキャッシュの保存に失敗しました: '
                        . $tmpFile
                    );
                }

                if (!rename($tmpFile, $item['cache'])) {
                    @unlink($tmpFile);

                    throw new RuntimeException(
                        'gzipキャッシュの確定に失敗しました: '
                        . $item['cache']
                    );
                }

                $results[$name] = $body;
                continue;
            }

            if ($attempt >= $maxRetries) {
                throw new RuntimeException(
                    '公式ページの取得に失敗しました'
                    . ' HTTP ' . $httpStatus
                    . ($curlError !== ''
                        ? ' CURL ' . $curlError
                        : '')
                    . ': ' . $item['url']
                );
            }

            if ($httpStatus === 429) {
                $wait = 30 * $attempt;
            } elseif ($httpStatus >= 500
                || $httpStatus === 0
            ) {
                $wait = 5 * $attempt;
            } else {
                $wait = 3 * $attempt;
            }

            fwrite(
                STDERR,
                $name
                . " 取得再試行 {$attempt}/{$maxRetries}"
                . " HTTP {$httpStatus}"
                . " {$wait}秒待機\n"
            );

            $item['wait'] = $wait;
            $retry[$name] = $item;
        }

        curl_multi_close($mh);

        if ($retry) {
            /*
             * 同じ再試行ラウンド内では最長の待機時間を使う。
             * 各ページを順番にsleepして待機時間を加算しない。
             */
            $maxWait = max(
                array_column($retry, 'wait')
            );

            sleep($maxWait);

            foreach ($retry as &$item) {
                unset($item['wait']);
            }
            unset($item);
        }

        $pending = $retry;
    }

    /*
     * 呼び出し側が指定した順番で返す。
     */
    $ordered = [];

    foreach (array_keys($requests) as $name) {
        if (!array_key_exists($name, $results)) {
            throw new RuntimeException(
                'HTML取得結果がありません: ' . $name
            );
        }

        $ordered[$name] = $results[$name];
    }

    return $ordered;
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

$dbPath = __DIR__ . '/detail_test.sqlite';

$date = getenv('BR_DATE') ?: '2024-01-01';
$carbonDate = \Carbon\CarbonImmutable::parse($date);
$stadiumNumber = (int)(getenv('BR_STADIUM') ?: '6');
$raceNumber = (int)(getenv('BR_RACE') ?: '1');

$dateCompact = str_replace('-', '', $date);
$jcd = sprintf('%02d', $stadiumNumber);

$programUrl =
    'https://www.boatrace.jp/owpc/pc/race/racelist'
    . '?hd=' . $dateCompact
    . '&jcd=' . $jcd
    . '&rno=' . $raceNumber;

$beforeUrl =
    'https://www.boatrace.jp/owpc/pc/race/beforeinfo'
    . '?hd=' . $dateCompact
    . '&jcd=' . $jcd
    . '&rno=' . $raceNumber;

$resultUrl =
    'https://www.boatrace.jp/owpc/pc/race/raceresult'
    . '?hd=' . $dateCompact
    . '&jcd=' . $jcd
    . '&rno=' . $raceNumber;

/*
 * 公式HTML gzipキャッシュ
 * racelist / beforeinfo / raceresult を保存する。
 */
$cacheDir = __DIR__
    . '/html_cache/'
    . $dateCompact
    . '/'
    . $jcd
    . '/'
    . sprintf('%02d', $raceNumber);

if (!is_dir($cacheDir)
    && !mkdir($cacheDir, 0775, true)
    && !is_dir($cacheDir)) {
    throw new RuntimeException(
        'HTMLキャッシュディレクトリを作成できません。'
    );
}

$programCache = $cacheDir . '/racelist.html.gz';
$beforeCache = $cacheDir . '/beforeinfo.html.gz';
$resultCache = $cacheDir . '/raceresult.html.gz';

$t = microtime(true);

$htmlPages = fetchGzipCachedHtmlMulti([
    'racelist' => [
        'url' => $programUrl,
        'cache' => $programCache,
    ],
    'beforeinfo' => [
        'url' => $beforeUrl,
        'cache' => $beforeCache,
    ],
    'raceresult' => [
        'url' => $resultUrl,
        'cache' => $resultCache,
    ],
]);

printf(
    "HTML取得（最大3ページ並列）: %.3f秒\n",
    microtime(true) - $t
);

$programHtml = $htmlPages['racelist'];
$beforeHtml = $htmlPages['beforeinfo'];
$resultHtml = $htmlPages['raceresult'];

[, $programXpath] = loadDom($programHtml);
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

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 30000');
$pdo->exec('PRAGMA foreign_keys = ON');

echo "取得開始: {$date} 場{$stadiumNumber} {$raceNumber}R\n";

$programParser = new ProgramCacheParser();
$previewParser = new PreviewCacheParser();
$resultParser = new ResultCacheParser();

$program = $programParser->scrape(
    $carbonDate,
    $stadiumNumber,
    $raceNumber,
    $programHtml
);

$preview = $previewParser->scrape(
    $carbonDate,
    $stadiumNumber,
    $raceNumber,
    $beforeHtml
);

$result = $resultParser->scrape(
    $carbonDate,
    $stadiumNumber,
    $raceNumber,
    $resultHtml
);

/*
 * 後続の既存処理を変更しないため、
 * BVP Dispatcherと同じ配列構造に戻す。
 */
$programs = [
    $stadiumNumber => [
        $raceNumber => $program
    ]
];

$previews = [
    $stadiumNumber => [
        $raceNumber => $preview
    ]
];

$results = [
    $stadiumNumber => [
        $raceNumber => $result
    ]
];

$program = $programs[$stadiumNumber][$raceNumber] ?? null;
$preview = $previews[$stadiumNumber][$raceNumber] ?? null;
$result  = $results[$stadiumNumber][$raceNumber] ?? null;

if (!$program || !$preview || !$result) {
    throw new RuntimeException('Program / Preview / Result の取得に失敗しました。');
}

$pdo->beginTransaction();

try {
    /*
     * v3から登録済みのレースをそのまま使用する。
     * 既存データは削除しない。
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

    if ($existingRaceId === false) {
        throw new RuntimeException('対象レースがSQLiteにありません。');
    }

    $raceId = (int)$existingRaceId;

    /*
     * races
     * v3の既存レースを残したまま、Program / Preview / Result の
     * 詳細項目だけを補完する。
     */
    $stmt = $pdo->prepare(
        'UPDATE races SET
            closed_at = ?,
            day_label = ?,
            grade_label = ?,
            grade_number = ?,
            title = ?,
            subtitle = ?,
            distance = ?,

            result_wind_speed = COALESCE(?, result_wind_speed),
            result_wind_direction_number = COALESCE(?, result_wind_direction_number),
            result_wave_height = COALESCE(?, result_wave_height),
            result_weather_number = COALESCE(?, result_weather_number),
            result_air_temperature = COALESCE(?, result_air_temperature),
            result_water_temperature = COALESCE(?, result_water_temperature),

            preview_wind_speed = ?,
            preview_wind_direction_number = ?,
            preview_wave_height = ?,
            preview_weather_number = ?,
            preview_air_temperature = ?,
            preview_water_temperature = ?,

            technique_number = COALESCE(?, technique_number),
            updated_at = CURRENT_TIMESTAMP
         WHERE race_id = ?'
    );

    $stmt->execute([
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
        $raceId,
    ]);

    /*
     * race_entries
     * v3で登録済みの艇データを残し、
     * Programで取得できる詳細項目だけ補完する。
     */
    $entryStmt = $pdo->prepare(
        'UPDATE race_entries SET
            racer_number = COALESCE(?, racer_number),
            racer_name = COALESCE(?, racer_name),
            racer_class_number = ?,
            racer_branch_number = ?,
            racer_birthplace_number = ?,
            racer_age = ?,
            program_weight = ?,
            flying_count = ?,
            late_count = ?,
            average_start_timing = ?,
            national_win_rate = ?,
            national_top_2_percent = ?,
            national_top_3_percent = ?,
            local_win_rate = ?,
            local_top_2_percent = ?,
            local_top_3_percent = ?,
            motor_number = ?,
            motor_top_2_percent = ?,
            motor_top_3_percent = ?,
            assigned_boat_number = ?,
            assigned_boat_top_2_percent = ?,
            assigned_boat_top_3_percent = ?
         WHERE race_id = ?
           AND boat_number = ?'
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
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(race_id, boat_number) DO UPDATE SET
            exhibition_course_number = excluded.exhibition_course_number,
            exhibition_start_timing = excluded.exhibition_start_timing,
            weight = excluded.weight,
            weight_adjustment = excluded.weight_adjustment,
            exhibition_time = excluded.exhibition_time,
            tilt_adjustment = excluded.tilt_adjustment'
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

            $raceId,
            $boatNumber,
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
        'INSERT OR IGNORE INTO payouts (
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

    /*
     * 安定板・備考
     */
    $extraRaceStmt = $pdo->prepare(
        'UPDATE races
         SET stabilizer_used = ?,
             remarks = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE race_id = ?'
    );

    $extraRaceStmt->execute([
        $stabilizerUsed,
        $remarks !== '' ? $remarks : null,
        $raceId,
    ]);

    /*
     * プロペラ交換
     */
    $propellerStmt = $pdo->prepare(
        'UPDATE race_previews
         SET propeller_changed = ?
         WHERE race_id = ?
           AND boat_number = ?'
    );

    foreach ($boatExtras as $boatNumber => $extra) {
        $propellerStmt->execute([
            $extra['propeller_changed'],
            $raceId,
            $boatNumber,
        ]);
    }

    /*
     * 部品交換
     * 再実行時に重複しないよう、このレース分を削除して再登録する。
     */
    $partsDeleteStmt = $pdo->prepare(
        'DELETE FROM parts_replacements WHERE race_id = ?'
    );
    $partsDeleteStmt->execute([$raceId]);

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
                $quantityText = mb_convert_kana(
                    $match[2],
                    'n',
                    'UTF-8'
                );
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
     * レースタイム
     */
    $raceTimeStmt = $pdo->prepare(
        'UPDATE race_entries
         SET race_time_text = ?,
             race_time_seconds = ?
         WHERE race_id = ?
           AND boat_number = ?'
    );

    foreach ($raceTimes as $boatNumber => $time) {
        $raceTimeStmt->execute([
            $time['text'],
            $time['seconds'],
            $raceId,
            $boatNumber,
        ]);
    }

    /*
     * 払戻人気
     */
    $payoutSelectStmt = $pdo->prepare(
        'SELECT payout_id, bet_type, combination, amount
         FROM payouts
         WHERE race_id = ?'
    );

    $payoutSelectStmt->execute([$raceId]);

    $popularityStmt = $pdo->prepare(
        'UPDATE payouts
         SET popularity = ?
         WHERE payout_id = ?'
    );

    foreach ($payoutSelectStmt as $payout) {
        $key =
            $payout['bet_type']
            . '|'
            . $payout['combination']
            . '|'
            . $payout['amount'];

        if (array_key_exists($key, $payoutPopularity)) {
            $popularityStmt->execute([
                $payoutPopularity[$key],
                $payout['payout_id'],
            ]);
        }
    }

    /*
     * 返還
     * 再実行時に重複しないよう、このレース分を削除して再登録する。
     */
    $refundDeleteStmt = $pdo->prepare(
        'DELETE FROM refunds WHERE race_id = ?'
    );
    $refundDeleteStmt->execute([$raceId]);

    if ($refundText !== '') {
        preg_match_all('/[1-6]/', $refundText, $matches);

        $refundBoats = array_values(
            array_unique(
                array_map('intval', $matches[0])
            )
        );

        $refundInsertStmt = $pdo->prepare(
            'INSERT INTO refunds (
                race_id,
                boat_number,
                refund_text
            ) VALUES (?, ?, ?)'
        );

        foreach ($refundBoats as $refundBoatNumber) {
            $refundInsertStmt->execute([
                $raceId,
                $refundBoatNumber,
                $refundText,
            ]);
        }

        /*
         * 艇番を判定できない特殊な返還表記も
         * 原文を失わず保存する。
         */
        if (!$refundBoats) {
            $refundInsertStmt->execute([
                $raceId,
                null,
                $refundText,
            ]);
        }
    }

    /*
     * 取得完了状態
     * ここまで全処理が成功した場合のみ success にする。
     */
    $fetchStatusStmt = $pdo->prepare(
        'UPDATE races
         SET program_fetched_at = CURRENT_TIMESTAMP,
             preview_fetched_at = CURRENT_TIMESTAMP,
             result_fetched_at = CURRENT_TIMESTAMP,
             extra_fetched_at = CURRENT_TIMESTAMP,
             program_fetch_status = ?,
             preview_fetch_status = ?,
             result_fetch_status = ?,
             extra_fetch_status = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE race_id = ?'
    );

    $fetchStatusStmt->execute([
        'success',
        'success',
        'success',
        'success',
        $raceId,
    ]);

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
