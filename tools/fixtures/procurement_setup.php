<?php
// Disposable DB only. Never run against an application schema.
if(PHP_SAPI!=='cli')exit(1);
$dsn=getenv('EMCORE_DB_DSN');
if(!preg_match('/^mysql:host=127\.0\.0\.1;port=33379;dbname=emcore_procurement_fixture;charset=utf8mb4$/',(string)$dsn))throw new RuntimeException('Isolated fixture DSN required.');
$admin=new PDO('mysql:host=127.0.0.1;port=33379;charset=utf8mb4',getenv('EMCORE_DB_USER'),getenv('EMCORE_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE IF NOT EXISTS emcore_procurement_fixture CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db=new PDO($dsn,getenv('EMCORE_DB_USER'),getenv('EMCORE_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($db->query('SHOW TABLES')->fetch())throw new RuntimeException('Fixture DB must be empty; no existing database is dropped automatically.');
$db->exec("CREATE TABLE USERS (USR_UID CHAR(32) PRIMARY KEY,USR_USERNAME VARCHAR(255),USR_FIRSTNAME VARCHAR(255),USR_LASTNAME VARCHAR(255),USR_ROLE VARCHAR(32),USR_STATUS VARCHAR(16));
    CREATE TABLE emcore_modules (module_key VARCHAR(64) PRIMARY KEY,name_fa VARCHAR(255),name_en VARCHAR(255),sort_order INT,is_active TINYINT DEFAULT 1);
    CREATE TABLE emcore_user_permissions (usr_uid CHAR(32),module_key VARCHAR(64),can_create TINYINT,can_read TINYINT,can_update TINYINT,can_delete TINYINT,granted_by CHAR(32),PRIMARY KEY(usr_uid,module_key));
    CREATE TABLE TASK (TAS_UID CHAR(32) PRIMARY KEY,PRO_UID CHAR(32));
    CREATE TABLE APPLICATION (APP_UID CHAR(32) PRIMARY KEY,PRO_UID CHAR(32),APP_STATUS VARCHAR(32));
    CREATE TABLE APP_DELEGATION (APP_UID CHAR(32),DEL_INDEX INT,TAS_UID CHAR(32),USR_UID CHAR(32),DEL_THREAD_STATUS VARCHAR(16),DEL_FINISH_DATE DATETIME DEFAULT NULL,PRIMARY KEY(APP_UID,DEL_INDEX));
    CREATE FUNCTION shamsi_slash_to_gregorian_date(input_fa VARCHAR(10)) RETURNS DATE DETERMINISTIC RETURN DATE('2026-09-22');");
foreach(['002_emcore_audit_log.sql','010_emcore_procurement_notices.sql','011_emcore_procurement_classification.sql','012_emcore_procurement_workflow.sql'] as $file) {
    $db->exec(file_get_contents(__DIR__.'/../../database/migrations/'.$file));
}
foreach(['operator'=>str_repeat('1',32),'manager'=>str_repeat('2',32),'outsider'=>str_repeat('7',32)] as $name=>$uid){
    $db->prepare("INSERT INTO USERS VALUES (:uid,:name,:name,'آزمایشی','PROCESSMAKER_OPERATOR','ACTIVE')")->execute([':uid'=>$uid,':name'=>$name]);
    // Outsider deliberately has full CRUD permissions: role/scope must deny it.
    $db->prepare("INSERT INTO emcore_user_permissions VALUES (:uid,'procurement_notices',1,1,1,1,:grant)")->execute([':uid'=>$uid,':grant'=>$uid]);
}
foreach(['4','5','6'] as $digit)$db->prepare('INSERT INTO TASK VALUES (:task,:process)')->execute([':task'=>str_repeat($digit,32),':process'=>str_repeat('3',32)]);
$db->prepare("INSERT INTO emcore_procurement_notices (notice_type,title,participation_status,created_by_usr_uid,updated_by_usr_uid,owner_usr_uid,manager_usr_uid)
    VALUES ('tender','سابقهٔ بسته','won',:historical,:historical2,:owner,:manager)")->execute([':historical'=>str_repeat('9',32),':historical2'=>str_repeat('9',32),':owner'=>str_repeat('1',32),':manager'=>str_repeat('2',32)]);
$db->prepare("INSERT INTO emcore_procurement_notices (notice_type,title,created_by_usr_uid,updated_by_usr_uid,owner_usr_uid,manager_usr_uid)
    VALUES ('tender','خارج از دامنه',:owner,:owner2,:owner3,:manager)")->execute([':owner'=>str_repeat('7',32),':owner2'=>str_repeat('7',32),':owner3'=>str_repeat('7',32),':manager'=>str_repeat('2',32)]);
echo "Disposable procurement schema and PM fixtures initialized.\n";
