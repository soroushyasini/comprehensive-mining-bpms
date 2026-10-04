<?php
require __DIR__.'/guard.php'; minutes_fixture_guard();
if (PHP_SAPI!=='cli') exit(1);
$db=new PDO(getenv('EMCORE_DB_DSN'),getenv('EMCORE_DB_USER'),getenv('EMCORE_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($db->query('SHOW TABLES')->fetch()) throw new RuntimeException('Fixture schema must be empty; no existing data is removed.');
$db->exec("CREATE TABLE USERS (USR_UID CHAR(32) PRIMARY KEY,USR_USERNAME VARCHAR(100),USR_FIRSTNAME VARCHAR(100),USR_LASTNAME VARCHAR(100),USR_ROLE VARCHAR(32),USR_STATUS VARCHAR(16)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE emcore_companies (id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,name_fa VARCHAR(200) NOT NULL,national_id VARCHAR(20),is_active TINYINT DEFAULT 1,deleted_at DATETIME DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE fixture_dates (fa VARCHAR(10) PRIMARY KEY,en DATE) ENGINE=InnoDB;
CREATE FUNCTION shamsi_slash_to_gregorian_date(input_fa VARCHAR(10)) RETURNS DATE DETERMINISTIC READS SQL DATA RETURN (SELECT en FROM fixture_dates WHERE fa=input_fa);
CREATE TABLE fixture_flags (id INT PRIMARY KEY,fail_audit TINYINT DEFAULT 0);
INSERT INTO fixture_flags VALUES (1,0);");
$dates=['1399/12/30'=>'2021-03-20','1400/01/01'=>'2021-03-21','1403/12/30'=>'2025-03-20','1404/01/01'=>'2025-03-21',
    '1404/12/29'=>'2026-03-20','1405/01/01'=>'2026-03-21','1405/07/12'=>'2026-10-04','1405/07/13'=>'2026-10-05','1406/01/01'=>'2027-03-21'];
foreach($dates as $fa=>$en)$db->prepare('INSERT INTO fixture_dates VALUES (?,?)')->execute([$fa,$en]);
$names=['admin','clerk','reader','creator','outsider','inactive','parallel'];
foreach($names as $i=>$name){$uid=$i===0?'00000000000000000000000000000001':str_repeat((string)$i,32);
    $db->prepare('INSERT INTO USERS VALUES (?,?,?,?,?,?)')->execute([$uid,$name,$name,'آزمایشی','PROCESSMAKER_OPERATOR',$name==='inactive'?'INACTIVE':'ACTIVE']);}
$companies=[['سرمایه‌گذاری و توسعه معادن شرق (امیدکو)','10103956371'],['کاوش گستر امین','10103992021'],['معدن مس تپه سیاه سبزوار','10380584031'],['معدن کاران مس میامی','10380617797'],['تهاتر کالای خراسان','10380253266'],['شتابدهنده (میکا)','14010802153'],['Emidco Metal','9009870'],['حفار گستر نائیین',null],['شرکت جدید',null]];
foreach($companies as $c)$db->prepare('INSERT INTO emcore_companies (name_fa,national_id) VALUES (?,?)')->execute($c);
$repo=dirname(__DIR__,3);
foreach(['001_emcore_authorization.sql','002_emcore_audit_log.sql','013_emcore_meeting_minutes.sql'] as $file)$db->exec(file_get_contents($repo.'/database/migrations/'.$file));
foreach(['clerk'=>[1,1,1,0],'reader'=>[0,1,0,0],'creator'=>[1,1,0,0],'inactive'=>[1,1,1,1],'parallel'=>[1,1,1,1]] as $name=>$caps){
    $i=array_search($name,$names,true);$db->prepare('INSERT INTO emcore_user_permissions (usr_uid,module_key,can_create,can_read,can_update,can_delete) VALUES (?,\'meeting_minutes\',?,?,?,?)')->execute(array_merge([str_repeat((string)$i,32)],$caps));}
$db->exec("CREATE TRIGGER fixture_fail_audit BEFORE INSERT ON emcore_audit_log FOR EACH ROW BEGIN
IF NEW.module_key='meeting_minutes' AND (SELECT fail_audit FROM fixture_flags WHERE id=1)=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture audit failure'; END IF; END");
echo "Minutes fixture initialized.\n";
