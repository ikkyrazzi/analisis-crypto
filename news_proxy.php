<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Timeout settings
$context = stream_context_create([
    'http' => [
        'timeout' => 6,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    ]
]);

$type = isset($_GET['type']) ? $_GET['type'] : 'news';

// ------------------------------------------------------------
// 1. KLINE PROXY (Bypasses CORS & Indonesia ISP Blocks)
// ------------------------------------------------------------
if ($type === 'kline') {
    $symbol = isset($_GET['symbol']) ? $_GET['symbol'] : 'BTCUSDT';
    $interval = isset($_GET['interval']) ? $_GET['interval'] : '1h';
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 200;

    $bybitIntervalMap = [
        "15m" => "15",
        "1h" => "60",
        "2h" => "120",
        "4h" => "240",
        "1d" => "D"
    ];
    $bybitTf = isset($bybitIntervalMap[$interval]) ? $bybitIntervalMap[$interval] : "60";

    // A. Try Bybit first (highly reliable, no CORS or ISP blocks in Indonesia)
    try {
        $bybitUrl = "https://api.bytick.com/v5/market/kline?category=linear&symbol=" . urlencode($symbol) . "&interval=" . urlencode($bybitTf) . "&limit=" . $limit;
        $bybitRes = @file_get_contents($bybitUrl, false, $context);
        if ($bybitRes) {
            $data = json_decode($bybitRes, true);
            if ($data && isset($data['retCode']) && $data['retCode'] === 0 && isset($data['result']['list'])) {
                $list = $data['result']['list'];
                $reversed = array_reverse($list);
                $normalized = [];
                foreach ($reversed as $c) {
                    $normalized[] = [
                        $c[0], // time (ms)
                        $c[1], // open
                        $c[2], // high
                        $c[3], // low
                        $c[4], // close
                        $c[5]  // volume
                    ];
                }
                echo json_encode([
                    'status' => 'success',
                    'source' => 'bybit',
                    'data' => $normalized
                ]);
                exit;
            }
        }
    } catch (Exception $e) {
        // Silently fall through to Binance
    }

    // B. Try Binance fallback
    try {
        $binanceUrl = "https://fapi.binance.com/fapi/v1/klines?symbol=" . urlencode($symbol) . "&interval=" . urlencode($interval) . "&limit=" . $limit;
        $binanceRes = @file_get_contents($binanceUrl, false, $context);
        if ($binanceRes) {
            $data = json_decode($binanceRes, true);
            if ($data && is_array($data)) {
                echo json_encode([
                    'status' => 'success',
                    'source' => 'binance',
                    'data' => $data
                ]);
                exit;
            }
        }
    } catch (Exception $e) {
        // Fallback
    }

    echo json_encode([
        'status' => 'error',
        'message' => 'Both Bybit and Binance kline fetches failed'
    ]);
    exit;
}

// ------------------------------------------------------------
// 2. CALENDAR PROXY (Bypasses CORS)
// ------------------------------------------------------------
if ($type === 'calendar') {
    try {
        $calendarJson = @file_get_contents('https://nfs.faireconomy.media/ff_calendar_thisweek.json', false, $context);
        if ($calendarJson) {
            echo $calendarJson;
            exit;
        }
    } catch (Exception $e) {
        // Silently continue
    }
    echo json_encode([]);
    exit;
}

// ------------------------------------------------------------
// 3. NEWS PROXY (Bypasses CORS & ISP blocks)
// ------------------------------------------------------------
$newsItems = [];

// Fetch CoinTelegraph RSS
try {
    $ctXml = @file_get_contents('https://cointelegraph.com/rss', false, $context);
    if ($ctXml) {
        $xml = @simplexml_load_string($ctXml);
        if ($xml && isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $pubDate = (string)$item->pubDate;
                $timestamp = strtotime($pubDate);
                $newsItems[] = [
                    'id' => 'ct_' . md5((string)$item->link),
                    'title' => (string)$item->title,
                    'url' => (string)$item->link,
                    'source' => 'CoinTelegraph',
                    'description' => (string)$item->description,
                    'publishedAt' => date('c', $timestamp),
                    'timestamp' => $timestamp
                ];
            }
        }
    }
} catch (Exception $e) {
    // Silently continue
}

// Fetch Yahoo Finance Crypto RSS
try {
    $yfXml = @file_get_contents('https://finance.yahoo.com/rss/crypto', false, $context);
    if ($yfXml) {
        $xml = @simplexml_load_string($yfXml);
        if ($xml && isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $pubDate = (string)$item->pubDate;
                $timestamp = strtotime($pubDate);
                $newsItems[] = [
                    'id' => 'yf_' . md5((string)$item->link),
                    'title' => (string)$item->title,
                    'url' => (string)$item->link,
                    'source' => 'Yahoo Finance',
                    'description' => (string)$item->description,
                    'publishedAt' => date('c', $timestamp),
                    'timestamp' => $timestamp
                ];
            }
        }
    }
} catch (Exception $e) {
    // Silently continue
}

// Sort by timestamp descending
usort($newsItems, function ($a, $b) {
    return $b['timestamp'] - $a['timestamp'];
});

// Remove temporary timestamp
foreach ($newsItems as &$item) {
    unset($item['timestamp']);
}

// Slice to limit results to 40 items
$newsItems = array_slice($newsItems, 0, 40);

echo json_encode([
    'status' => 'success',
    'results' => $newsItems
]);
