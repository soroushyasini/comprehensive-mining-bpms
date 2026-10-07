<?php
require_once __DIR__.'/_business_cards_domain.php';

function emcore_bc_storage_settings() {
    static $settings;
    if ($settings!==null) return $settings;
    $local=file_exists(__DIR__.'/emcore_config.php') ? require __DIR__.'/emcore_config.php' : [];
    if (!is_array($local)) throw new RuntimeException('Invalid EMCORE local configuration');
    $max=getenv('EMCORE_BUSINESS_CARDS_MAX_UPLOAD_BYTES');
    $settings=['root'=>getenv('EMCORE_BUSINESS_CARDS_STORAGE_ROOT')?:($local['business_cards_storage_root']??''),
        'max_bytes'=>$max!==false?(int)$max:(int)($local['business_cards_max_upload_bytes']??10485760)];
    if ($settings['max_bytes']<1) throw new RuntimeException('Invalid business-card upload limit');
    return $settings;
}
function emcore_bc_storage_root() {
    $root=realpath(emcore_bc_storage_settings()['root']);
    if (!$root || !is_dir($root) || !is_writable($root)) throw new RuntimeException('Business-card private storage unavailable');
    $web=isset($_SERVER['DOCUMENT_ROOT'])?realpath($_SERVER['DOCUMENT_ROOT']):false;
    if ($web && (strcasecmp($root,$web)===0 || stripos($root,rtrim($web,'/\\').DIRECTORY_SEPARATOR)===0)) throw new RuntimeException('Business-card storage must be outside web root');
    return $root;
}
function emcore_bc_storage_ready() {
    try {emcore_bc_storage_root();return extension_loaded('gd') && class_exists('finfo');} catch(Throwable $e) {return false;}
}
function emcore_bc_inspect_image($path,$filename) {
    $name=emcore_bc_text(basename(str_replace('\\','/',$filename)),255);
    if (!$name || preg_match('/[\r\n]/',$name)) throw new EmcoreHttpException(422,'نام فایل نامعتبر است');
    $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    $mimes=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];
    if (!isset($mimes[$extension])) throw new EmcoreHttpException(422,'فقط تصویر JPEG یا PNG مجاز است');
    $size=filesize($path);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);$dims=@getimagesize($path);
    if (!$size || $size>emcore_bc_storage_settings()['max_bytes']) throw new EmcoreHttpException(422,'اندازه تصویر مجاز نیست');
    if ($mime!==$mimes[$extension] || !$dims || ($dims['mime']??'')!==$mime || $dims[0]<1 || $dims[1]<1 || $dims[0]*$dims[1]>40000000) throw new EmcoreHttpException(422,'محتوا یا ابعاد تصویر معتبر نیست');
    $limit=ini_get('memory_limit');
    if ($limit && $limit!=='-1') {
        $last=strtolower(substr($limit,-1));$bytes=(int)$limit*($last==='g'?1073741824:($last==='m'?1048576:($last==='k'?1024:1)));
        if ($dims[0]*$dims[1]*9+memory_get_usage(true)+16777216>$bytes) throw new EmcoreHttpException(422,'ابعاد تصویر برای پردازش سرور بزرگ است');
    }
    return ['original_filename'=>$name,'mime_type'=>$mime,'size_bytes'=>$size,'width'=>$dims[0],'height'=>$dims[1],'sha256'=>hash_file('sha256',$path)];
}
function emcore_bc_store_image($path,$filename) {
    $meta=emcore_bc_inspect_image($path,$filename);$root=emcore_bc_storage_root();
    if (!extension_loaded('gd')) throw new RuntimeException('GD is required to validate business-card image content');
    $name=bin2hex(random_bytes(16));$meta['stored_filename']=$name.($meta['mime_type']==='image/png'?'.png':'.jpg');
    $original=$root.DIRECTORY_SEPARATOR.$meta['stored_filename'];
    try {
        if (!copy($path,$original)) throw new RuntimeException('Unable to copy private image');
        $image=$meta['mime_type']==='image/png'?@imagecreatefrompng($path):@imagecreatefromjpeg($path);
        if (!$image) throw new EmcoreHttpException(422,'تصویر بازشدنی نیست');
        imagedestroy($image);return $meta;
    } catch(Throwable $e) {if(is_file($original))unlink($original);throw $e;}
}
function emcore_bc_cleanup_image($meta) {
    if (!$meta) return;
    $root=emcore_bc_storage_root();foreach(['stored_filename'] as $key) if (isset($meta[$key]) && preg_match('/^[a-f0-9]{32}\.(?:jpg|png)$/D',$meta[$key])) {
        $path=$root.DIRECTORY_SEPARATOR.$meta[$key];if(is_file($path))unlink($path);
    }
}
function emcore_bc_serve_image($id,$download) {
    $card=emcore_bc_get($id);$row=emcore_bc_current_file($id);
    if (!$row) throw new EmcoreHttpException(404,'تصویر کارت موجود نیست');
    if (isset($_POST['file_id']) && emcore_bc_int($_POST['file_id'])!==(int)$row['id']) throw new EmcoreHttpException(404,'این تصویر دیگر جاری نیست');
    $filename=$row['stored_filename'];
    if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png)$/D',$filename)) throw new RuntimeException('Invalid private image filename');
    $path=emcore_bc_storage_root().DIRECTORY_SEPARATOR.$filename;
    if (!is_file($path) || !hash_equals($row['sha256'],(string)hash_file('sha256',$path))) throw new EmcoreHttpException(409,'فایل تصویر موجود نیست یا صحت آن تأیید نشد');
    if ($download) emcore_bc_insert('emcore_business_card_download_log',['card_id'=>$id,'file_id'=>$row['id'],'actor_usr_uid'=>emcore_current_user()['USR_UID']]);
    header('Content-Type: '.$row['mime_type']);header('Content-Length: '.filesize($path));
    header("Content-Disposition: ".($download?'attachment':'inline')."; filename=\"card-".$id.($row['mime_type']==='image/png'?'.png':'.jpg')."\"; filename*=UTF-8''".rawurlencode($row['original_filename']));
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();readfile($path);exit;
}
