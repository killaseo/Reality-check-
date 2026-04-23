<?php
/**
 * fetch_cache.php — worker dla crona
 * Pobiera dane rynkowe + RSS i zapisuje do cache.json
 *
 * Cron (co 5 minut):
 *   *\/5 * * * * /usr/bin/php /home/user/public_html/fetch_cache.php >> /home/user/logs/ai_cache.log 2>&1
 *
 * Lub przez panel hostingu (cPanel / DirectAdmin):
 *   Komenda: php /home/TWOJLOGIN/public_html/fetch_cache.php
 *   Częstotliwość: co 5 minut
 */

define('CACHE_FILE',       __DIR__ . '/cache.json');
define('CACHE_LOCK',       __DIR__ . '/cache.lock');
define('KNOWLEDGE_CUTOFF', '2025-08-31');
define('TIMEZONE',         'Europe/Warsaw');
define('NEWS_PER_FEED',    5);

// ─── LOCK (zapobiega równoczesnym uruchomieniom) ───────────────────────────

if (file_exists(CACHE_LOCK) && (time() - filemtime(CACHE_LOCK)) < 120) {
    echo "[" . date('H:i:s') . "] Lock aktywny, pomijam.\n";
    exit(0);
}
file_put_contents(CACHE_LOCK, date('c'));

echo "[" . date('H:i:s') . "] Start fetch_cache.php\n";

// ─── HTTP FETCH ────────────────────────────────────────────────────────────

function fetchRaw(string $url, int $timeout = 10, string $accept = 'application/json'): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'AIContextAggregator/3.0 (+https://killaseo.pl/ai.php)',
            CURLOPT_HTTPHEADER     => ["Accept: {$accept}"],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => 'gzip, deflate',
        ]);
        $raw = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($err || !$raw) return null;
        return $raw;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout'    => $timeout,
            'user_agent' => 'AIContextAggregator/3.0',
            'header'     => "Accept: {$accept}\r\nAccept-Encoding: gzip\r\n",
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        return $raw !== false ? $raw : null;
    }
    return null;
}

