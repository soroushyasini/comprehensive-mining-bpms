<?php
if(PHP_SAPI!=='cli')exit(1);
define('EMCORE_NATIVE_CONTEXT',true);require __DIR__.'/../emcore_api/_mine_history_domain.php';
$checks=0;
foreach(['1399/12/30'=>'2021-03-20','1400/01/01'=>'2021-03-21','1403/12/30'=>'2025-03-20','1404/01/01'=>'2025-03-21','1405/06/31'=>'2026-09-22','1405/07/01'=>'2026-09-23','۱۴۰۵/۰۷/۱۲'=>'2026-10-04','١٤٠٥/٠٧/١٢'=>'2026-10-04'] as $fa=>$iso){
 if(emcore_mh_date($fa,'2026-11-04')!==[emcore_mh_digits($fa),$iso])throw new RuntimeException('Date mismatch: '.$fa);$checks++;
}
foreach(['1404/12/30','1405/07/31','1405/00/01','1405/01/00','1406/01/01','','bad-date'] as $fa){
 try{emcore_mh_date($fa,'2026-11-04');}catch(EmcoreHttpException $e){if($e->status===422){$checks++;continue;}throw $e;}throw new RuntimeException('Invalid or future date accepted');
}
if(emcore_mh_category_key(' پيگيري  گمانه‌ها ')!==emcore_mh_category_key('پیگیری گمانه ها'))throw new RuntimeException('Category normalization mismatch');$checks++;
foreach([[],"bad\x00text",str_repeat('م',5001)] as $value){try{emcore_mh_text($value,5000,true);}catch(EmcoreHttpException $e){$checks++;continue;}throw new RuntimeException('Unsafe text accepted');}
echo 'Mine history domain: '.$checks.' checks passed without SQL.'.PHP_EOL;
