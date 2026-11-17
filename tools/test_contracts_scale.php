<?php
// Seed and time the large-query path in the contracts fixture only.
if (PHP_SAPI!=='cli' || getenv('EMCORE_CONTRACTS_FIXTURE')!=='1'
    || !preg_match('/;dbname=emcore_contracts_fixture(?:;|$)/',getenv('EMCORE_DB_DSN') ?: '')) exit(1);
define('EMCORE_NATIVE_CONTEXT',true);
require dirname(__DIR__).'/emcore_api/_contracts_queries.php';
$db=emcore_db();
$actor=str_repeat('1',32);
$prefix='CT-SCALE-';
$number='SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
    UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9';
$cases=(int)ct_one("SELECT COUNT(*) AS n FROM emcore_contracts WHERE title LIKE 'CT-SCALE-%'")['n'];
if ($cases===0) {
    $db->beginTransaction();
    try {
        ct_query("INSERT INTO emcore_contracts(title,owner_uid,created_by,updated_by,created_at,updated_at,ends_on,ends_fa)
            SELECT CONCAT(?,d.n,c.n,b.n,a.n),?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(?,INTERVAL a.n DAY),NULL
            FROM ($number) a CROSS JOIN ($number) b CROSS JOIN ($number) c CROSS JOIN ($number) d",
            [$prefix,$actor,$actor,$actor,ct_today()]);
        $category=ct_one('SELECT id FROM emcore_contract_categories WHERE contract_id IS NULL ORDER BY id LIMIT 1')['id'];
        ct_query("INSERT INTO emcore_contract_actions (contract_id,category_id,title,owner_uid,due_on,created_by,updated_by,created_at,updated_at)
            SELECT c.id,?,CONCAT(?,d.n),?,DATE_ADD(?,INTERVAL d.n DAY),?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()
            FROM emcore_contracts c CROSS JOIN ($number) d WHERE c.title LIKE 'CT-SCALE-%'",
            [$category,$prefix,$actor,ct_today(),$actor,$actor]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
$checks=['list'=>static function () { return ct_list(['search'=>'CT-SCALE-']); },'dashboard'=>'ct_dashboard'];
$metrics=[];
foreach ($checks as $label=>$run) {
    $times=[];
    for ($i=0;$i<30;$i++) {
        $start=hrtime(true);
        $r=$run();
        $times[]=(hrtime(true)-$start)/1000000;
        if ($label==='list' && $r['total']!==10000) throw new RuntimeException('Fixture must include 10,000 contracts');
        if ($label==='dashboard' && (int)$r['quality']['open_actions']<100000) throw new RuntimeException('Fixture must include 100,000 open actions');
    }
    sort($times);
    $metrics[$label.'_p95_ms']=round($times[(int)ceil(count($times)*0.95)-1],2);
}
echo ct_json($metrics).PHP_EOL;
exit(max($metrics)<2000 ? 0 : 2);
