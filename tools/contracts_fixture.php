<?php
// Test router and installer for an isolated PHP server and disposable database.
$dsn=getenv('EMCORE_DB_DSN') ?: '';
if (getenv('EMCORE_CONTRACTS_FIXTURE')!=='1' || !preg_match('/;dbname=emcore_contracts_fixture(?:;|$)/',$dsn)) {
    http_response_code(404);
    exit(1);
}
$repo=dirname(__DIR__);
$actors=['admin'=>str_repeat('1',32),'reader'=>str_repeat('2',32),'editor'=>str_repeat('3',32),'outsider'=>str_repeat('4',32),'creator'=>str_repeat('5',32)];
if (PHP_SAPI==='cli') {
    $db=new PDO($dsn,getenv('EMCORE_DB_USER'),getenv('EMCORE_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($db->query('SHOW TABLES')->fetch()) throw new RuntimeException('Fixture schema must be empty');
    $db->exec("CREATE TABLE USERS(USR_UID CHAR(32) PRIMARY KEY,USR_USERNAME VARCHAR(100),USR_FIRSTNAME VARCHAR(100),USR_LASTNAME VARCHAR(100),USR_ROLE VARCHAR(32),USR_STATUS VARCHAR(16)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        CREATE TABLE emcore_companies(id INT UNSIGNED PRIMARY KEY,name_fa VARCHAR(200),national_id VARCHAR(50),registration_number VARCHAR(50),deleted_at DATETIME NULL);
        CREATE TABLE emcore_persons(id INT UNSIGNED PRIMARY KEY,first_name VARCHAR(200),last_name VARCHAR(200),national_id VARCHAR(50),address VARCHAR(500),deleted_at DATETIME NULL);
        CREATE TABLE emcore_mines(id INT UNSIGNED PRIMARY KEY,mine_name VARCHAR(200),deleted_at DATETIME NULL);
        CREATE TABLE fixture_flags(id INT PRIMARY KEY,fail_audit TINYINT NOT NULL);
        INSERT INTO fixture_flags VALUES(1,0);
        INSERT INTO emcore_companies(id,name_fa) VALUES(1,'شرکت آزمایشی');
        INSERT INTO emcore_persons(id,first_name,last_name) VALUES(1,'کاربر','آزمایشی');
        INSERT INTO emcore_mines(id,mine_name) VALUES(1,'معدن آزمایشی')");
    foreach ($actors as $name=>$uid) $db->prepare('INSERT INTO USERS VALUES(?,?,?,?,?,?)')->execute([$uid,$name,$name,'آزمایشی','OPERATOR','ACTIVE']);
    foreach (['001_emcore_authorization.sql','002_emcore_audit_log.sql','024_emcore_contracts.sql','024_emcore_contracts.sql','025_emcore_contract_history_files.sql','025_emcore_contract_history_files.sql'] as $file) {
        $db->exec(file_get_contents($repo.'/database/migrations/'.$file));
    }
    if ($db->query("SELECT COUNT(*) FROM emcore_user_permissions WHERE module_key='contracts'")->fetchColumn()!=0) throw new RuntimeException('Unexpected permission grant');
    foreach (['admin'=>[1,1,1,1],'reader'=>[0,1,0,0],'editor'=>[0,1,1,0],'creator'=>[1,1,0,0]] as $name=>$caps) {
        $db->prepare("INSERT INTO emcore_user_permissions(usr_uid,module_key,can_create,can_read,can_update,can_delete) VALUES(?,'contracts',?,?,?,?)")
            ->execute(array_merge([$actors[$name]],$caps));
    }
    $db->exec("CREATE TRIGGER fixture_ct_audit BEFORE INSERT ON emcore_audit_log FOR EACH ROW BEGIN
        IF NEW.module_key='contracts' AND (SELECT fail_audit FROM fixture_flags WHERE id=1)=1
        THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture audit failure'; END IF; END");
    echo "Contracts fixture initialized; migration applied twice.\n";
    exit;
}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/panel') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><script src="/fixture-jquery.js"></script>';
    readfile($repo.'/panels/emcore_contracts_panel.html');
    exit;
}
if ($path==='/fixture-jquery.js') {
    header('Content-Type: application/javascript');
    readfile(getenv('EMCORE_FIXTURE_JQUERY'));
    exit;
}
if (preg_match('~^/emcore_assets/((?:emcore-ui|contracts)\.(js|css)|fonts/Vazirmatn-(Regular|Medium|SemiBold|Bold)\.ttf)$~',$path)) {
    $extension=pathinfo($path,PATHINFO_EXTENSION);
    header('Content-Type: '.($extension==='css'?'text/css':($extension==='ttf'?'font/ttf':'application/javascript')));
    readfile($repo.$path);
    exit;
}
// Asset misses (notably favicon) must not race the API's first session cookie.
if (!in_array($path,['/fixture/audit-failure','/emcore_api/emcore_contracts.php'],true)) {
    http_response_code(404);
    exit;
}
session_start();
$actor=$_SERVER['HTTP_X_CONTRACT_FIXTURE_ACTOR'] ?? $_COOKIE['contract_actor'] ?? 'admin';
if (isset($actors[$actor])) $_SESSION['USER_LOGGED']=$actors[$actor];
else unset($_SESSION['USER_LOGGED']);
if ($path==='/fixture/audit-failure' && $actor==='admin') {
    require $repo.'/emcore_api/_bootstrap.php';
    emcore_db()->prepare('UPDATE fixture_flags SET fail_audit=? WHERE id=1')->execute([($_POST['enabled']??'0')==='1'?1:0]);
    echo '{}';
    exit;
}
if ($path==='/emcore_api/emcore_contracts.php') require $repo.$path;
else http_response_code(404);
