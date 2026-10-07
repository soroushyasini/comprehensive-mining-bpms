<?php
require __DIR__.'/guard.php';
bc_fixture_guard();
require __DIR__.'/../../../emcore_api/_business_cards_storage.php';
$manifest=json_decode(file_get_contents($argv[1]??'dataset/business_cards_manifest.json'),true);
$record=&$manifest['records'][0];
$s=emcore_db()->prepare('SELECT s.content_hash,c.lock_version,c.notes FROM emcore_business_card_source_records s JOIN emcore_business_cards c ON c.id=s.card_id WHERE s.source_key=?');
$s->execute([$record['source_key']]);$before=$s->fetch();
$record['raw_text'].="\nFixture-only source revision\n";
$record['content_hash']=hash('sha256',$record['raw_text']."\n".($record['image_sha256']??''));
$temporary=tempnam(sys_get_temp_dir(),'bc-changed-source-');
try {
    file_put_contents($temporary,json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $process=proc_open([PHP_BINARY,'-d','memory_limit=512M','tools/import_business_cards.php','--manifest='.$temporary,'--source-root=cart','--actor=00000000000000000000000000000001'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process))throw new RuntimeException('Cannot start fixture importer');
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);
    $counts=json_decode($output,true);
    if ($exit!==1 || $counts['changed']!==1 || $counts['skipped']!==442 || $counts['new']!==0 || $counts['failed']!==0)throw new RuntimeException('Changed source must be reported without reimport');
    $s->execute([$record['source_key']]);
    if ($s->fetch()!==$before)throw new RuntimeException('Changed source modified user data or provenance');
    echo "Changed-source check passed: one revision reported, zero writes, user data and provenance unchanged.\n";
} finally {if (is_file($temporary))unlink($temporary);}
