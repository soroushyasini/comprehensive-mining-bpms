<?php
// Explicitly isolated test database only; never use application credentials.
if (PHP_SAPI!=='cli' || getenv('EMCORE_MH_FIXTURE')!=='1'
    || !preg_match('/;dbname=emcore_mine_history_fixture(?:;|$)/',getenv('EMCORE_DB_DSN'))) exit(1);
$repo=dirname(__DIR__,3);
$db=new PDO(getenv('EMCORE_DB_DSN'),getenv('EMCORE_DB_USER'),getenv('EMCORE_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($db->query('SHOW TABLES')->fetch())throw new RuntimeException('Fixture schema must be empty');
$db->exec("CREATE TABLE USERS(USR_UID CHAR(32) PRIMARY KEY,USR_USERNAME VARCHAR(100),USR_FIRSTNAME VARCHAR(100),USR_LASTNAME VARCHAR(100),USR_ROLE VARCHAR(32),USR_STATUS VARCHAR(16)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE emcore_companies(id INT UNSIGNED PRIMARY KEY,name_fa VARCHAR(200),is_active TINYINT DEFAULT 1,deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE emcore_persons(id INT UNSIGNED PRIMARY KEY,first_name VARCHAR(200),last_name VARCHAR(200),is_active TINYINT DEFAULT 1,deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE emcore_boreholes(id INT PRIMARY KEY,mine_id INT,deleted_at DATETIME NULL);
CREATE TABLE emcore_mine_technical_managers(id INT PRIMARY KEY,mine_id INT,deleted_at DATETIME NULL);
CREATE TABLE fixture_flags(id INT PRIMARY KEY,fail_audit TINYINT DEFAULT 0); INSERT INTO fixture_flags VALUES(1,0);");
preg_match('/CREATE TABLE emcore_mines \([\s\S]+?;/u',file_get_contents($repo.'/EMCORE_dev_reference_addendum.md'),$m);$db->exec($m[0]);
$names=['admin','reader','creator','editor','deleter','outsider','inactive','no_read'];
foreach($names as $i=>$name)$db->prepare('INSERT INTO USERS VALUES(?,?,?,?,?,?)')->execute([$i===0?'00000000000000000000000000000001':str_repeat((string)$i,32),$name,$name,'آزمایشی','OPERATOR',$name==='inactive'?'INACTIVE':'ACTIVE']);
foreach(['001_emcore_authorization.sql','002_emcore_audit_log.sql','019_emcore_mine_history.sql','019_emcore_mine_history.sql'] as $file)$db->exec(file_get_contents($repo.'/database/migrations/'.$file));
foreach(['admin'=>[1,1,1,1],'reader'=>[0,1,0,0],'creator'=>[1,1,0,0],'editor'=>[0,1,1,0],'deleter'=>[0,1,0,1],'inactive'=>[1,1,1,1],'no_read'=>[1,0,1,1]] as $name=>$caps){
 $i=array_search($name,$names,true);$uid=$i===0?'00000000000000000000000000000001':str_repeat((string)$i,32);
 $db->prepare("INSERT INTO emcore_user_permissions(usr_uid,module_key,can_create,can_read,can_update,can_delete) VALUES(?,'mines',?,?,?,?) ON DUPLICATE KEY UPDATE can_create=VALUES(can_create),can_read=VALUES(can_read),can_update=VALUES(can_update),can_delete=VALUES(can_delete)")->execute(array_merge([$uid],$caps));
}
$db->exec("INSERT INTO emcore_mines(id,mine_name,mineral_type,status) VALUES(1,'تپه سیاه شمالی','مس','فاقد اطلاعات'),(2,'معدن دوم','آهن','فاقد اطلاعات'),(3,'معدن قابل حذف','آهن','فاقد اطلاعات');
CREATE TRIGGER fixture_mh_audit BEFORE INSERT ON emcore_audit_log FOR EACH ROW BEGIN
 IF NEW.module_key='mines' AND (SELECT fail_audit FROM fixture_flags WHERE id=1)=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture audit failure'; END IF; END");
echo "Mine history fixture initialized; migration applied twice.\n";
