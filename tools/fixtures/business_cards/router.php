<?php
require __DIR__.'/guard.php';bc_fixture_guard();$repo=dirname(__DIR__,3);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/panel') {
    header('Content-Type: text/html; charset=utf-8');echo str_replace('<script src="/emcore_assets/emcore-ui.js">','<script src="/fixture-jquery.js"></script><script src="/emcore_assets/emcore-ui.js">',file_get_contents($repo.'/panels/emcore_business_cards_panel.html'));exit;
}
if($path==='/fixture-jquery.js'){header('Content-Type: application/javascript');readfile('/fixture-runtime/jquery.js');exit;}
if(preg_match('~^/emcore_assets/(emcore-ui\.js|emcore-ui\.css|business-cards\.js|business-cards\.css|fonts/Vazirmatn-(?:Regular|Medium|SemiBold|Bold)\.ttf)$~',$path,$m)) {
    $ext=pathinfo($m[1],PATHINFO_EXTENSION);header('Content-Type: '.($ext==='css'?'text/css':($ext==='ttf'?'font/ttf':'application/javascript')));readfile($repo.'/emcore_assets/'.$m[1]);exit;
}
if($path!=='/emcore_api/emcore_business_cards.php'){http_response_code(404);exit;}
session_start();$names=['admin','reader','creator','outsider','inactive','editor','deleter'];
$name=$_SERVER['HTTP_X_BC_FIXTURE_ACTOR']??$_COOKIE['bc_fixture_actor']??'admin';$i=array_search($name,$names,true);
if($i===false)unset($_SESSION['USER_LOGGED']);else $_SESSION['USER_LOGGED']=$i===0?'00000000000000000000000000000001':str_repeat((string)$i,32);
require $repo.'/emcore_api/emcore_business_cards.php';
