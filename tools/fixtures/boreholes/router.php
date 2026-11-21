<?php

if (PHP_SAPI !== 'cli-server' || getenv('EMCORE_BOREHOLES_FIXTURE') !== '1'
    || !preg_match('/;dbname=emcore_boreholes_fixture(?:;|$)/', (string)getenv('EMCORE_DB_DSN'))
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 3);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/panel') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>گمانه‌ها</title>';
    readfile($root . '/panels/emcore_boreholes_panel.html');
    exit;
}
$allowed = ['/emcore_api/emcore_boreholes.php', '/emcore_api/emcore_drilling_reports.php',
    '/emcore_api/emcore_drilling_analytics.php', '/emcore_api/emcore_audit_log_api.php'];
if (!in_array($path, $allowed, true)) {
    http_response_code(404);
    exit;
}

session_start();
$actor = $_SERVER['HTTP_X_BOREHOLE_FIXTURE_ACTOR'] ?? 'admin';
if ($actor === 'admin') {
    $_SESSION['USER_LOGGED'] = '00000000000000000000000000000001';
} elseif ($actor === 'reader') {
    $_SESSION['USER_LOGGED'] = str_repeat('2', 32);
} else {
    unset($_SESSION['USER_LOGGED']);
}
require $root . $path;
