<?php
declare(strict_types=1);

// Read-only official-source audit. No scraper, importer or database is invoked.
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/analysis_data/sqlite/ResultCacheParser_test.php';
use BoatRace\Cache\ResultCacheParser;
use Carbon\CarbonImmutable;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
});
$date = $argv[1] ?? '';
$day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($argc !== 3 || !$day || $day->format('Y-m-d') !== $date || $date < '2026-10-09' ||
    $date >= (new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d')) {
    throw new RuntimeException('Only strict-policy dates through Tokyo yesterday are permitted.');
}
$client = HttpClient::create(['timeout' => 30, 'max_duration' => 60, 'max_redirects' => 0]);
$evidence = [];
$sourceHtml = [];
$parser = new ResultCacheParser();
$payoutTypes = ['trifecta', 'trio', 'exacta', 'quinella', 'quinella_place', 'win', 'place'];
$fetch = function (string $url) use ($client, &$evidence, &$sourceHtml): Crawler {
    $response = $client->request('GET', $url);
    if ($response->getStatusCode() !== 200) { throw new RuntimeException("Official HTTP failure: $url"); }
    $html = $response->getContent();
    if (!str_contains($html, 'BOAT RACE') || !str_contains($html, '<main')) {
        throw new RuntimeException("Unrecognized official page: $url");
    }
    $evidence[] = ['url' => $url, 'sha256' => hash('sha256', $html), 'fetched_at' => gmdate('c')];
    $sourceHtml[$url] = $html;
    $crawler = new Crawler(null, $url);
    $crawler->addHtmlContent($html, 'UTF-8');
    return $crawler;
};
$hd = $day->format('Ymd');
$index = $fetch("https://www.boatrace.jp/owpc/pc/race/index?hd=$hd");
$indexMain = $index->filter('main');
if (!$indexMain->count()) {
    throw new RuntimeException("Missing official index main: $date (https://www.boatrace.jp/owpc/pc/race/index?hd=$hd)");
}
$heading = preg_replace('/\s+/u', '', $indexMain->text());
if (!str_contains($heading, $day->format('n') . '月' . $day->format('j') . '日のレース情報')) {
    throw new RuntimeException('Official date identity mismatch.');
}
$venues = [];
foreach ($index->filter('main a[href]') as $link) {
    $url = parse_url(html_entity_decode($link->getAttribute('href')));
    parse_str($url['query'] ?? '', $q);
    if (($url['path'] ?? '') === '/owpc/pc/race/resultlist' && ($q['hd'] ?? '') === $hd) {
        $jcd = (int)($q['jcd'] ?? 0);
        if ($jcd < 1 || $jcd > 24) { throw new RuntimeException('Invalid official venue.'); }
        $venues[$jcd] = true;
    }
}
$expected = [];
$cancelled = [];
if (!$venues && !preg_match('/本日[はの].{0,20}開催.{0,20}(ありません|ございません)|開催[はが].{0,10}ありません/u', $heading)) {
    throw new RuntimeException('Empty venue list without explicit official no-meetings evidence.');
}
foreach (array_keys($venues) as $venue) {
    $page = $fetch(sprintf('https://www.boatrace.jp/owpc/pc/race/resultlist?hd=%s&jcd=%02d', $hd, $venue));
    // First payout table only: the page also repeats race links in other tables.
    $tables = $page->filter('main table');
    $table = null;
    foreach ($tables as $node) {
        $candidate = new Crawler($node);
        if (str_contains($candidate->text(), '3連勝単式') && str_contains($candidate->text(), '2連勝単式')) {
            $table = $candidate; break;
        }
    }
    if ($table === null) { throw new RuntimeException("Missing official payout table: $venue"); }
    $seen = [];
    foreach ($table->filter('tbody tr') as $node) {
        $row = new Crawler($node);
        $links = $row->filter('a[href*="raceresult"]');
        if (!$links->count()) { continue; }
        parse_str(parse_url(html_entity_decode($links->first()->attr('href')), PHP_URL_QUERY) ?? '', $q);
        $race = (int)($q['rno'] ?? 0);
        if (($q['hd'] ?? '') !== $hd || (int)($q['jcd'] ?? 0) !== $venue || $race < 1 || $race > 12 || isset($seen[$race])) {
            throw new RuntimeException('Official race identity conflict.');
        }
        $seen[$race] = true;
        $key = "$date|$venue|$race";
        // Only a race-wide status cell denotes cancellation. A refunded or
        // unavailable individual payout type must not cancel the whole race.
        $isCancelled = false;
        $statusCells = [];
        foreach ($row->filter('td') as $cell) {
            if ((new Crawler($cell))->filter('a[href*="raceresult"]')->count()) { continue; }
            $statusCells[] = preg_replace('/\s+/u', '', $cell->textContent);
        }
        if ($statusCells && array_filter($statusCells, static fn(string $text): bool =>
            in_array($text, ['中止', '不成立'], true)) &&
            !array_filter($statusCells, static fn(string $text): bool =>
                !in_array($text, ['', '中止', '不成立', '返還', '特払', '-', '－', '―', '—'], true))) {
            $isCancelled = true;
        }
        foreach ($row->filter('td[colspan]') as $cell) {
            if ((int)$cell->getAttribute('colspan') >= 4 &&
                in_array(preg_replace('/\s+/u', '', $cell->textContent), ['中止', '不成立'], true)) {
                $isCancelled = true;
            }
        }
        $detailUrl = sprintf('https://www.boatrace.jp/owpc/pc/race/raceresult?hd=%s&jcd=%02d&rno=%d', $hd, $venue, $race);
        $detail = $fetch($detailUrl);
        $result = $parser->scrape(CarbonImmutable::parse($date, 'Asia/Tokyo'), $venue, $race, $sourceHtml[$detailUrl]);
        $result['boats'] = array_values($result['boats']);
        foreach ($payoutTypes as $type) {
            $result['payouts'][$type] = array_values($result['payouts'][$type] ?? []);
        }
        // Check source nodes, not the parser's six default boat placeholders.
        // Use the same layout offset and paths as ResultCacheParser. Missing
        // start courses, null fields, nonfinish codes and tied places are valid.
        if (!$isCancelled) {
            $baseXPath = 'descendant-or-self::body/main/div/div/div';
            $baseLevel = $detail->filterXPath($baseXPath . '/div[2]/div[3]/ul/li')->count() ? 1 : 0;
            $resultXPath = $baseXPath . '/div[2]/div[' . ($baseLevel + 5) . ']';
            $boatTable = $detail->filterXPath($resultXPath . '/div[1]/div/table');
            $sourceBoats = [];
            if ($boatTable->count() !== 1 || $boatTable->filterXPath('./*/tbody')->count() !== 6) {
                throw new RuntimeException("Incomplete official boat table: $key");
            }
            foreach ($boatTable->filterXPath('./*/tbody') as $body) {
                $boatRow = new Crawler($body);
                $cells = $boatRow->filterXPath('./*/tr/td');
                if ($boatRow->filterXPath('./*/tr')->count() !== 1 || $cells->count() < 4) {
                    throw new RuntimeException("Incomplete official boat row: $key");
                }
                $boatNumber = \BVP\Converter\Converter::convertToInt($cells->eq(1)->text());
                if ($boatNumber === null || $boatNumber < 1 || $boatNumber > 6 || isset($sourceBoats[$boatNumber])) {
                    throw new RuntimeException("Invalid official boat coverage: $key");
                }
                $sourceBoats[$boatNumber] = true;
            }
            if (!$detail->filterXPath($resultXPath . '/div[2]/div/table')->count()) {
                throw new RuntimeException("Missing official start table: $key");
            }
            // The parser reads seven bet-type bodies at this fixed payout path.
            // Validate the actual nodes consumed by ResultCacheParser. Empty
            // parser output alone cannot establish an unavailable payout.
            $payoutTable = $detail->filterXPath($baseXPath . '/div[2]/div[6]/div[1]/div/table');
            if ($payoutTable->count() !== 1 || $payoutTable->filterXPath('./*/tbody')->count() !== count($payoutTypes)) {
                throw new RuntimeException("Incomplete official payout section: $key");
            }
            $payoutLabels = ['3連単', '3連複', '2連単', '2連複', '拡連複', '単勝', '複勝'];
            $rowLimits = [2, 2, 3, 3, 5, 2, 3];
            $spanCounts = [5, 5, 3, 3, 3, 1, 1];
            $separators = ['-', '=', '-', '=', '=', '', ''];
            $normalize = static fn(string $text): string => preg_replace('/[\s\x{3000}]+/u', '', $text);
            $outcomes = ['発売なし', '発売無し', '発売中止', '不発売', '返還', '全返還', '特払', '特払い',
                '特別払戻', '特別払い戻し', '不成立', '中止', '-', '－', '―', '—'];
            $isOutcome = static fn(string $text): bool => in_array($text, $outcomes, true);
            $isAmount = static fn(string $text): bool => (bool)preg_match('/^¥?(?:[0-9]+|[1-9][0-9]{0,2}(?:,[0-9]{3})+)$/u', $text);
            foreach ($payoutTable->filterXPath('./*/tbody') as $typeIndex => $body) {
                $payoutBody = new Crawler($body);
                $rows = $payoutBody->filterXPath('./*/tr');
                if (!$rows->count() || $rows->count() > $rowLimits[$typeIndex]) {
                    throw new RuntimeException("Missing official payout rows: $key");
                }
                $label = $rows->first()->filterXPath('./*/td[1]');
                if (!$label->count() || preg_replace('/\s+/u', '', $label->text()) !== $payoutLabels[$typeIndex]) {
                    throw new RuntimeException("Unrecognized official payout type: $key");
                }
                $hasPayoutOrOutcome = false;
                foreach ($rows as $index => $payoutRow) {
                    $rowCrawler = new Crawler($payoutRow);
                    $cells = $rowCrawler->filterXPath('./*/td');
                    $combinationCell = $cells->eq($index === 0 ? 1 : 0);
                    // A single explicitly labelled status may span all payout
                    // columns. A colspan with empty/arbitrary text is not evidence.
                    if ($cells->count() === ($index === 0 ? 2 : 1) &&
                        (int)$combinationCell->attr('colspan') >= 3 &&
                        $isOutcome($normalize($combinationCell->text()))) {
                        $hasPayoutOrOutcome = true;
                        continue;
                    }
                    if ($cells->count() < ($index === 0 ? 4 : 3)) {
                        throw new RuntimeException("Incomplete official payout row: $key");
                    }
                    $amountCell = $cells->eq($index === 0 ? 2 : 1);
                    $combinationText = $normalize($combinationCell->text());
                    $amountText = $normalize($amountCell->text());
                    // Win/place combinations use a descendant path in the
                    // existing parser; amounts use the direct path for all bets.
                    $prefix = $baseXPath . ($typeIndex >= 5 ? '//div[2]' : '/div[2]') .
                        '/div[6]/div[1]/div/table/tbody[' . ($typeIndex + 1) . ']/tr[' . ($index + 1) . ']';
                    $combinationPath = $prefix . '/td[' . ($index === 0 ? 2 : 1) . ']/div/div';
                    $spans = $detail->filterXPath($combinationPath . '/span');
                    $amountPath = $baseXPath . '/div[2]/div[6]/div[1]/div/table/tbody[' .
                        ($typeIndex + 1) . ']/tr[' . ($index + 1) . ']/td[' . ($index === 0 ? 3 : 2) . ']/span';
                    $amountNodes = $detail->filterXPath($amountPath);
                    // Only the exact empty continuation layout is a placeholder.
                    // It never counts as payout/outcome evidence for this body.
                    $placeholderNormalize = static fn(string $text): string =>
                        $normalize(str_replace("\u{00A0}", '', $text));
                    if ($index > 0 && $cells->count() === 3 &&
                        $rowCrawler->filterXPath('./*/*')->count() === 3 &&
                        !$rowCrawler->filterXPath('./*/td[@rowspan and @rowspan != "1"] | ./*/td[@colspan and @colspan != "1"]')->count() &&
                        $placeholderNormalize($combinationCell->text()) === '' &&
                        $placeholderNormalize($amountCell->text()) === '' &&
                        $placeholderNormalize($cells->eq(2)->text()) === '' &&
                        !$combinationCell->filterXPath('./*/descendant::div | ./*/descendant::span')->count() &&
                        $amountNodes->count() === 1 &&
                        $amountCell->filterXPath('./*/descendant::*')->count() === 1 &&
                        $placeholderNormalize($amountNodes->text()) === '') {
                        continue;
                    }
                    $combination = '';
                    $numbers = [];
                    $validCombination = $detail->filterXPath($combinationPath)->count() === 1 &&
                        $spans->count() === $spanCounts[$typeIndex];
                    foreach ($spans as $spanIndex => $span) {
                        $text = $normalize($span->textContent);
                        $combination .= $text;
                        if ($spanIndex % 2 === 0) {
                            $validCombination = $validCombination && (bool)preg_match('/^[1-6]$/', $text);
                            $numbers[] = $text;
                        } else {
                            $validCombination = $validCombination && $text === $separators[$typeIndex];
                        }
                    }
                    $validCombination = $validCombination && $combination === $combinationText &&
                        count(array_unique($numbers)) === count($numbers);
                    $validAmount = $amountNodes->count() === 1 && $isAmount($amountText) &&
                        $normalize($amountNodes->text()) === $amountText;
                    if ($validCombination && $validAmount) {
                        $hasPayoutOrOutcome = true;
                        continue;
                    }
                    // Explicit nonpayable/special content may replace the usual
                    // spans, but may not conceal a partial combination or amount.
                    if (($isOutcome($combinationText) || $isOutcome($amountText)) &&
                        ($validCombination || $combinationText === '' || $isOutcome($combinationText)) &&
                        ($validAmount || $amountText === '' || $isOutcome($amountText))) {
                        $hasPayoutOrOutcome = true;
                        continue;
                    }
                    throw new RuntimeException("Missing or malformed official payout content: $key {$payoutTypes[$typeIndex]}");
                }
                if (!$hasPayoutOrOutcome) {
                    throw new RuntimeException("Missing official payout or outcome: $key {$payoutTypes[$typeIndex]}");
                }
            }
        }
        if ($isCancelled) { $cancelled[] = $key; }
        $expected[$key] = $result;
    }
    if (count($seen) !== 12) { throw new RuntimeException("Official race coverage incomplete: $venue"); }
}
$path = $argv[2];
$hash = is_file($path) ? hash_file('sha256', $path) : null;
$races = [];
if ($hash !== null) {
    $json = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!isset($json['results']) || !is_array($json['results']) || !array_is_list($json['results'])) {
        throw new RuntimeException('Invalid local results array.');
    }
    foreach ($json['results'] as $race) {
        $key = ($race['date'] ?? '') . '|' . ($race['stadium_number'] ?? '') . '|' . ($race['number'] ?? '');
        if (isset($races[$key]) || !isset($expected[$key])) { throw new RuntimeException("Local/official race conflict: $key"); }
        $official = $expected[$key];
        // Compare the parser's actual boat results, including ties, F/L and
        // nonfinish codes, instead of inferring places from a single trifecta.
        $boats = $race['boats'] ?? null;
        if (!is_array($boats) || !array_is_list($boats) || count($boats) !== count($official['boats'])) {
            throw new RuntimeException("Incomplete boat coverage: $key");
        }
        $byNumber = [];
        foreach ($boats as $boat) {
            $n = $boat['racer_boat_number'] ?? null;
            if (!is_int($n) || $n < 1 || $n > 6 || isset($byNumber[$n])) {
                throw new RuntimeException("Invalid boat identity: $key");
            }
            $byNumber[$n] = $boat;
        }
        foreach ($official['boats'] as $boat) {
            $local = $byNumber[$boat['racer_boat_number']] ?? [];
            foreach ($boat as $field => $value) {
                if (!array_key_exists($field, $local) || $local[$field] != $value ||
                    ($local[$field] === null) !== ($value === null)) {
                    throw new RuntimeException("Official boat conflict: $key $field");
                }
            }
        }
        foreach (['wind_speed', 'wind_direction_number', 'wave_height', 'weather_number',
            'air_temperature', 'water_temperature', 'technique_number'] as $field) {
            if (!array_key_exists($field, $race) || $race[$field] != $official[$field] ||
                ($race[$field] === null) !== ($official[$field] === null)) {
                throw new RuntimeException("Official result conflict: $key $field");
            }
        }
        if (!isset($race['payouts']) || !is_array($race['payouts']) ||
            array_diff(array_keys($race['payouts']), $payoutTypes)) {
            throw new RuntimeException("Invalid payout types: $key");
        }
        foreach ($payoutTypes as $type) {
            $items = $race['payouts'][$type] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                throw new RuntimeException("Invalid payout array: $key $type");
            }
            $canonical = static function (array $items) use ($key, $type): array {
                $rows = [];
                foreach ($items as $item) {
                    if (!is_array($item) || !is_string($item['combination'] ?? null) ||
                        !is_int($item['amount'] ?? null)) {
                        throw new RuntimeException("Invalid payout: $key $type");
                    }
                    $rows[] = [$item['combination'], $item['amount']];
                }
                sort($rows);
                return $rows;
            };
            if ($canonical($items) !== $canonical($official['payouts'][$type])) {
                throw new RuntimeException("Official payout conflict: $key $type");
            }
        }
        $races[$key] = true;
    }
}
foreach ($expected as $key => $result) {
    if (!isset($races[$key]) && !in_array($key, $cancelled, true)) {
        throw new RuntimeException("Local/official race coverage mismatch: $key");
    }
}
if ($hash !== (is_file($path) ? hash_file('sha256', $path) : null)) { throw new RuntimeException('JSON changed during official audit.'); }
// Cancellation placeholders remain importable/auditable when locally present.
$keys = array_keys($races);
sort($keys);
echo json_encode(['version' => 2, 'date' => $date, 'status' => $keys ? 'source_verified' : 'verified_no_races',
    'json_sha256' => $hash, 'keys' => $keys, 'cancelled_keys' => $cancelled, 'evidence' => $evidence], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
