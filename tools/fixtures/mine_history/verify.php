<?php
if(PHP_SAPI!=='cli' || getenv('EMCORE_MH_FIXTURE')!=='1' || !preg_match('/;dbname=emcore_mine_history_fixture(?:;|$)/',getenv('EMCORE_DB_DSN')))exit(1);
define('EMCORE_NATIVE_CONTEXT',true);require dirname(__DIR__,3).'/emcore_api/_mine_history_storage.php';
$db=emcore_db();
$missing=(int)$db->query("SELECT COUNT(*) FROM emcore_mine_history h WHERE NOT EXISTS(SELECT 1 FROM emcore_audit_log a WHERE a.entity_type='mine_history' AND a.entity_id=h.id AND a.action='create')")->fetchColumn();
if($missing)throw new RuntimeException('A committed history record is missing its creation audit');
if((int)$db->query('SELECT COUNT(*) FROM emcore_mine_history_download_log')->fetchColumn()<1)throw new RuntimeException('Authorized downloads were not logged');
$paths=$db->query('SELECT storage_path FROM emcore_mine_history_files')->fetchAll(PDO::FETCH_COLUMN);$known=array_fill_keys($paths,true);$count=0;
$root=emcore_mh_storage_root();
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){
 if(!$file->isFile())continue;$relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($file->getPathname(),strlen($root)+1));
 if(!isset($known[$relative]))throw new RuntimeException('Uncommitted upload left on disk');$count++;
}
if($count!==count($paths))throw new RuntimeException('A retained attachment is missing from private storage');
echo "Mine history persistence: audit coverage, logged downloads, retained soft-deleted files and no orphan uploads verified.\n";
