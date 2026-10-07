<?php
require __DIR__.'/guard.php';bc_fixture_guard();if(PHP_SAPI!=='cli')exit(1);
$db=new PDO(getenv('EMCORE_DB_DSN'),getenv('EMCORE_DB_USER'),getenv('EMCORE_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($db->query('SHOW TABLES')->fetch())throw new RuntimeException('Fixture schema must be empty');
$db->exec("CREATE TABLE USERS(USR_UID CHAR(32) PRIMARY KEY,USR_USERNAME VARCHAR(100),USR_FIRSTNAME VARCHAR(100),USR_LASTNAME VARCHAR(100),USR_ROLE VARCHAR(32),USR_STATUS VARCHAR(16)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fixture_flags(id INT PRIMARY KEY,fail_audit TINYINT DEFAULT 0); INSERT INTO fixture_flags VALUES(1,0);");
$names=['admin','reader','creator','outsider','inactive','editor','deleter'];
foreach($names as $i=>$name)$db->prepare('INSERT INTO USERS VALUES(?,?,?,?,?,?)')->execute([$i===0?'00000000000000000000000000000001':str_repeat((string)$i,32),$name,$name,'آزمایشی','OPERATOR',$name==='inactive'?'INACTIVE':'ACTIVE']);
$repo=dirname(__DIR__,3);
foreach(['001_emcore_authorization.sql','002_emcore_audit_log.sql','014_emcore_business_cards.sql'] as $file)$db->exec(file_get_contents($repo.'/database/migrations/'.$file));
foreach(['admin'=>[1,1,1,1],'reader'=>[0,1,0,0],'creator'=>[1,1,0,0],'inactive'=>[1,1,1,1],'editor'=>[0,1,1,0],'deleter'=>[0,1,0,1]] as $name=>$caps){
    $i=array_search($name,$names,true);$uid=$i===0?'00000000000000000000000000000001':str_repeat((string)$i,32);
    $db->prepare("INSERT INTO emcore_user_permissions(usr_uid,module_key,can_create,can_read,can_update,can_delete) VALUES(?,'business_cards',?,?,?,?)")->execute(array_merge([$uid],$caps));}
$db->exec("CREATE TRIGGER fixture_bc_audit BEFORE INSERT ON emcore_audit_log FOR EACH ROW BEGIN
 IF NEW.module_key='business_cards' AND (SELECT fail_audit FROM fixture_flags WHERE id=1)=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture audit failure'; END IF; END");
echo "Business-card fixture initialized.\n";
