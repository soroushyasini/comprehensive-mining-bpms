<?php
require __DIR__.'/guard.php';
bc_fixture_guard();
require __DIR__.'/../../../emcore_api/_business_cards_storage.php';
$manifest=json_decode(file_get_contents($argv[1]??'dataset/business_cards_manifest.json'),true);
if (!is_array($manifest) || count($manifest['records']??[])!==443) throw new RuntimeException('Invalid fixture source manifest');
$db=emcore_db();$checked=0;$images=0;$coordinates=0;
foreach($manifest['records'] as $record) {
    $s=$db->prepare('SELECT card_id,raw_text,content_hash,extracted_data FROM emcore_business_card_source_records WHERE source_key=?');
    $s->execute([$record['source_key']]);$source=$s->fetch();
    if (!$source || $source['raw_text']!==$record['raw_text'] || $source['content_hash']!==$record['content_hash']) throw new RuntimeException('Source text/hash fidelity failed');
    $extracted=json_decode($source['extracted_data'],true);
    if ($extracted['export_lineage']!==$record['export_lineage']) throw new RuntimeException('CSV provenance fidelity failed');
    $file=emcore_bc_current_file($source['card_id']);
    if ($record['image_sha256']) {
        if (!$file || $file['sha256']!==$record['image_sha256'] || hash_file('sha256',emcore_bc_storage_root().DIRECTORY_SEPARATOR.$file['stored_filename'])!==$record['image_sha256']) throw new RuntimeException('Current original image fidelity failed');
        $images++;
    } elseif ($file) throw new RuntimeException('Image-less source unexpectedly has an image');
    $s=$db->prepare('SELECT latitude,longitude,accuracy,source_note,source_urls FROM emcore_business_card_locations WHERE card_id=? ORDER BY sort_order,id');$s->execute([$source['card_id']]);$locations=$s->fetchAll();
    if (count($locations)!==count($record['card']['locations'])) throw new RuntimeException('Location count fidelity failed');
    foreach($locations as $i=>$location) {
        $expected=$record['card']['locations'][$i];
        foreach(['latitude','longitude'] as $field) {
            if (($location[$field]===null)!==($expected[$field]===null) || ($location[$field]!==null && (float)$location[$field] !== (float)$expected[$field])) throw new RuntimeException('Coordinate fidelity failed');
        }
        if ($location['accuracy']!==$expected['accuracy'] || $location['source_note']!==$expected['source_note'] || json_decode($location['source_urls'],true)!==$expected['source_urls']) throw new RuntimeException('Location provenance fidelity failed');
        if ($location['latitude']!==null)$coordinates++;
    }
    $checked++;
}
if ($images!==304 || $coordinates!==297) throw new RuntimeException('Source coverage failed');
echo "Source fidelity passed: $checked full Markdown blocks, $images original image hashes, $coordinates coordinate pairs and CSV provenance.\n";