function fetchJson(string $url): ?array {
    $raw  = fetchRaw($url);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

// ─── RYNKI ─────────────────────────────────────────────────────────────────

function fetchCrypto(): array {
    $r = ['btc'=>null,'eth'=>null,'btc_24h'=>null,'eth_24h'=>null,'btc_pln'=>null,'source'=>null];

    // CoinGecko
    $d = fetchJson('https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum&vs_currencies=usd,pln&include_24hr_change=true');
    if ($d && isset($d['bitcoin']['usd'])) {
        $r = array_merge($r, [
            'btc'     => round($d['bitcoin']['usd'], 2),
            'btc_pln' => round($d['bitcoin']['pln'] ?? 0, 0),
            'btc_24h' => round($d['bitcoin']['usd_24h_change'] ?? 0, 2),
            'eth'     => round($d['ethereum']['usd'] ?? 0, 2),
            'eth_24h' => round($d['ethereum']['usd_24h_change'] ?? 0, 2),
            'source'  => 'coingecko',
        ]);
        echo "[" . date('H:i:s') . "] Crypto OK (CoinGecko): BTC={$r['btc']}\n";
        return $r;
    }

    // Fallback Binance
    $b1 = fetchJson('https://api.binance.com/api/v3/ticker/24hr?symbol=BTCUSDT');
    $b2 = fetchJson('https://api.binance.com/api/v3/ticker/24hr?symbol=ETHUSDT');
    if ($b1) {
        $r['btc']    = round((float)($b1['lastPrice'] ?? 0), 2) ?: null;
        $r['btc_24h']= round((float)($b1['priceChangePercent'] ?? 0), 2);
    }
    if ($b2) {
        $r['eth']    = round((float)($b2['lastPrice'] ?? 0), 2) ?: null;
        $r['eth_24h']= round((float)($b2['priceChangePercent'] ?? 0), 2);
    }
    $r['source'] = 'binance';
    echo "[" . date('H:i:s') . "] Crypto OK (Binance fallback): BTC={$r['btc']}\n";
    return $r;
}

function fetchForex(): array {
    $r = ['usd_pln'=>null,'usd_eur'=>null,'usd_gbp'=>null,'usd_chf'=>null,'source'=>null];

    $d = fetchJson('https://api.frankfurter.app/latest?from=USD&to=PLN,EUR,GBP,CHF');
    if ($d && isset($d['rates']['PLN'])) {
        $r = [
            'usd_pln' => round($d['rates']['PLN'], 4),
            'usd_eur' => round($d['rates']['EUR'], 4),
            'usd_gbp' => round($d['rates']['GBP'], 4),
            'usd_chf' => round($d['rates']['CHF'] ?? 0, 4) ?: null,
            'source'  => 'frankfurter',
        ];
        echo "[" . date('H:i:s') . "] Forex OK (Frankfurter): PLN={$r['usd_pln']}\n";
        return $r;
    }

    $d2 = fetchJson('https://open.er-api.com/v6/latest/USD');
    if ($d2 && isset($d2['rates']['PLN'])) {
        $r = [
            'usd_pln' => round($d2['rates']['PLN'], 4),
            'usd_eur' => round($d2['rates']['EUR'], 4),
            'usd_gbp' => round($d2['rates']['GBP'], 4),
            'usd_chf' => round($d2['rates']['CHF'] ?? 0, 4) ?: null,
            'source'  => 'open.er-api',
        ];
        echo "[" . date('H:i:s') . "] Forex OK (open.er-api fallback): PLN={$r['usd_pln']}\n";
    }
    return $r;
}

function fetchMetals(): array {
    $r = ['gold'=>null,'silver'=>null,'source'=>null];

    $d = fetchJson('https://api.metals.live/v1/spot/gold,silver');
    if (is_array($d)) {
        foreach ($d as $entry) {
            if (isset($entry['gold']))   $r['gold']   = round($entry['gold'], 2);
            if (isset($entry['silver'])) $r['silver'] = round($entry['silver'], 2);
        }
    }
    if ($r['gold']) {
        $r['source'] = 'metals.live';
        echo "[" . date('H:i:s') . "] Metals OK: XAU={$r['gold']}\n";
        return $r;
    }

    // Fallback: frankfurter XAU
    $d2 = fetchJson('https://api.frankfurter.app/latest?from=XAU&to=USD');
    if ($d2 && isset($d2['rates']['USD'])) {
        $r['gold']   = round($d2['rates']['USD'], 2);
        $r['source'] = 'frankfurter-xau';
        echo "[" . date('H:i:s') . "] Metals OK (Frankfurter XAU fallback): XAU={$r['gold']}\n";
    }
    return $r;
}

// ─── RSS PARSER ────────────────────────────────────────────────────────────

function parseRss(string $url, int $limit = 5): array {
    $raw = fetchRaw($url, 12, 'application/rss+xml, application/xml, text/xml');
    if (!$raw) return [];

    // Wyłącz błędy XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    libxml_clear_errors();
    if (!$xml) return [];

    $items = [];
    $nodes = $xml->channel->item ?? $xml->entry ?? [];

    foreach ($nodes as $item) {
        if (count($items) >= $limit) break;

        // Obsługa Atom vs RSS
        $title = (string)($item->title ?? '');
        $link  = (string)($item->link ?? $item->id ?? '');
        if (!$link && isset($item->link['href'])) $link = (string)$item->link['href'];
        $pubDate = (string)($item->pubDate ?? $item->published ?? $item->updated ?? '');
        $desc  = (string)($item->description ?? $item->summary ?? '');

        // Wyczyść HTML z opisu
        $desc = strip_tags($desc);
        $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc = mb_substr(trim($desc), 0, 160);
        if (mb_strlen($desc) === 160) $desc .= '…';

        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!$title || !$link) continue;

        // Parsuj datę na timestamp
        $ts = 0;
        if ($pubDate) {
            $ts = @strtotime($pubDate) ?: 0;
        }

        $items[] = [
            'title'   => $title,
            'link'    => $link,
            'desc'    => $desc,
            'ts'      => $ts,
            'date'    => $ts ? date('d.m H:i', $ts) : '',
        ];
    }

    return $items;
}

