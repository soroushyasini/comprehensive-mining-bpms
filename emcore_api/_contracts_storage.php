<?php
require_once __DIR__.'/_contracts_records.php';

function ct_root()
{
    $configured = getenv('EMCORE_CONTRACTS_STORAGE_ROOT');
    if ($configured === false || trim($configured)==='') {
        $configFile = __DIR__.'/emcore_config.php';
        $local = is_file($configFile) ? require $configFile : [];
        $configured = is_array($local) ? ($local['contracts_storage_root'] ?? null) : null;
    }
    if (!is_string($configured) || trim($configured)==='') ct_fail(503,'مخزن خصوصی قراردادها تنظیم نشده است');
    $root = realpath(trim($configured));
    if (!$root || !is_dir($root) || !is_writable($root)) ct_fail(503,'مخزن خصوصی قراردادها در دسترس نیست');
    $web = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $application = realpath(dirname(__DIR__));
    foreach ([$web,$application] as $public) {
        if ($public && (strcasecmp($root,$public)===0 || stripos($root,rtrim($public,'/\\').DIRECTORY_SEPARATOR)===0)) {
            ct_fail(503,'مخزن باید خارج از مسیر انتشار سامانه باشد');
        }
    }
    return $root;
}

function ct_storage_ready()
{
    try { ct_root(); return class_exists('finfo') && class_exists('ZipArchive'); }
    catch (EmcoreHttpException $e) { return false; }
}

function ct_private_path($relative, $required = true)
{
    $root = ct_root();
    $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative));
    $prefix = $root.DIRECTORY_SEPARATOR;
    if ($path && is_file($path)) {
        $compare = DIRECTORY_SEPARATOR==='\\' ? 'strncasecmp' : 'strncmp';
        if ($compare($path,$prefix,strlen($prefix))===0) return $path;
    }
    if ($required) ct_fail(404,'فایل در مخزن یافت نشد');
    return null;
}

function ct_blob($source,$expectedHash,&$stored)
{
    $root = ct_root();
    $directory = $root.DIRECTORY_SEPARATOR.'objects';
    if (!is_dir($directory) && !mkdir($directory,0770) && !is_dir($directory)) ct_fail(503,'مخزن قابل نوشتن نیست');
    $dir = realpath($directory);
    if ($dir !== $directory || is_link($directory)) ct_fail(503,'مسیر مخزن نامعتبر است');
    $key = 'objects/'.bin2hex(random_bytes(24));
    $destination = $root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$key);
    $in = fopen($source,'rb');
    if (!$in) ct_fail(503,'خواندن فایل ممکن نیست');
    $out = fopen($destination,'xb');
    if (!$out) { fclose($in); ct_fail(503,'نوشتن فایل ممکن نیست'); }
    $stored[] = $destination;
    try { $ok = stream_copy_to_stream($in,$out); }
    finally { fclose($in); fclose($out); }
    @chmod($destination,0660);
    if ($ok === false || hash_file('sha256',$destination)!==$expectedHash) ct_fail(503,'کنترل یکپارچگی نسخهٔ ذخیره‌شده ناموفق بود');
    return $key;
}

function ct_validate_file($path,$filename,$max)
{
    $name = ct_text($filename,255,true);
    if (preg_match('~[\\\\/:\r\n]~',$name)) ct_fail(422,'نام فایل نامعتبر است');
    $extension = strtolower(pathinfo($name,PATHINFO_EXTENSION));
    $mimes = [
        'pdf'=>['application/pdf'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],
        'bmp'=>['image/bmp','image/x-ms-bmp'],
        'doc'=>['application/msword','application/vnd.ms-office','application/x-ole-storage','application/CDFV2'],
        'xls'=>['application/vnd.ms-excel','application/vnd.ms-office','application/x-ole-storage','application/CDFV2'],
        'docx'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],
        'xlsx'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip']
    ];
    $size = filesize($path);
    if (!$size || $size>$max || !isset($mimes[$extension])) ct_fail(422,'اندازه یا قالب فایل مجاز نیست');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime,$mimes[$extension],true)) ct_fail(422,'محتوای فایل با پسوند آن سازگار نیست');
    $header = file_get_contents($path,false,null,0,8);
    if ($extension==='pdf' && substr($header,0,5)!=='%PDF-') ct_fail(422,'ساختار PDF نامعتبر است');
    if (in_array($extension,['doc','xls'],true) && bin2hex($header)!=='d0cf11e0a1b11ae1') ct_fail(422,'ساختار فایل Office نامعتبر است');
    if (strpos($mime,'image/')===0 && @getimagesize($path)===false) ct_fail(422,'ساختار تصویر نامعتبر است');
    if (in_array($extension,['docx','xlsx'],true)) {
        if (!class_exists('ZipArchive')) ct_fail(503,'بررسی پروندهٔ Office در دسترس نیست');
        $zip = new ZipArchive();
        if ($zip->open($path)!==true) ct_fail(422,'ساختار فایل Office نامعتبر است');
        $valid = $zip->locateName('[Content_Types].xml')!==false &&
            $zip->locateName($extension==='docx'?'word/document.xml':'xl/workbook.xml')!==false;
        $zip->close();
        if (!$valid) ct_fail(422,'ساختار Office با پسوند سازگار نیست');
    }
    return ['path'=>$path,'original_filename'=>$name,'file_size'=>$size,'mime_type'=>$mime,'sha256'=>hash_file('sha256',$path)];
}

