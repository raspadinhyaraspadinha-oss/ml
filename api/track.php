<?php
/**
 * Page View Tracking Endpoint
 *
 * Records page views from a frontend pixel/beacon request.
 * Returns a 1x1 transparent GIF so it can be loaded as an <img> tag.
 *
 * Usage: <img src="/api/track.php?page=/checkout&ref=https://google.com&utm_source=google" />
 */

require_once __DIR__ . '/config.php';

// Override the JSON Content-Type set by config.php — we return a GIF pixel
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// 1x1 transparent GIF pixel (43 bytes)
$pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

// Collect parameters from GET request
$record = [
    'page'         => isset($_GET['page'])         ? substr($_GET['page'], 0, 2048)         : '',
    'referrer'     => isset($_GET['ref'])           ? substr($_GET['ref'], 0, 2048)          : '',
    'utm_source'   => isset($_GET['utm_source'])    ? substr($_GET['utm_source'], 0, 255)    : '',
    'utm_medium'   => isset($_GET['utm_medium'])    ? substr($_GET['utm_medium'], 0, 255)    : '',
    'utm_campaign' => isset($_GET['utm_campaign'])  ? substr($_GET['utm_campaign'], 0, 255)  : '',
    'utm_content'  => isset($_GET['utm_content'])   ? substr($_GET['utm_content'], 0, 255)   : '',
    'utm_term'     => isset($_GET['utm_term'])      ? substr($_GET['utm_term'], 0, 255)      : '',
    'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent'   => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : '',
    'timestamp'    => date('c'),
    'country'      => '',
];

// Try to detect country from Cloudflare or other proxy headers (optional)
if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
    $record['country'] = $_SERVER['HTTP_CF_IPCOUNTRY'];
} elseif (!empty($_SERVER['HTTP_X_COUNTRY_CODE'])) {
    $record['country'] = $_SERVER['HTTP_X_COUNTRY_CODE'];
}

// Skip recording if no page was provided
if ($record['page'] === '') {
    echo $pixel;
    exit;
}

// --- Write to pageviews.json with file locking ---
$file = DATA_DIR . 'pageviews.json';
$maxRecords = 10000;

$fp = fopen($file, 'c+');
if ($fp && flock($fp, LOCK_EX)) {
    $contents = '';
    $size = filesize($file);
    if ($size > 0) {
        $contents = fread($fp, $size);
    }

    $data = ($contents !== '' && $contents !== false) ? json_decode($contents, true) : [];
    if (!is_array($data)) {
        $data = [];
    }

    // Append new record
    $data[] = $record;

    // Trim to last N records to prevent file bloat
    if (count($data) > $maxRecords) {
        $data = array_slice($data, -$maxRecords);
    }

    // Rewrite file
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
} elseif ($fp) {
    fclose($fp);
}

// Output the transparent pixel
echo $pixel;
exit;
