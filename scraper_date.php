<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BOA\Results\ResultScraper;
use BOA\Results\ResultSaver;
use BOA\Results\ScraperAdapter;
use BVP\Scraper\Scraper;
use Carbon\CarbonImmutable as Carbon;

// 繧ｳ繝槭Φ繝峨Λ繧､繝ｳ蠑墓焚縺九ｉ繝舌・繧ｸ繝ｧ繝ｳ繧貞叙蠕暦ｼ医ョ繝輔か繝ｫ繝医・ v3・・
$version = $argv[1] ?? 'v3';
$day_specified = ($argc == 3);

// 譏ｨ譌･縺ｮ譌･莉倥ｒ譚ｱ莠ｬ譎る俣縺ｧ蜿門ｾ・
$date = Carbon::yesterday('Asia/Tokyo');
if ($day_specified) {
    $date = Carbon::parse($argv[2], 'Asia/Tokyo');
    if ($date->isBefore(Carbon::parse('2020-01-01'))) {
        exit;
    }
}

// v2 or v3 縺ｮ蝣ｴ蜷医・縺ｿ ResultScraper 繧貞茜逕ｨ縺励※邨先棡繝・・繧ｿ繧貞叙蠕・
if ($version === 'v2' || $version === 'v3') {
    $scraperInstance = Scraper::getInstance();
    $scraperAdapter = new ScraperAdapter($scraperInstance);
    $scraper = new ResultScraper($scraperAdapter);

    // 謖・ｮ壽律莉倥・邨先棡繝・・繧ｿ繧偵せ繧ｯ繝ｬ繧､繝斐Φ繧ｰ
    $results = $scraper->scrape($date);
}

// 邨先棡繝・・繧ｿ縺悟叙蠕励〒縺阪↑縺九▲縺溷ｴ蜷医・蜃ｦ逅・ｵゆｺ・
if (empty($results ?? [])) {
    exit;
}

// 邨先棡繝・・繧ｿ繧・JSON 繝輔ぃ繧､繝ｫ縺ｨ縺励※菫晏ｭ・
// 譌･莉倅ｻ倥″縺ｮ JSON 繝輔ぃ繧､繝ｫ縺ｨ縺励※菫晏ｭ假ｼ井ｾ・ docs/v3/2026/20260322.json・・
// 譛譁ｰ繝・・繧ｿ縺ｨ縺励※ yesterday.json 縺ｫ繧ゆｿ晏ｭ・
$saver = new ResultSaver();
$saver->save($results, "docs/{$version}/" . $date->format('Y') . '/' . $date->format('Ymd') . '.json');
if (!$day_specified) { $saver->save($results, "docs/{$version}/yesterday.json"); }