function fetchAllNews(): array {
    $feeds = [
        // AI / Tech
        ['id'=>'anthropic',  'label'=>'Anthropic Blog',    'cat'=>'ai',    'url'=>'https://www.anthropic.com/rss.xml'],
        ['id'=>'openai',     'label'=>'OpenAI Blog',       'cat'=>'ai',    'url'=>'https://openai.com/blog/rss.xml'],
        ['id'=>'googledev',  'label'=>'Google AI Blog',    'cat'=>'ai',    'url'=>'https://blog.google/technology/ai/rss/'],
        ['id'=>'verge_ai',   'label'=>'The Verge · AI',    'cat'=>'ai',    'url'=>'https://www.theverge.com/ai-artificial-intelligence/rss/index.xml'],
        ['id'=>'ars_tech',   'label'=>'Ars Technica',      'cat'=>'tech',  'url'=>'https://feeds.arstechnica.com/arstechnica/technology-lab'],
        // Świat / Finanse
        ['id'=>'bbc_world',  'label'=>'BBC World',         'cat'=>'world', 'url'=>'https://feeds.bbci.co.uk/news/world/rss.xml'],
        ['id'=>'reuters',    'label'=>'Reuters Business',  'cat'=>'world', 'url'=>'https://feeds.reuters.com/reuters/businessNews'],
        ['id'=>'bloomberg',  'label'=>'Bloomberg Markets', 'cat'=>'world', 'url'=>'https://feeds.bloomberg.com/markets/news.rss'],
        // Polska
        ['id'=>'tvn24',      'label'=>'TVN24',             'cat'=>'pl',    'url'=>'https://tvn24.pl/najnowsze.xml'],
        ['id'=>'wp',         'label'=>'WP Wiadomości',     'cat'=>'pl',    'url'=>'https://wiadomosci.wp.pl/rss.xml'],
        ['id'=>'bankier',    'label'=>'Bankier.pl',        'cat'=>'pl',    'url'=>'https://www.bankier.pl/rss/wiadomosci.xml'],
    ];

    $result = [];
    foreach ($feeds as $feed) {
        echo "[" . date('H:i:s') . "] RSS: {$feed['label']}…\n";
        $items = parseRss($feed['url'], NEWS_PER_FEED);
        $result[] = [
            'id'     => $feed['id'],
            'label'  => $feed['label'],
            'cat'    => $feed['cat'],
            'items'  => $items,
            'count'  => count($items),
            'ok'     => count($items) > 0,
        ];
        // Małe opóźnienie żeby nie blokować
        usleep(200000); // 200ms
    }
    return $result;
}

// ─── ZBIERZ WSZYSTKO I ZAPISZ ──────────────────────────────────────────────

$now    = new DateTimeImmutable('now', new DateTimeZone(TIMEZONE));
$cutoff = new DateTimeImmutable(KNOWLEDGE_CUTOFF);
$daysOld = (int)(($now->getTimestamp() - $cutoff->getTimestamp()) / 86400);

$crypto = fetchCrypto();
$forex  = fetchForex();
$metals = fetchMetals();
$news   = fetchAllNews();

// Volatility alerts
$alerts = [];
$checks = [
    ['BTC/USD',  $crypto['btc_24h'] ?? null, 5],
    ['ETH/USD',  $crypto['eth_24h'] ?? null, 5],
];
foreach ($checks as [$name, $ch, $threshold]) {
    if ($ch !== null && abs($ch) >= $threshold) {
        $alerts[] = [
            'asset'   => $name,
            'change'  => $ch,
            'label'   => abs($ch) >= 10 ? 'EXTREME' : 'HIGH',
        ];
    }
}

// Data quality
$marketSources = array_filter([
    $crypto['source'] ?? null,
    $forex['source']  ?? null,
    $metals['source'] ?? null,
]);
$dataQuality = [
    'market_sources'  => array_values($marketSources),
    'news_feeds_ok'   => count(array_filter($news, fn($f) => $f['ok'])),
    'news_feeds_total'=> count($news),
    'generated_at'    => $now->format('c'),
    'generated_unix'  => $now->getTimestamp(),
];

