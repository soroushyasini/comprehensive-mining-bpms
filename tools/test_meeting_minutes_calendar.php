<?php
// Standalone regression: date conversion must never use a database connection.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('EMCORE_NATIVE_CONTEXT', true);
require_once __DIR__.'/../emcore_api/_minutes_domain.php';
$checks=0;
$vectors=[
    '1399/12/30'=>'2021-03-20','1400/01/01'=>'2021-03-21',
    '1403/12/30'=>'2025-03-20','1404/01/01'=>'2025-03-21',
    '1404/12/29'=>'2026-03-20','1405/01/01'=>'2026-03-21',
    '1405/06/31'=>'2026-09-22','1405/07/01'=>'2026-09-23',
    '1405/07/12'=>'2026-10-04','1406/01/01'=>'2027-03-21',
    '۱۴۰۵/۰۷/۱۲'=>'2026-10-04','١٤٠٥/٠٧/١٢'=>'2026-10-04'
];
foreach ($vectors as $fa=>$expected) {
    // stdClass has no prepare() method: any SQL dependency makes the test fail.
    list($normalized,$actual)=emcore_minutes_date(new stdClass(),$fa);
    if ($actual!==$expected || $normalized!==emcore_minutes_digits($fa)) throw new RuntimeException('Calendar vector failed: '.$fa);
    $checks++;
}
foreach (['1404/12/30','1405/07/31','1405/00/01','1405/01/00','1405/13/01','bad-date'] as $fa) {
    try { emcore_minutes_date(new stdClass(),$fa); }
    catch (EmcoreHttpException $e) { if ($e->status===422) { $checks++; continue; } throw $e; }
    throw new RuntimeException('Invalid date accepted: '.$fa);
}
if (emcore_minutes_date(new stdClass(),'')!==[null,null]) throw new RuntimeException('Unknown date changed');
$checks++;
echo 'Meeting minutes calendar: '.$checks.' checks passed without SQL.'.PHP_EOL;
