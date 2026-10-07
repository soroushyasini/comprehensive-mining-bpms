<?php
require_once __DIR__ . '/_module_permissions.php';

function emcore_bc_text($value, $max = 1000) {
    if ($value === null || $value === '') return null;
    if (!is_scalar($value)) throw new EmcoreHttpException(422, 'مقدار متن نامعتبر است');
    $value = trim((string)$value);
    if (mb_strlen($value, 'UTF-8') > $max || strpos($value, "\0") !== false) throw new EmcoreHttpException(422, 'طول یا محتوای متن نامعتبر است');
    return $value === '' ? null : $value;
}
function emcore_bc_normalize($value) {
    if ($value === null) return null;
    $value = strtr($value, ['ي'=>'ی','ك'=>'ک','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
}
function emcore_bc_int($value, $max = PHP_INT_MAX) {
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D', (string)$value) || strlen((string)$value)>18 || (int)$value>$max) throw new EmcoreHttpException(422, 'شناسه یا عدد نامعتبر است');
    return (int)$value;
}
function emcore_bc_request($value) {
    if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) throw new EmcoreHttpException(422, 'شناسه درخواست نامعتبر است');
    return $value;
}
function emcore_bc_enum($value, $allowed) {
    if (!is_string($value) || !in_array($value, $allowed, true)) throw new EmcoreHttpException(422, 'گزینه انتخاب‌شده نامعتبر است');
    return $value;
}
function emcore_bc_array($value) {
    if (is_string($value)) $value=json_decode($value,true);
    if (!is_array($value) || array_values($value)!==$value || count($value)>400) throw new EmcoreHttpException(422,'فهرست اطلاعات نامعتبر است');
    return $value;
}
function emcore_bc_country($value) {
    $value=emcore_bc_text($value,2);
    if ($value===null) return null;
    $value=strtoupper($value);
    $s=emcore_db()->prepare('SELECT code FROM emcore_business_card_countries WHERE code=?');$s->execute([$value]);
    if (!$s->fetch()) throw new EmcoreHttpException(422,'کشور نامعتبر است');
    return $value;
}
function emcore_bc_point_normalized($kind,$value) {
    $v=emcore_bc_normalize($value);
    if ($v===null || $v==='') return null;
    if ($kind==='email') return filter_var($v,FILTER_VALIDATE_EMAIL) ? $v : null;
    if ($kind==='website') return preg_replace('~^https?://~','',rtrim($v,'/'));
    if (in_array($kind,['phone','mobile','fax'],true)) {
        // Only an unambiguous single number; never concatenate multiple numbers/ranges.
        if (!preg_match('/^\+?[0-9 ()-]+\+?$/D',$v) || preg_match('/-\d{1,2}$/D',$v)) return null;
        $digits=preg_replace('/[^0-9]/','',$v);
        return strlen($digits)>=7 && strlen($digits)<=15 ? $digits : null;
    }
    return $kind==='messenger' ? $v : null;
}
function emcore_bc_payload($input,$import=false,$before=null) {
    $out=[];
    foreach (['contact_name'=>500,'organization_name'=>500,'job_title'=>500,'source_category'=>255,'related_unit'=>255,'activity'=>10000,'notes'=>20000] as $key=>$max)
        $out[$key]=emcore_bc_text($input[$key]??null,$max);
    if (!$out['contact_name'] && !$out['organization_name'] && !$import && !($before && !$before['contact_name'] && !$before['organization_name'])) throw new EmcoreHttpException(422,'نام مخاطب یا سازمان الزامی است');
    $out['business_country_code']=emcore_bc_country($input['business_country_code']??null);
    $out['review_status']=emcore_bc_enum($input['review_status']??'needs_review',['needs_review','reviewed']);
    $out['contact_key']=emcore_bc_normalize($out['contact_name']);$out['organization_key']=emcore_bc_normalize($out['organization_name']);
    $out['contact_points']=[];
    foreach(emcore_bc_array($input['contact_points']??[]) as $index=>$p) {
        if (!is_array($p)) throw new EmcoreHttpException(422,'راه ارتباطی نامعتبر است');
        $kind=emcore_bc_enum($p['kind']??'other',['phone','mobile','fax','email','website','messenger','other']);
        $value=emcore_bc_text($p['raw_value']??null,1000);
        if (!$value) throw new EmcoreHttpException(422,'مقدار راه ارتباطی الزامی است');
        // Historical ambiguous cells may be retained unchanged, but new edits are validated.
        $unchanged=false;
        foreach($before['contact_points']??[] as $old) if ($old['kind']===$kind && $old['raw_value']===$value) $unchanged=true;
        if (!$import && !$unchanged && $kind==='email' && !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new EmcoreHttpException(422,'ایمیل نامعتبر است');
        if (!$import && !$unchanged && $kind==='website' && !filter_var(preg_match('~^https?://~i',$value)?$value:'https://'.$value,FILTER_VALIDATE_URL)) throw new EmcoreHttpException(422,'وب‌سایت نامعتبر است');
        $out['contact_points'][]=['kind'=>$kind,'label'=>emcore_bc_text($p['label']??null,255),'raw_value'=>$value,'normalized_value'=>emcore_bc_point_normalized($kind,$value),'sort_order'=>$index];
    }
    $out['locations']=[];
    foreach(emcore_bc_array($input['locations']??[]) as $index=>$l) {
        if (!is_array($l)) throw new EmcoreHttpException(422,'نشانی نامعتبر است');
        $loc=[];
        foreach(['label'=>255,'region_name'=>255,'city'=>255,'address'=>20000,'postal_code'=>100,'source_note'=>10000] as $key=>$max) $loc[$key]=emcore_bc_text($l[$key]??null,$max);
        $loc['country_code']=emcore_bc_country($l['country_code']??null);
        foreach(['latitude'=>90,'longitude'=>180] as $key=>$max) {
            $v=emcore_bc_text($l[$key]??null,30);
            if ($v!==null && (!preg_match('/^-?\d{1,3}(?:\.\d{1,7})?$/D',$v) || abs((float)$v)>$max)) throw new EmcoreHttpException(422,'مختصات جغرافیایی نامعتبر است');
            $loc[$key]=$v;
        }
        if (($loc['latitude']===null)!==($loc['longitude']===null)) throw new EmcoreHttpException(422,'عرض و طول جغرافیایی باید با هم وارد شوند');
        $loc['accuracy']=emcore_bc_enum($l['accuracy']??'unknown',['address','street','city','unknown']);
        $urls=$l['source_urls']??[];
        if (is_string($urls)) $urls=json_decode($urls,true);
        $urls=emcore_bc_array($urls);
        foreach($urls as $url) if (!is_string($url) || strlen($url)>2000 || !preg_match('~^https?://~i',$url) || !filter_var($url,FILTER_VALIDATE_URL)) throw new EmcoreHttpException(422,'منبع موقعیت نامعتبر است');
        $loc['source_urls']=json_encode($urls,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$loc['sort_order']=$index;
        $out['locations'][]=$loc;
    }
    return $out;
}
function emcore_bc_hash($value) { return hash('sha256',emcore_audit_json($value)); }
function emcore_bc_insert($table,$values) {
    $keys=array_keys($values);
    $s=emcore_db()->prepare('INSERT INTO '.$table.' ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')');
    $s->execute(array_values($values)); return (int)emcore_db()->lastInsertId();
}
function emcore_bc_children($id,$payload) {
    $db=emcore_db();
    foreach(['contact_points','locations'] as $kind) {
        $db->prepare('DELETE FROM emcore_business_card_'.$kind.' WHERE card_id=?')->execute([$id]);
        foreach($payload[$kind] as $row) emcore_bc_insert('emcore_business_card_'.$kind,array_merge(['card_id'=>$id],$row));
    }
}
function emcore_bc_file_public($row) {
    if (!$row) return null;
    return array_intersect_key($row,array_flip(['id','card_id','original_filename','mime_type','size_bytes','width','height','sha256','created_at']));
}
function emcore_bc_current_file($id) {
    $s=emcore_db()->prepare('SELECT * FROM emcore_business_card_files WHERE current_card_id=?');$s->execute([$id]);return $s->fetch();
}
function emcore_bc_get($id,$lock=false) {
    $s=emcore_db()->prepare('SELECT * FROM emcore_business_cards WHERE id=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''));$s->execute([$id]);$row=$s->fetch();
    if (!$row) throw new EmcoreHttpException(404,'کارت ویزیت یافت نشد');
    unset($row['create_payload_hash'],$row['create_request_id']);
    foreach(['contact_points','locations'] as $kind) {
        $s=emcore_db()->prepare('SELECT * FROM emcore_business_card_'.$kind.' WHERE card_id=? ORDER BY sort_order,id');$s->execute([$id]);$row[$kind]=$s->fetchAll();
        if ($kind==='locations') foreach($row[$kind] as &$loc) $loc['source_urls']=json_decode($loc['source_urls']??'[]',true)??[];
    }
    $row['image']=emcore_bc_file_public(emcore_bc_current_file($id));
    $s=emcore_db()->prepare('SELECT source_key,legacy_source_id,raw_text,created_at FROM emcore_business_card_source_records WHERE card_id=?');$s->execute([$id]);$row['sources']=$s->fetchAll();
    return $row;
}
function emcore_bc_version($row) {
    if ((int)$row['lock_version']!==emcore_bc_int($_POST['lock_version']??null)) throw new EmcoreHttpException(409,'اطلاعات این کارت تغییر کرده است؛ دوباره آن را باز کنید',['current_version'=>(int)$row['lock_version']]);
}
function emcore_bc_touch($id,$actor) {
    emcore_db()->prepare('UPDATE emcore_business_cards SET lock_version=lock_version+1,updated_by_usr_uid=?,updated_at=NOW() WHERE id=?')->execute([$actor,$id]);
}
function emcore_bc_create($payload,$actor,$origin,$request=null,$hash=null) {
    $values=$payload;unset($values['contact_points'],$values['locations']);
    $values=array_merge($values,['record_origin'=>$origin,'created_by_usr_uid'=>$actor,'updated_by_usr_uid'=>$actor,'create_request_id'=>$request,'create_payload_hash'=>$hash]);
    $id=emcore_bc_insert('emcore_business_cards',$values);emcore_bc_children($id,$payload);return $id;
}