$cache = [
    'meta' => [
        'generated'        => $now->format('c'),
        'generated_unix'   => $now->getTimestamp(),
        'timezone'         => TIMEZONE,
        'cutoff'           => KNOWLEDGE_CUTOFF,
        'days_since_cutoff'=> $daysOld,
        'quality'          => $dataQuality,
        'alerts'           => $alerts,
    ],
    'market' => array_merge($crypto, $forex, $metals),
    'news'   => $news,
];

$written = file_put_contents(
    CACHE_FILE,
    json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

if ($written) {
    echo "[" . date('H:i:s') . "] Cache zapisany: " . round($written/1024, 1) . " KB → " . CACHE_FILE . "\n";
} else {
    echo "[" . date('H:i:s') . "] BŁĄD: nie można zapisać " . CACHE_FILE . "\n";
}

// Usuń lock
@unlink(CACHE_LOCK);
echo "[" . date('H:i:s') . "] Gotowe.\n";
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'AIContextAggregator/3.0 (+https://killaseo.pl/ai.php)',
            CURLOPT_HTTPHEADER     => ["Accept: {$accept}"],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => 'gzip, deflate',
        ]);
        $raw = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($err || !$raw) return null;
        return $raw;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout'    => $timeout,
            'user_agent' => 'AIContextAggregator/3.0',
            'header'     => "Accept: {$accept}\r\nAccept-Encoding: gzip\r\n",
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        return $raw !== false ? $raw : null;
    }
    return null;
}

