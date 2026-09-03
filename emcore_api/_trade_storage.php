<?php

require_once __DIR__ . '/_module_permissions.php';

function emcore_trade_storage_settings()
{
    static $settings;
    if ($settings !== null) {
        return $settings;
    }
    $localFile = __DIR__ . '/emcore_config.php';
    $local = file_exists($localFile) ? require $localFile : [];
    if (!is_array($local)) {
        throw new RuntimeException('Invalid EMCORE local configuration.');
    }
    $rootFromEnvironment = getenv('EMCORE_TRADE_STORAGE_ROOT');
    $maxFromEnvironment = getenv('EMCORE_TRADE_MAX_UPLOAD_BYTES');
    $settings = [
        'root' => $rootFromEnvironment !== false && trim($rootFromEnvironment) !== ''
            ? trim($rootFromEnvironment)
            : (isset($local['trade_storage_root']) ? trim((string)$local['trade_storage_root']) : ''),
        'max_bytes' => $maxFromEnvironment !== false && trim($maxFromEnvironment) !== ''
            ? (int)$maxFromEnvironment
            : (isset($local['trade_max_upload_bytes']) ? (int)$local['trade_max_upload_bytes'] : 52428800),
    ];
    return $settings;
}

function emcore_trade_storage_root()
{
    static $root;
    if ($root !== null) {
        return $root;
    }
    $settings = emcore_trade_storage_settings();
    $configured = $settings['root'];
    if ($configured === '') {
        throw new RuntimeException('EMCORE trade storage configuration is missing.');
    }
    if (!is_dir($configured) || !is_writable($configured)) {
        throw new RuntimeException('EMCORE trade storage directory is unavailable or not writable.');
    }
    $resolved = realpath($configured);
    if ($resolved === false) {
        throw new RuntimeException('Unable to resolve EMCORE trade storage directory.');
    }
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    if ($documentRoot !== false) {
        $storageComparable = strtolower(rtrim($resolved, "\\/"));
        $webComparable = strtolower(rtrim($documentRoot, "\\/"));
        if ($storageComparable === $webComparable
            || strpos($storageComparable, $webComparable . DIRECTORY_SEPARATOR) === 0) {
            throw new RuntimeException('EMCORE trade storage must be outside the web root.');
        }
    }
    $root = $resolved;
    return $root;
}

function emcore_trade_storage_ready()
{
    try {
        emcore_trade_storage_root();
        return true;
    } catch (Throwable $exception) {
        return false;
    }
}

function emcore_trade_max_upload_bytes()
{
    $value = emcore_trade_storage_settings()['max_bytes'];
    return $value > 0 ? $value : 52428800;
}

