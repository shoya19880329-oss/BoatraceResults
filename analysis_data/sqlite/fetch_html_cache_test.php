<?php
declare(strict_types=1);

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


$date = getenv('BR_DATE') ?: '2024-01-01';
$stadiumNumber = (int)(getenv('BR_STADIUM') ?: '6');
$raceNumber = (int)(getenv('BR_RACE') ?: '1');

$dateCompact = str_replace('-', '', $date);
$jcd = sprintf('%02d', $stadiumNumber);

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

$requests = [
    'racelist' => [
        'url' => 'https://www.boatrace.jp/owpc/pc/race/racelist'
            . '?hd=' . $dateCompact
            . '&jcd=' . $jcd
            . '&rno=' . $raceNumber,
        'cache' => $cacheDir . '/racelist.html.gz',
    ],
    'beforeinfo' => [
        'url' => 'https://www.boatrace.jp/owpc/pc/race/beforeinfo'
            . '?hd=' . $dateCompact
            . '&jcd=' . $jcd
            . '&rno=' . $raceNumber,
        'cache' => $cacheDir . '/beforeinfo.html.gz',
    ],
    'raceresult' => [
        'url' => 'https://www.boatrace.jp/owpc/pc/race/raceresult'
            . '?hd=' . $dateCompact
            . '&jcd=' . $jcd
            . '&rno=' . $raceNumber,
        'cache' => $cacheDir . '/raceresult.html.gz',
    ],
];

$t = microtime(true);
fetchGzipCachedHtmlMulti($requests);

printf(
    "HTMLキャッシュ取得完了: %s 場%d %dR %.3f秒\n",
    $date,
    $stadiumNumber,
    $raceNumber,
    microtime(true) - $t
);
