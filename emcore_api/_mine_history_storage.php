<?php

require_once __DIR__ . '/_mine_history_domain.php';

function emcore_mh_storage_settings()
{
    static $settings;
    if ($settings !== null) return $settings;
    $path = __DIR__ . '/emcore_config.php';
    $local = file_exists($path) ? require $path : [];
    if (!is_array($local)) throw new RuntimeException('Invalid EMCORE configuration.');
    $root = getenv('EMCORE_MINE_HISTORY_STORAGE_ROOT'); $limit = getenv('EMCORE_MINE_HISTORY_MAX_UPLOAD_BYTES');
    $max = $limit !== false ? (int)$limit : (int)($local['mine_history_max_upload_bytes'] ?? 20971520);
    $settings = ['root'=>$root !== false ? trim($root) : trim((string)($local['mine_history_storage_root'] ?? '')),
        'max_bytes'=>$max > 0 ? min($max,20971520) : 20971520];
    return $settings;
}

function emcore_mh_storage_root()
{
    $configured = emcore_mh_storage_settings()['root'];
    $root = $configured !== '' ? realpath($configured) : false;
    if ($root === false || !is_dir($root) || !is_writable($root)) throw new RuntimeException('Mine history private storage is unavailable.');
    $web = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($web !== false && (strcasecmp($root,$web) === 0 || stripos($root,rtrim($web,'/\\').DIRECTORY_SEPARATOR) === 0)) {
        throw new RuntimeException('Mine history private storage must be outside the web root.');
    }
    return $root;
}

function emcore_mh_storage_ready()
{
    try { emcore_mh_storage_root(); return class_exists('finfo') && class_exists('ZipArchive'); } catch (Throwable $e) { return false; }
}

function emcore_mh_extensions($role)
{
    return ['doc','docx','pdf','xls','xlsx','jpg','jpeg','png'];
}

function emcore_mh_validate_upload($role)
{
    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || !isset($f['error']) || !is_scalar($f['error']) || (int)$f['error'] !== UPLOAD_ERR_OK) {
        throw new EmcoreHttpException(422, 'بارگذاری فایل کامل نشد؛ محدودیت سرور یا اتصال را بررسی کنید');
    }
    $tmp = $f['tmp_name'] ?? '';
    if (!is_string($tmp) || !is_uploaded_file($tmp)) throw new EmcoreHttpException(422, 'فایل بارگذاری‌شده معتبر نیست');
    $size = filesize($tmp);
    if ($size === false || $size <= 0 || $size > emcore_mh_storage_settings()['max_bytes']) throw new EmcoreHttpException(422, 'اندازه فایل مجاز نیست');
    $rawName = str_replace('\\','/', (string)($f['name'] ?? ''));
    $name = emcore_mh_text(basename($rawName),255,true);
    if (preg_match('/[\r\n:]/', $name)) throw new EmcoreHttpException(422, 'نام فایل نامعتبر است');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext,emcore_mh_extensions($role),true)) throw new EmcoreHttpException(422, 'نوع فایل برای این بخش مجاز نیست');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $mimes = [
        'pdf'=>['application/pdf'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],
        'doc'=>['application/msword','application/vnd.ms-office','application/x-ole-storage','application/CDFV2'],
        'xls'=>['application/vnd.ms-excel','application/vnd.ms-office','application/x-ole-storage','application/CDFV2'],
        'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],
        'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'],
    ];
    if (!in_array($mime,$mimes[$ext],true)) throw new EmcoreHttpException(422, 'محتوای فایل با پسوند آن سازگار نیست');
    if (in_array($ext,['jpg','jpeg','png'],true) && @getimagesize($tmp) === false) throw new EmcoreHttpException(422, 'تصویر معتبر نیست');
    if ($ext === 'pdf') {
        $head = file_get_contents($tmp,false,null,0,5);
        if ($head !== '%PDF-') throw new EmcoreHttpException(422, 'ساختار PDF معتبر نیست');
    }
    if (in_array($ext,['docx','xlsx'],true)) {
        if (!class_exists('ZipArchive')) throw new EmcoreHttpException(503, 'بررسی فایل Office در سرور در دسترس نیست');
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new EmcoreHttpException(422, 'ساختار فایل فشرده معتبر نیست');
        $valid = ($zip->locateName('[Content_Types].xml') !== false
            && $zip->locateName($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') !== false);
        $zip->close();
        if (!$valid) throw new EmcoreHttpException(422, 'ساختار فایل Office با پسوند آن سازگار نیست');
    }
    return ['temporary_path'=>$tmp,'original_filename'=>$name,'extension'=>$ext,'mime_type'=>$mime,
        'file_size'=>$size,'sha256'=>hash_file('sha256',$tmp)];
}

function emcore_mh_store_upload($file, $historyId)
{
    $relative = 'history/' . $historyId;
    $directory = emcore_mh_storage_root().DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
    if (!is_dir($directory) && !mkdir($directory,0770,true) && !is_dir($directory)) throw new RuntimeException('Unable to create mine history storage directory.');
    // Check containment after mkdir too, to reject pre-existing symlink directories.
    $resolved = realpath($directory); $prefix = rtrim(emcore_mh_storage_root(),'/\\').DIRECTORY_SEPARATOR;
    if ($resolved === false || stripos($resolved,$prefix) !== 0) throw new RuntimeException('Invalid mine history storage directory.');
    $stored = bin2hex(random_bytes(16)).'.'.$file['extension'];
    $absolute = $resolved.DIRECTORY_SEPARATOR.$stored;
    if (!move_uploaded_file($file['temporary_path'],$absolute)) throw new RuntimeException('Unable to store mine history upload.');
    @chmod($absolute,0660);
    $file['stored_filename']=$stored; $file['storage_path']=$relative.'/'.$stored; $file['absolute_path']=$absolute;
    unset($file['temporary_path']); return $file;
}

function emcore_mh_remove_failed_upload($file)
{
    if (is_array($file) && isset($file['absolute_path']) && is_file($file['absolute_path'])) @unlink($file['absolute_path']);
}

function emcore_mh_file_path($relative)
{
    if (!preg_match('~^history/[1-9][0-9]*/[a-f0-9]{32}\.(pdf|jpg|jpeg|png|doc|docx|xls|xlsx)$~',$relative)) throw new RuntimeException('Invalid mine history file path.');
    $root = emcore_mh_storage_root(); $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));
    if ($path === false || !is_file($path)) throw new EmcoreHttpException(404,'فایل در مخزن یافت نشد');
    if (stripos($path,rtrim($root,'/\\').DIRECTORY_SEPARATOR) !== 0) throw new RuntimeException('File escaped mine history storage.');
    return $path;
}
