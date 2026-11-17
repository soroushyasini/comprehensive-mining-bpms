<?php
// Read-only verification. Configure a restored database and its matching private storage first.
if (PHP_SAPI!=='cli') { http_response_code(404); exit(1); }
define('EMCORE_NATIVE_CONTEXT',true);
require dirname(__DIR__).'/emcore_api/_contracts_storage.php';

try {
    $expected = $argv[1] ?? null;
    if ($expected!==null && !preg_match('/^\d+$/D',$expected)) throw new RuntimeException('Expected source count must be numeric');
    $versions = ct_all('SELECT id,storage_key,sha256,file_size FROM emcore_contract_versions ORDER BY id');
    $sources = ct_all('SELECT id,doc_id,status,storage_key,sha256,version_id FROM emcore_contract_sources ORDER BY id');
    $versionsById = array_column($versions,null,'id');
    $errors = [];
    $seen = [];
    foreach ([['version',$versions],['source',$sources]] as [$kind,$rows]) {
        foreach ($rows as $r) {
            if (!empty($r['storage_key'])) {
                $file=ct_private_path($r['storage_key'],false);
                $seen[$r['storage_key']]=true;
                if (!$file || hash_file('sha256',$file)!==$r['sha256'] || (isset($r['file_size']) && (int)$r['file_size']!==filesize($file))) {
                    $errors[]=['kind'=>$kind,'id'=>$r['id'],'issue'=>'missing_or_checksum_mismatch'];
                }
            }
            if ($kind==='source' && $r['status']==='imported'
                && (!isset($versionsById[$r['version_id']]) || $versionsById[$r['version_id']]['sha256']!==$r['sha256'])) {
                $errors[]=['kind'=>'source','id'=>$r['doc_id'],'issue'=>'invalid_version_mapping'];
            }
        }
    }
    if ($expected!==null && count($sources)!==(int)$expected) $errors[]=['issue'=>'unexpected_source_count'];
    $broken=ct_one("SELECT COUNT(*) AS total FROM emcore_contract_documents d
        LEFT JOIN emcore_contract_versions v ON v.id=d.current_version_id AND v.document_id=d.id AND v.deleted_at IS NULL
        WHERE d.current_version_id IS NOT NULL AND v.id IS NULL");
    if ((int)$broken['total']) $errors[]=['issue'=>'broken_current_versions','count'=>$broken['total']];
    $broken=ct_one("SELECT COUNT(*) AS total FROM emcore_contracts c JOIN emcore_contract_versions v ON v.id=c.basis_version_id
        JOIN emcore_contract_documents d ON d.id=v.document_id WHERE c.merged_into IS NULL AND (d.contract_id<>c.id OR v.deleted_at IS NOT NULL)");
    if ((int)$broken['total']) $errors[]=['issue'=>'broken_contract_basis','count'=>$broken['total']];
    if (ct_one("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emcore_contract_history_files'")) {
        $broken=ct_one("SELECT COUNT(*) AS total FROM emcore_contract_history_files f
            LEFT JOIN emcore_contract_history h ON h.id=f.history_id
            LEFT JOIN emcore_contract_versions v ON v.id=f.version_id
            LEFT JOIN emcore_contract_documents d ON d.id=v.document_id
            WHERE f.deleted_at IS NULL AND (h.id IS NULL OR v.id IS NULL OR d.id IS NULL
                OR h.contract_id<>d.contract_id OR v.deleted_at IS NOT NULL)");
        if ((int)$broken['total']) $errors[]=['issue'=>'broken_history_files','count'=>$broken['total']];
    }
    $orphans = 0;
    foreach (glob(ct_root().'/objects/*') ?: [] as $file) {
        if (is_file($file) && !isset($seen['objects/'.basename($file)])) $orphans++;
    }
    if ($orphans) $errors[]=['issue'=>'orphan_objects','count'=>$orphans];
    echo ct_json(['versions'=>count($versions),'sources'=>count($sources),'objects'=>count($seen),'errors'=>$errors]).PHP_EOL;
    exit($errors ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR,'Archive verification could not run: '.$e->getMessage().PHP_EOL);
    exit(1);
}
