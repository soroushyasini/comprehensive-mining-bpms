<?php
require __DIR__.'/guard.php';minutes_fixture_guard();
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$repo=dirname(__DIR__,3);
if($path==='/panel'){
    header('Content-Type: text/html; charset=utf-8');
    echo str_replace('<script src="/emcore_assets/emcore-ui.js">','<script src="/fixture-jquery.js"></script><script src="/emcore_assets/emcore-ui.js">',file_get_contents($repo.'/panels/emcore_meeting_minutes_panel.html'));exit;
}
if($path==='/fixture-jquery.js'){header('Content-Type: application/javascript');readfile('/fixture-runtime/jquery.js');exit;}
if(preg_match('~^/emcore_assets/fonts/(Vazirmatn-(Regular|Medium|SemiBold|Bold)\.ttf)$~',$path,$m)){
    header('Content-Type: font/ttf');readfile($repo.'/emcore_assets/fonts/'.$m[1]);exit;
}
if(preg_match('~^/emcore_assets/(emcore-ui\.js|emcore-ui\.css|meeting-minutes\.js)$~',$path,$m)){
    header('Content-Type: '.(substr($path,-4)==='.css'?'text/css':'application/javascript'));readfile($repo.'/emcore_assets/'.$m[1]);exit;
}
if($path!=='/emcore_api/emcore_meeting_minutes.php'){http_response_code(404);exit;}
session_start();
// Synthetic identity is only allowed in this guarded disposable router.
$names=['admin','clerk','reader','creator','outsider','inactive','parallel'];
$name=$_SERVER['HTTP_X_MINUTES_FIXTURE_ACTOR'] ?? $_COOKIE['minutes_fixture_actor'] ?? 'admin';
$index=array_search($name,$names,true);
if($index===false)unset($_SESSION['USER_LOGGED']);else $_SESSION['USER_LOGGED']=$index===0?'00000000000000000000000000000001':str_repeat((string)$index,32);
require $repo.'/emcore_api/emcore_meeting_minutes.php';