function fetchJson(string $url): ?array {
    $raw  = fetchRaw($url);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

// ─── RYNKI ─────────────────────────────────────────────────────────────────

function fetchCrypto(): array {
    $r = ['btc'=>null,'eth'=>null,'btc_24h'=>null,'eth_24h'=>null,'btc_pln'=>null,'source'=>null];

    // CoinGecko
    $d = fetchJson('https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum&vs_currencies=usd,pln&include_24hr_change=true');
    if ($d && isset($d['bitcoin']['usd'])) {
        $r = array_merge($r, [
            'btc'     => round($d['bitcoin']['usd'], 2),
            'btc_pln' => round($d['bitcoin']['pln'] ?? 0, 0),
            'btc_24h' => round($d['bitcoin']['usd_24h_change'] ?? 0, 2),
            'eth'     => round($d['ethereum']['usd'] ?? 0, 2),
            'eth_24h' => round($d['ethereum']['usd_24h_change'] ?? 0, 2),
            'source'  => 'coingecko',
        ]);
        echo "[" . date('H:i:s') . "] Crypto OK (CoinGecko): BTC={$r['btc']}\n";
        return $r;
    }

    // Fallback Binance
    $b1 = fetchJson('https://api.binance.com/api/v3/ticker/24hr?symbol=BTCUSDT');
    $b2 = fetchJson('https://api.binance.com/api/v3/ticker/24hr?symbol=ETHUSDT');
    if ($b1) {
        $r['btc']    = round((float)($b1['lastPrice'] ?? 0), 2) ?: null;
        $r['btc_24h']= round((float)($b1['priceChangePercent'] ?? 0), 2);
    }
    if ($b2) {
        $r['eth']    = round((float)($b2['lastPrice'] ?? 0), 2) ?: null;
        $r['eth_24h']= round((float)($b2['priceChangePercent'] ?? 0), 2);
    }
    $r['source'] = 'binance';
    echo "[" . date('H:i:s') . "] Crypto OK (Binance fallback): BTC={$r['btc']}\n";
    return $r;
}

function fetchForex(): array {
    $r = ['usd_pln'=>null,'usd_eur'=>null,'usd_gbp'=>null,'usd_chf'=>null,'source'=>null];

    $d = fetchJson('https://api.frankfurter.app/latest?from=USD&to=PLN,EUR,GBP,CHF');
    if ($d && isset($d['rates']['PLN'])) {
        $r = [
            'usd_pln' => round($d['rates']['PLN'], 4),
            'usd_eur' => round($d['rates']['EUR'], 4),
            'usd_gbp' => round($d['rates']['GBP'], 4),
            'usd_chf' => round($d['rates']['CHF'] ?? 0, 4) ?: null,
            'source'  => 'frankfurter',
        ];
        echo "[" . date('H:i:s') . "] Forex OK (Frankfurter): PLN={$r['usd_pln']}\n";
        return $r;
    }

    $d2 = fetchJson('https://open.er-api.com/v6/latest/USD');
    if ($d2 && isset($d2['rates']['PLN'])) {
        $r = [
            'usd_pln' => round($d2['rates']['PLN'], 4),
            'usd_eur' => round($d2['rates']['EUR'], 4),
            'usd_gbp' => round($d2['rates']['GBP'], 4),
            'usd_chf' => round($d2['rates']['CHF'] ?? 0, 4) ?: null,
            'source'  => 'open.er-api',
        ];
        echo "[" . date('H:i:s') . "] Forex OK (open.er-api fallback): PLN={$r['usd_pln']}\n";
    }
    return $r;
}

function fetchMetals(): array {
    $r = ['gold'=>null,'silver'=>null,'source'=>null];

    $d = fetchJson('https://api.metals.live/v1/spot/gold,silver');
    if (is_array($d)) {
        foreach ($d as $entry) {
            if (isset($entry['gold']))   $r['gold']   = round($entry['gold'], 2);
            if (isset($entry['silver'])) $r['silver'] = round($entry['silver'], 2);
        }
    }
    if ($r['gold']) {
        $r['source'] = 'metals.live';
        echo "[" . date('H:i:s') . "] Metals OK: XAU={$r['gold']}\n";
        return $r;
    }

    // Fallback: frankfurter XAU
    $d2 = fetchJson('https://api.frankfurter.app/latest?from=XAU&to=USD');
    if ($d2 && isset($d2['rates']['USD'])) {
        $r['gold']   = round($d2['rates']['USD'], 2);
        $r['source'] = 'frankfurter-xau';
        echo "[" . date('H:i:s') . "] Metals OK (Frankfurter XAU fallback): XAU={$r['gold']}\n";
    }
    return $r;
}

// ─── RSS PARSER ────────────────────────────────────────────────────────────

function parseRss(string $url, int $limit = 5): array {
    $raw = fetchRaw($url, 12, 'application/rss+xml, application/xml, text/xml');
    if (!$raw) return [];

    // Wyłącz błędy XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    libxml_clear_errors();
    if (!$xml) return [];

    $items = [];
    $nodes = $xml->channel->item ?? $xml->entry ?? [];

    foreach ($nodes as $item) {
        if (count($items) >= $limit) break;

        // Obsługa Atom vs RSS
        $title = (string)($item->title ?? '');
        $link  = (string)($item->link ?? $item->id ?? '');
        if (!$link && isset($item->link['href'])) $link = (string)$item->link['href'];
        $pubDate = (string)($item->pubDate ?? $item->published ?? $item->updated ?? '');
        $desc  = (string)($item->description ?? $item->summary ?? '');

        // Wyczyść HTML z opisu
        $desc = strip_tags($desc);
        $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $desc = mb_substr(trim($desc), 0, 160);
        if (mb_strlen($desc) === 160) $desc .= '…';

        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!$title || !$link) continue;

        // Parsuj datę na timestamp
        $ts = 0;
        if ($pubDate) {
            $ts = @strtotime($pubDate) ?: 0;
        }

        $items[] = [
            'title'   => $title,
            'link'    => $link,
            'desc'    => $desc,
            'ts'      => $ts,
            'date'    => $ts ? date('d.m H:i', $ts) : '',
        ];
    }

    return $items;
}