function ct_upload_file()
{
    $file = $_FILES['file'] ?? [];
    if (($file['error'] ?? null)!==UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        ct_fail(422,'بارگذاری کامل نشد؛ اندازهٔ فایل و اتصال را بررسی کنید');
    }
    return ct_validate_file($file['tmp_name'],$file['name'] ?? null,50*1024*1024);
}

function ct_version_create($document,$contract,$file,$note,&$stored,$extracted = null)
{
    $number = ct_one('SELECT COALESCE(MAX(version_no),0)+1 AS next_version FROM emcore_contract_versions WHERE document_id=?',[$document]);
    $fields = array_intersect_key($file,array_flip(['original_filename','file_size','mime_type','sha256']));
    $fields += ['document_id'=>$document,'version_no'=>$number['next_version'],'change_note'=>$note,
        'storage_key'=>ct_blob($file['path'],$file['sha256'],$stored),'extracted_text'=>$extracted,
        'created_by'=>ct_actor(),'created_at'=>ct_now()];
    $row = ct_create_record('versions',$fields,$contract,false);
    unset($row['extracted_text']);
    return $row;
}

function ct_document_write($action,$input,$contract,$file,&$stored)
{
    $id = $contract['id'];
    if ($action==='document_create' || $action==='document_update') {
        $before = $action==='document_update' ? ct_row('documents',ct_id($input['id'] ?? null),$id,true) : null;
        if ($before) ct_version($before,$input['lock_version'] ?? null);
        $input += $before ?: [];
        $values = ['contract_id'=>$id,'title'=>ct_text($input['title'] ?? null,250,true),
            'role_code'=>ct_enum($input['role_code'] ?? null,['main','draft','amendment','attachment','payment','guarantee','correspondence','identity','minutes','reference','other']),
            'signature_status'=>ct_enum($input['signature_status'] ?? null,['unknown','unsigned','partial','signed'],'unknown')];
        return $before ? ct_change('documents',$before,$values,$id,$action) : ct_create_record('documents',$values,$id);
    }
    $doc = ct_row('documents',ct_id($input['document_id'] ?? null),$id,true);
    ct_version($doc,$input['lock_version'] ?? null);
    if ($action==='upload') {
        $version = ct_version_create($doc['id'],$id,$file,ct_text($input['change_note'] ?? null,500),$stored);
        $values=[];
        if (array_key_exists('make_current',$input)) {
            $selected=ct_enum($input['make_current'],[true,false]);
            if ($selected) $values['current_version_id']=$version['id'];
        }
        $updated = ct_change('documents',$doc,$values,$id,$action);
        return ['version'=>$version,'lock_version'=>$updated['lock_version']];
    }
    $version = ct_version_in_contract($input['version_id'] ?? null,$id,$action==='restore_version');
    if ((string)$version['document_id']!==(string)$doc['id']) ct_fail(404,'نسخه متعلق به این مدرک نیست');
    if ($action==='set_current_version') return ct_change('documents',$doc,['current_version_id'=>$version['id']],$id,$action);
    if ($action==='delete_version') {
        if (ct_history_file_used($version['id']) || $contract['hold_reason'] !== null || (string)$doc['current_version_id']===(string)$version['id']
            || (string)$contract['basis_version_id']===(string)$version['id']
            || ct_one('SELECT id FROM emcore_contract_amendments WHERE basis_version_id=? AND applied_at IS NOT NULL LIMIT 1',[$version['id']])
            || ct_one('SELECT id FROM emcore_contract_evidence WHERE version_id=? LIMIT 1',[$version['id']])) {
            ct_fail(409,'نسخه جاری، استنادی یا تحت منع امحا است');
        }
        $result = ct_delete_record('versions',$version,$input['reason'] ?? null,$id);
    } else {
        if (!$version['deleted_at']) ct_fail(409,'نسخه حذف نشده است');
        $values = ['deleted_at'=>null,'deleted_by'=>null,'delete_reason'=>null];
        ct_patch('versions',$version['id'],$values);
        $result = array_replace($version,$values);
        ct_audit('versions',$version['id'],$version,$result,$id,'restore_version');
    }
    ct_change('documents',$doc,[],$id,$action);
    unset($result['extracted_text']);
    return $result;
}

function ct_log_download($contract,$version,$kind)
{
    ct_insert('downloads',['contract_id'=>$contract,'version_id'=>$version,'actor_uid'=>ct_actor(),'kind'=>$kind,'created_at'=>ct_now()]);
}

function ct_stream($path,$filename,$mime,$inline=false,$temporary=false)
{
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    if ($temporary) register_shutdown_function(static function () use ($path) { if (is_file($path)) unlink($path); });
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($path));
    header('Content-Disposition: '.($inline?'inline':'attachment')."; filename=\"contract-download\"; filename*=UTF-8''".rawurlencode($filename));
    header("Content-Security-Policy: sandbox; default-src 'none'");
    readfile($path);
    exit;
}

function ct_download($input,$contract)
{
    $v = ct_version_in_contract($input['version_id'] ?? null,$contract);
    if (isset($input['document_id']) && (string)ct_id($input['document_id'])!==(string)$v['document_id']) ct_fail(404,'نسخه متعلق به این مدرک نیست');
    $path = ct_private_path($v['storage_key']);
    $inline = !empty($input['preview']) && in_array($v['mime_type'],['application/pdf','image/png','image/jpeg','image/bmp','image/x-ms-bmp'],true);
    ct_log_download($contract,$v['id'],$inline?'preview':'version');
    ct_stream($path,$v['original_filename'],$inline?$v['mime_type']:'application/octet-stream',$inline);
}
