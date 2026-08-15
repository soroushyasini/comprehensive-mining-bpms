<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

class EmcoreHttpException extends RuntimeException
{
    public $status;
    public $details;

    public function __construct($status, $message, $details = null)
    {
        parent::__construct($message);
        $this->status = (int)$status;
        $this->details = $details;
    }
}

function emcore_json($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function ($exception) {
    if ($exception instanceof EmcoreHttpException) {
        $payload = ['success' => false, 'error' => $exception->getMessage()];
        if ($exception->details !== null) {
            $payload['details'] = $exception->details;
        }
        emcore_json($payload, $exception->status);
    }

    error_log('EMCORE API error: ' . $exception->getMessage());
    emcore_json(['success' => false, 'error' => 'خطای داخلی سرور'], 500);
});

function emcore_config()
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    $localFile = __DIR__ . '/emcore_config.php';
    $local = file_exists($localFile) ? require $localFile : [];
    if (!is_array($local)) {
        throw new RuntimeException('Invalid EMCORE local configuration.');
    }

    $config = [
        'db_dsn' => getenv('EMCORE_DB_DSN') ?: (isset($local['db_dsn']) ? $local['db_dsn'] : ''),
        'db_user' => getenv('EMCORE_DB_USER') ?: (isset($local['db_user']) ? $local['db_user'] : ''),
        'db_password' => getenv('EMCORE_DB_PASSWORD') !== false
            ? getenv('EMCORE_DB_PASSWORD')
            : (isset($local['db_password']) ? $local['db_password'] : ''),
        'session_name' => getenv('EMCORE_SESSION_NAME') ?: (isset($local['session_name']) ? $local['session_name'] : ''),
    ];

    if ($config['db_dsn'] === '' || $config['db_user'] === '') {
        throw new RuntimeException('EMCORE database configuration is missing.');
    }

    return $config;
}

function emcore_db()
{
    static $db;
    if ($db instanceof PDO) {
        return $db;
    }

    $config = emcore_config();
    $db = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $db;
}

function emcore_start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $config = emcore_config();
    if ($config['session_name'] !== '') {
        session_name($config['session_name']);
    }
    session_start();
}

function emcore_current_user()
{
    static $user;
    if ($user !== null) {
        return $user;
    }

    emcore_start_session();
    $usrUid = isset($_SESSION['USER_LOGGED']) ? (string)$_SESSION['USER_LOGGED'] : '';
    if (!preg_match('/^[A-Za-z0-9]{32}$/', $usrUid)) {
        throw new EmcoreHttpException(401, 'ورود به سامانه الزامی است');
    }

    $stmt = emcore_db()->prepare(
        "SELECT USR_UID, USR_USERNAME, USR_FIRSTNAME, USR_LASTNAME, USR_ROLE
         FROM USERS WHERE USR_UID = :usr_uid AND USR_STATUS = 'ACTIVE' LIMIT 1"
    );
    $stmt->execute([':usr_uid' => $usrUid]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new EmcoreHttpException(401, 'کاربر فعال یافت نشد');
    }
    return $user;
}

function emcore_require_permission($moduleKey, $capability)
{
    $columns = [
        'create' => 'can_create',
        'read' => 'can_read',
        'update' => 'can_update',
        'delete' => 'can_delete',
    ];
    if (!isset($columns[$capability])) {
        throw new RuntimeException('Unknown EMCORE capability.');
    }

    $user = emcore_current_user();
    $sql = "SELECT " . $columns[$capability] . " AS allowed
            FROM emcore_user_permissions
            WHERE usr_uid = :usr_uid AND module_key = :module_key LIMIT 1";
    $stmt = emcore_db()->prepare($sql);
    $stmt->execute([':usr_uid' => $user['USR_UID'], ':module_key' => $moduleKey]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['allowed'] !== 1) {
        throw new EmcoreHttpException(403, 'دسترسی کافی ندارید');
    }
    return $user;
}

function emcore_csrf_token()
{
    emcore_start_session();
    if (empty($_SESSION['EMCORE_CSRF_TOKEN'])) {
        $_SESSION['EMCORE_CSRF_TOKEN'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['EMCORE_CSRF_TOKEN'];
}

function emcore_require_csrf()
{
    $provided = isset($_SERVER['HTTP_X_CSRF_TOKEN'])
        ? (string)$_SERVER['HTTP_X_CSRF_TOKEN']
        : (isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '');
    if ($provided === '' || !hash_equals(emcore_csrf_token(), $provided)) {
        throw new EmcoreHttpException(403, 'توکن امنیتی نامعتبر است');
    }
}

function emcore_action($allowed)
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new EmcoreHttpException(405, 'فقط درخواست POST مجاز است');
    }
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    if (!in_array($action, $allowed, true)) {
        throw new EmcoreHttpException(400, 'action نامعتبر');
    }
    return $action;
}

function emcore_positive_id($name)
{
    $value = filter_input(INPUT_POST, $name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value === false || $value === null) {
        throw new EmcoreHttpException(422, 'شناسه نامعتبر است', [$name => 'positive_integer_required']);
    }
    return (int)$value;
}

function emcore_string($name, $required = false, $maxLength = null)
{
    $value = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
    if ($required && $value === '') {
        throw new EmcoreHttpException(422, 'اطلاعات ورودی نامعتبر است', [$name => 'required']);
    }
    if ($maxLength !== null && mb_strlen($value, 'UTF-8') > $maxLength) {
        throw new EmcoreHttpException(422, 'اطلاعات ورودی نامعتبر است', [$name => 'too_long']);
    }
    return $value === '' ? null : $value;
}

function emcore_post_bool($name)
{
    $value = isset($_POST[$name]) ? strtolower(trim((string)$_POST[$name])) : '0';
    if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
        return 1;
    }
    if (in_array($value, ['0', 'false', 'off', 'no', ''], true)) {
        return 0;
    }
    throw new EmcoreHttpException(422, 'مقدار بله/خیر نامعتبر است', [$name => 'boolean_required']);
}