function emcore_trade_allowed_extensions($kind)
{
    $sets = [
        'template' => ['doc', 'docx'],
        'document' => ['doc', 'docx', 'pdf'],
        'attachment' => ['doc', 'docx', 'pdf', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'],
    ];
    if (!isset($sets[$kind])) {
        throw new RuntimeException('Unknown EMCORE trade upload kind.');
    }
    return $sets[$kind];
}

function emcore_trade_allowed_mimes($extension)
{
    $map = [
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
        'pdf' => ['application/pdf', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    ];
    return isset($map[$extension]) ? $map[$extension] : [];
}

function emcore_trade_clean_original_filename($name)
{
    $name = str_replace(["\r", "\n", "\0"], '', basename((string)$name));
    $name = trim($name);
    if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
        throw new EmcoreHttpException(422, 'نام فایل نامعتبر است');
    }
    return $name;
}

function emcore_trade_store_upload($fieldName, $kind, $relativeDirectory)
{
    if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
        throw new EmcoreHttpException(422, 'فایل الزامی است', [$fieldName => 'required']);
    }
    $upload = $_FILES[$fieldName];
    if (!isset($upload['error']) || (int)$upload['error'] !== UPLOAD_ERR_OK) {
        throw new EmcoreHttpException(422, 'بارگذاری فایل کامل نشد', [$fieldName => 'upload_failed']);
    }
    $size = isset($upload['size']) ? (int)$upload['size'] : 0;
    if ($size <= 0 || $size > emcore_trade_max_upload_bytes()) {
        throw new EmcoreHttpException(422, 'اندازه فایل مجاز نیست', [$fieldName => 'invalid_size']);
    }
    $temporaryPath = isset($upload['tmp_name']) ? (string)$upload['tmp_name'] : '';
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new EmcoreHttpException(422, 'فایل بارگذاری‌شده معتبر نیست');
    }
    $originalName = emcore_trade_clean_original_filename(isset($upload['name']) ? $upload['name'] : '');
    $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, emcore_trade_allowed_extensions($kind), true)) {
        throw new EmcoreHttpException(422, 'نوع فایل مجاز نیست', [$fieldName => 'extension_not_allowed']);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($temporaryPath);
    if (!in_array($mimeType, emcore_trade_allowed_mimes($extension), true)) {
        throw new EmcoreHttpException(422, 'محتوای فایل با پسوند آن سازگار نیست', [$fieldName => 'mime_mismatch']);
    }
    if (!preg_match('/^[A-Za-z0-9_\/-]+$/', $relativeDirectory)) {
        throw new RuntimeException('Unsafe EMCORE trade storage directory.');
    }
    $relativeDirectory = trim(str_replace('\\', '/', $relativeDirectory), '/');
    $absoluteDirectory = emcore_trade_storage_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0770, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('Unable to create EMCORE trade storage directory.');
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $absolutePath = $absoluteDirectory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
        throw new RuntimeException('Unable to store EMCORE trade upload.');
    }
    $hash = hash_file('sha256', $absolutePath);
    if ($hash === false) {
        @unlink($absolutePath);
        throw new RuntimeException('Unable to hash EMCORE trade upload.');
    }
    return [
        'original_filename' => $originalName,
        'stored_filename' => $storedName,
        'storage_path' => $relativeDirectory . '/' . $storedName,
        'absolute_path' => $absolutePath,
        'extension' => $extension,
        'mime_type' => $mimeType,
        'file_size' => $size,
        'sha256' => $hash,
    ];
}

function emcore_trade_remove_failed_upload($file)
{
    if (is_array($file) && !empty($file['absolute_path']) && is_file($file['absolute_path'])) {
        @unlink($file['absolute_path']);
    }
}

function emcore_trade_absolute_path($relativePath)
{
    $relativePath = str_replace('\\', '/', (string)$relativePath);
    if ($relativePath === '' || strpos($relativePath, '..') !== false || $relativePath[0] === '/') {
        throw new RuntimeException('Invalid EMCORE trade storage path.');
    }
    $candidate = emcore_trade_storage_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $resolved = realpath($candidate);
    $root = realpath(emcore_trade_storage_root());
    if ($resolved === false || $root === false || !is_file($resolved)) {
        throw new EmcoreHttpException(404, 'فایل یافت نشد');
    }
    $resolvedComparable = strtolower($resolved);
    $rootComparable = strtolower(rtrim($root, "\\/")) . DIRECTORY_SEPARATOR;
    if (strpos($resolvedComparable, $rootComparable) !== 0) {
        throw new RuntimeException('EMCORE trade file escaped its storage root.');
    }
    return $resolved;
}

function emcore_trade_send_download($row, $kind)
{
    $path = emcore_trade_absolute_path($row['storage_path']);
    $actor = emcore_current_user();
    $stmt = emcore_db()->prepare(
        "INSERT INTO emcore_trade_download_log
            (actor_usr_uid, file_kind, file_id, original_filename, ip_address, user_agent)
         VALUES
            (:actor_usr_uid, :file_kind, :file_id, :original_filename, :ip_address, :user_agent)"
    );
    $stmt->execute([
        ':actor_usr_uid' => $actor['USR_UID'],
        ':file_kind' => $kind,
        ':file_id' => $row['id'],
        ':original_filename' => $row['original_filename'],
        ':ip_address' => isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null,
        ':user_agent' => isset($_SERVER['HTTP_USER_AGENT'])
            ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255, 'UTF-8')
            : null,
    ]);
    $safeAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $row['original_filename']);
    if ($safeAscii === '' || $safeAscii === null) {
        $safeAscii = 'download.' . $row['extension'];
    }
    header('Content-Type: ' . $row['mime_type'], true);
    header('Content-Length: ' . filesize($path), true);
    header('Content-Disposition: attachment; filename="' . $safeAscii
        . '"; filename*=UTF-8\'\'' . rawurlencode($row['original_filename']), true);
    header('X-Content-Type-Options: nosniff', true);
    header('Cache-Control: private, no-store', true);
    readfile($path);
    exit;
}
