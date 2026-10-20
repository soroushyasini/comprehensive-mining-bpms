<?php
// Load only the pure helper from the trusted endpoint source, not HTTP dispatch.
$source = file_get_contents(__DIR__ . '/../emcore_api/emcore_procurement_notices.php');
$start = strpos($source, 'function emcore_procurement_search_id(');
$end = strpos($source, 'function emcore_procurement_canonical_value(', $start ?: 0);
if ($start === false || $end === false) throw new RuntimeException('Search helper missing.');
eval(substr($source, $start, $end - $start));
$checks = 0;
foreach ([
    ['1588', '1588'], ['۱۵۸۸', '1588'], ['١٥٨٨', '1588'],
    ['۱5٨۸', '1588'], ['۱٬۵۸۸', '1588'], ['1,588', '1588'],
    ['00001588', '1588'], ['۰۰۰۱٬۵۸۸', '1588'],
    ['18446744073709551615', '18446744073709551615'],
    ['۱۸۴۴۶۷۴۴۰۷۳۷۰۹۵۵۱۶۱۵', '18446744073709551615'],
    ['18446744073709551616', null], [str_repeat('9', 100), null],
    ['', null], ['0', null], ['۰۰۰', null], ['-1588', null],
    ['1588.0', null], ['1e3', null], ['1 OR 1=1', null],
    ['شماره 1588', null], ['15 88', null], ["1588\n", null],
] as [$input, $expected]) {
    ++$checks;
    if (emcore_procurement_search_id($input) !== $expected) throw new RuntimeException('Search normalization failed for ' . $input);
}
echo "Procurement ID search: {$checks} assertions passed.\n";
