<?php
if(getenv('EMCORE_MH_FIXTURE')!=='1' || !preg_match('/;dbname=emcore_mine_history_fixture(?:;|$)/',getenv('EMCORE_DB_DSN'))) {http_response_code(404);exit;}
$repo=dirname(__DIR__,3);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/panel') {
 header('Content-Type: text/html; charset=utf-8');
 echo str_replace('<script>','<script src="/fixture-jquery.js"></script><script>',file_get_contents($repo.'/panels/emcore_mines_panel.html'));exit;
}
if($path==='/fixture-jquery.js'){header('Content-Type: application/javascript');readfile(getenv('EMCORE_FIXTURE_JQUERY'));exit;}
if(preg_match('~^/emcore_assets/(emcore-ui\.(js|css)|mine-history\.(js|css)|fonts/Vazirmatn-(Regular|Medium|SemiBold|Bold)\.ttf)$~',$path,$m)){
 $ext=pathinfo($m[1],PATHINFO_EXTENSION);header('Content-Type: '.($ext==='css'?'text/css':($ext==='ttf'?'font/ttf':'application/javascript')));readfile($repo.$path);exit;
}
session_start();$names=['admin','reader','creator','editor','deleter','outsider','inactive','no_read'];
$actor=$_SERVER['HTTP_X_MH_FIXTURE_ACTOR']??$_COOKIE['mh_fixture_actor']??'admin';$i=array_search($actor,$names,true);
if($i===false)unset($_SESSION['USER_LOGGED']);else $_SESSION['USER_LOGGED']=$i===0?'00000000000000000000000000000001':str_repeat((string)$i,32);
if($path==='/fixture/audit-failure'){
 if($actor!=='admin'){http_response_code(403);exit;}
 require $repo.'/emcore_api/_bootstrap.php';emcore_db()->prepare('UPDATE fixture_flags SET fail_audit=? WHERE id=1')->execute([($_POST['enabled']??'0')==='1'?1:0]);echo '{}';exit;
}
if(!in_array($path,['/emcore_api/emcore_mines.php','/emcore_api/emcore_mine_history.php'],true)){http_response_code(404);exit;}
require $repo.$path;
