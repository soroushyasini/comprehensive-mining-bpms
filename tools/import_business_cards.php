<?php
// CLI-only. Sources and existing user edits are immutable; dry-run is the default.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
define('EMCORE_NATIVE_CONTEXT',true);
require_once __DIR__.'/../emcore_api/_business_cards_storage.php';
$options=getopt('',['manifest:','source-root:','actor:','commit']);
try {
    if (empty($options['manifest']) || empty($options['source-root']) || empty($options['actor'])) throw new RuntimeException('Usage: php tools/import_business_cards.php --manifest=<private-json> --source-root=<cart> --actor=<active-UID> [--commit]');
    $root=realpath($options['source-root']);if(!$root || !is_dir($root))throw new RuntimeException('Source root is unavailable');
    $raw=file_get_contents($options['manifest']);$manifest=json_decode($raw,true);
    if (!is_array($manifest) || ($manifest['format_version']??null)!==1 || !is_array($manifest['records']??null) || count($manifest['records'])!==443) throw new RuntimeException('Expected a version-1 manifest with 443 records');
    emcore_start_session();$_SESSION['USER_LOGGED']=$options['actor'];
    $actor=emcore_require_permission('business_cards','read')['USR_UID'];emcore_require_permission('business_cards','create');emcore_require_permission('business_cards','update');
    if (!emcore_bc_storage_ready())throw new RuntimeException('Private storage, Fileinfo and GD must be available');
    $db=emcore_db();$lock=$db->query("SELECT GET_LOCK('emcore_business_cards_import',0)")->fetchColumn();
    if ((int)$lock!==1) throw new RuntimeException('Another business-card import is running');
    $commit=isset($options['commit']);$counts=['mode'=>$commit?'commit':'dry_run','records'=>443,'images'=>0,'new'=>0,'skipped'=>0,'changed'=>0,'failed'=>0,
        'source_summary'=>$manifest['summary']??null,'similarity_groups'=>count($manifest['similarities']??[])];
    $batch=null;$seen=[];
    if ($commit) $batch=emcore_bc_insert('emcore_business_card_import_batches',['source_hash'=>hash('sha256',$raw),'actor_usr_uid'=>$actor]);
    foreach($manifest['records'] as $record) {
        $stored=null;
        try {
            $key=emcore_bc_text($record['source_key']??null,500);
            if (!$key || isset($seen[$key]))throw new RuntimeException('Missing or duplicate source key');$seen[$key]=true;
            $payload=emcore_bc_payload($record['card']??[],true);
            $sourceText=emcore_bc_text($record['raw_text']??null,1000000);if(!$sourceText)throw new RuntimeException('Source text is required');
            $image=$record['image_relative_path']??null;$imagePath=null;$imageHash='';
            if ($image) {
                $candidate=$root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'],DIRECTORY_SEPARATOR,$image);$imagePath=realpath($candidate);
                if (!$imagePath || stripos($imagePath,rtrim($root,'/\\').DIRECTORY_SEPARATOR)!==0 || !is_file($imagePath))throw new RuntimeException('Image is missing or escapes source root');
                $meta=emcore_bc_inspect_image($imagePath,basename($imagePath));$imageHash=$meta['sha256'];
                if (!hash_equals($record['image_sha256']??'',$imageHash))throw new RuntimeException('Source image hash changed');$counts['images']++;
            }
            $contentHash=hash('sha256',$record['raw_text']."\n".$imageHash);
            if (!hash_equals($record['content_hash']??'',$contentHash))throw new RuntimeException('Source content hash changed');
            $s=$db->prepare('SELECT content_hash FROM emcore_business_card_source_records WHERE source_key=?');$s->execute([$key]);$old=$s->fetch();
            if ($old) {
                if (hash_equals($old['content_hash'],$contentHash))$counts['skipped']++;else {$counts['changed']++;fwrite(STDERR,'Changed source: '.$key."\n");}
                continue;
            }
            if (!$commit) {$counts['new']++;continue;}
            $db->beginTransaction();$id=emcore_bc_create($payload,$actor,'imported');
            if ($imagePath) {
                $stored=emcore_bc_store_image($imagePath,basename($imagePath));
                emcore_bc_insert('emcore_business_card_files',array_merge(['card_id'=>$id],$stored,['request_id'=>bin2hex(random_bytes(16)),'payload_hash'=>$contentHash,'uploaded_by_usr_uid'=>$actor]));
            }
            emcore_bc_insert('emcore_business_card_source_records',['card_id'=>$id,'batch_id'=>$batch,'source_key'=>$key,
                'legacy_source_id'=>emcore_bc_text($record['legacy_source_id']??null,100),'raw_text'=>$record['raw_text'],
                'extracted_data'=>emcore_audit_json($record),'image_relative_path'=>$image,'content_hash'=>$contentHash]);
            emcore_audit('business_cards','create','business_card',$id,null,emcore_bc_get($id),['operation'=>'source_import','batch_id'=>$batch,'source_key'=>$key]);
            $db->commit();$counts['new']++;
        } catch(Throwable $e) {
            if($db->inTransaction())$db->rollBack();if($stored)emcore_bc_cleanup_image($stored);
            $counts['failed']++;fwrite(STDERR,'Import failed for '.($record['source_key']??'unknown').': '.$e->getMessage()."\n");
        }
    }
    if ($commit)$db->prepare('UPDATE emcore_business_card_import_batches SET imported_count=?,skipped_count=?,changed_count=?,failed_count=?,completed_at=NOW() WHERE id=?')->execute([$counts['new'],$counts['skipped'],$counts['changed'],$counts['failed'],$batch]);
    $db->query("SELECT RELEASE_LOCK('emcore_business_cards_import')");
    echo json_encode($counts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    exit($counts['failed'] || $counts['changed'] || $counts['images']!==304 ? 1:0);
} catch(Throwable $e) {fwrite(STDERR,$e->getMessage()."\n");exit(1);}