function fetchAllNews(): array {
    $feeds = [
        // AI / Tech
        ['id'=>'anthropic',  'label'=>'Anthropic Blog',    'cat'=>'ai',    'url'=>'https://www.anthropic.com/rss.xml'],
        ['id'=>'openai',     'label'=>'OpenAI Blog',       'cat'=>'ai',    'url'=>'https://openai.com/blog/rss.xml'],
        ['id'=>'googledev',  'label'=>'Google AI Blog',    'cat'=>'ai',    'url'=>'https://blog.google/technology/ai/rss/'],
        ['id'=>'verge_ai',   'label'=>'The Verge · AI',    'cat'=>'ai',    'url'=>'https://www.theverge.com/ai-artificial-intelligence/rss/index.xml'],
        ['id'=>'ars_tech',   'label'=>'Ars Technica',      'cat'=>'tech',  'url'=>'https://feeds.arstechnica.com/arstechnica/technology-lab'],
        // Świat / Finanse
        ['id'=>'bbc_world',  'label'=>'BBC World',         'cat'=>'world', 'url'=>'https://feeds.bbci.co.uk/news/world/rss.xml'],
        ['id'=>'reuters',    'label'=>'Reuters Business',  'cat'=>'world', 'url'=>'https://feeds.reuters.com/reuters/businessNews'],
        ['id'=>'bloomberg',  'label'=>'Bloomberg Markets', 'cat'=>'world', 'url'=>'https://feeds.bloomberg.com/markets/news.rss'],
        // Polska
        ['id'=>'tvn24',      'label'=>'TVN24',             'cat'=>'pl',    'url'=>'https://tvn24.pl/najnowsze.xml'],
        ['id'=>'wp',         'label'=>'WP Wiadomości',     'cat'=>'pl',    'url'=>'https://wiadomosci.wp.pl/rss.xml'],
        ['id'=>'bankier',    'label'=>'Bankier.pl',        'cat'=>'pl',    'url'=>'https://www.bankier.pl/rss/wiadomosci.xml'],
    ];

    $result = [];
    foreach ($feeds as $feed) {
        echo "[" . date('H:i:s') . "] RSS: {$feed['label']}…\n";
        $items = parseRss($feed['url'], NEWS_PER_FEED);
        $result[] = [
            'id'     => $feed['id'],
            'label'  => $feed['label'],
            'cat'    => $feed['cat'],
            'items'  => $items,
            'count'  => count($items),
            'ok'     => count($items) > 0,
        ];
        // Małe opóźnienie żeby nie blokować
        usleep(200000); // 200ms
    }
    return $result;
}

// ─── ZBIERZ WSZYSTKO I ZAPISZ ──────────────────────────────────────────────

$now    = new DateTimeImmutable('now', new DateTimeZone(TIMEZONE));
$cutoff = new DateTimeImmutable(KNOWLEDGE_CUTOFF);
$daysOld = (int)(($now->getTimestamp() - $cutoff->getTimestamp()) / 86400);

$crypto = fetchCrypto();
$forex  = fetchForex();
$metals = fetchMetals();
$news   = fetchAllNews();

// Volatility alerts
$alerts = [];
$checks = [
    ['BTC/USD',  $crypto['btc_24h'] ?? null, 5],
    ['ETH/USD',  $crypto['eth_24h'] ?? null, 5],
];
foreach ($checks as [$name, $ch, $threshold]) {
    if ($ch !== null && abs($ch) >= $threshold) {
        $alerts[] = [
            'asset'   => $name,
            'change'  => $ch,
            'label'   => abs($ch) >= 10 ? 'EXTREME' : 'HIGH',
        ];
    }
}

// Data quality
$marketSources = array_filter([
    $crypto['source'] ?? null,
    $forex['source']  ?? null,
    $metals['source'] ?? null,
]);
$dataQuality = [
    'market_sources'  => array_values($marketSources),
    'news_feeds_ok'   => count(array_filter($news, fn($f) => $f['ok'])),
    'news_feeds_total'=> count($news),
    'generated_at'    => $now->format('c'),
    'generated_unix'  => $now->getTimestamp(),
];

$cache = [
    'meta' => [
        'generated'        => $now->format('c'),
        'generated_unix'   => $now->getTimestamp(),
        'timezone'         => TIMEZONE,
        'cutoff'           => KNOWLEDGE_CUTOFF,
        'days_since_cutoff'=> $daysOld,
        'quality'          => $dataQuality,
        'alerts'           => $alerts,
    ],
    'market' => array_merge($crypto, $forex, $metals),
    'news'   => $news,
];

$written = file_put_contents(
    CACHE_FILE,
    json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

if ($written) {
    echo "[" . date('H:i:s') . "] Cache zapisany: " . round($written/1024, 1) . " KB → " . CACHE_FILE . "\n";
} else {
    echo "[" . date('H:i:s') . "] BŁĄD: nie można zapisać " . CACHE_FILE . "\n";
}

// Usuń lock
@unlink(CACHE_LOCK);
echo "[" . date('H:i:s') . "] Gotowe.\n";
