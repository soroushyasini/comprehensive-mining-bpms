<?php
require_once __DIR__.'/_business_cards_storage.php';
$action=emcore_action(['lookups','list','get','duplicate_candidates','preview_image','download_image','create','update','upload_image','replace_image','delete_image','delete']);
$reads=['lookups','list','get','duplicate_candidates','preview_image','download_image'];
$cap=in_array($action,$reads,true)?'read':($action==='create'?'create':(in_array($action,['delete','delete_image'],true)?'delete':'update'));
$actor=emcore_require_permission('business_cards',$cap)['USR_UID'];$db=emcore_db();

if ($action==='lookups') {
    emcore_json(['success'=>true,'data'=>['countries'=>$db->query('SELECT code,name_fa,name_en FROM emcore_business_card_countries ORDER BY sort_order,name_fa')->fetchAll(),
        'units'=>$db->query("SELECT DISTINCT related_unit FROM emcore_business_cards WHERE deleted_at IS NULL AND related_unit IS NOT NULL ORDER BY related_unit")->fetchAll(PDO::FETCH_COLUMN),
        'storage_ready'=>emcore_bc_storage_ready(),'max_upload_bytes'=>emcore_bc_storage_settings()['max_bytes']],
        'permissions'=>emcore_module_permissions('business_cards'),'csrf_token'=>emcore_csrf_token()]);
}
if ($action==='preview_image' || $action==='download_image') {
    emcore_bc_enum($_POST['variant']??'original',['original']);
    emcore_bc_serve_image(emcore_bc_int($_POST['id']??null),$action==='download_image');
}
if ($action==='get') emcore_json(['success'=>true,'data'=>emcore_bc_get(emcore_bc_int($_POST['id']??null))]);

if ($action==='list') {
    $where=['c.deleted_at IS NULL'];$params=[];
    foreach(['business_country_code','related_unit','review_status','record_origin'] as $key) if (isset($_POST[$key]) && $_POST[$key]!=='') {
        $v=$key==='business_country_code'?emcore_bc_country($_POST[$key]):($key==='review_status'?emcore_bc_enum($_POST[$key],['needs_review','reviewed']):($key==='record_origin'?emcore_bc_enum($_POST[$key],['imported','manual']):emcore_bc_text($_POST[$key],255)));
        $where[]='c.'.$key.'=?';$params[]=$v;
    }
    foreach(['location_country'=>'country_code','city'=>'city'] as $key=>$column) if (isset($_POST[$key]) && $_POST[$key]!=='') {
        $where[]='EXISTS(SELECT 1 FROM emcore_business_card_locations loc WHERE loc.card_id=c.id AND loc.'.$column.'=?)';$params[]=emcore_bc_text($_POST[$key],255);
    }
    foreach(['has_image'=>"EXISTS(SELECT 1 FROM emcore_business_card_files fi WHERE fi.current_card_id=c.id)",
        'has_coordinates'=>"EXISTS(SELECT 1 FROM emcore_business_card_locations geo WHERE geo.card_id=c.id AND geo.latitude IS NOT NULL)"] as $key=>$expr) if (isset($_POST[$key]) && $_POST[$key]!=='') {
        $v=emcore_bc_enum((string)$_POST[$key],['0','1']);$where[]=($v==='0'?'NOT ':'').$expr;
    }
    if (isset($_POST['search']) && $_POST['search']!=='') {
        $search=emcore_bc_text($_POST['search'],500);$normalized=emcore_bc_normalize($search);
        $where[]="(c.contact_key LIKE ? OR c.organization_key LIKE ? OR c.job_title LIKE ? OR c.notes LIKE ? OR EXISTS(SELECT 1 FROM emcore_business_card_contact_points cp WHERE cp.card_id=c.id AND (cp.raw_value LIKE ? OR cp.normalized_value LIKE ?)) OR EXISTS(SELECT 1 FROM emcore_business_card_locations l WHERE l.card_id=c.id AND (l.address LIKE ? OR l.city LIKE ?)))";
        $params=array_merge($params,['%'.$normalized.'%','%'.$normalized.'%','%'.$search.'%','%'.$search.'%','%'.$search.'%','%'.$normalized.'%','%'.$search.'%','%'.$search.'%']);
    }
    $scope=implode(' AND ',$where);
    $sql="SELECT COUNT(*) total,COALESCE(SUM(EXISTS(SELECT 1 FROM emcore_business_card_files f WHERE f.current_card_id=c.id)),0) with_image,
        COALESCE(SUM(EXISTS(SELECT 1 FROM emcore_business_card_locations l WHERE l.card_id=c.id AND l.latitude IS NOT NULL)),0) with_coordinates,
        COALESCE(SUM(c.review_status='needs_review'),0) needs_review FROM emcore_business_cards c WHERE ".$scope;
    $s=$db->prepare($sql);$s->execute($params);$summary=array_map('intval',$s->fetch());$summary['without_image']=$summary['total']-$summary['with_image'];
    $sorts=['id'=>'c.id','contact_name'=>'c.contact_name','organization_name'=>'c.organization_name','business_country_code'=>'c.business_country_code','related_unit'=>'c.related_unit','created_at'=>'c.created_at'];
    $sort=emcore_bc_enum($_POST['sort_by']??'id',array_keys($sorts));$direction=emcore_bc_enum($_POST['sort_order']??'desc',['asc','desc']);
    $page=emcore_bc_int($_POST['page']??1,1000000);$size=emcore_bc_int($_POST['page_size']??25,100);$offset=($page-1)*$size;
    $s=$db->prepare("SELECT c.id,c.contact_name,c.organization_name,c.job_title,c.business_country_code,c.related_unit,c.review_status,c.record_origin,c.lock_version,c.created_at,
        bc.name_fa business_country_name,f.id image_id,
        (SELECT l.city FROM emcore_business_card_locations l WHERE l.card_id=c.id ORDER BY l.sort_order,l.id LIMIT 1) city,
        (SELECT country.name_fa FROM emcore_business_card_locations l LEFT JOIN emcore_business_card_countries country ON country.code=l.country_code WHERE l.card_id=c.id ORDER BY l.sort_order,l.id LIMIT 1) location_country_name,
        (SELECT l.address FROM emcore_business_card_locations l WHERE l.card_id=c.id ORDER BY l.sort_order,l.id LIMIT 1) primary_address,
        (SELECT GROUP_CONCAT(cp.raw_value SEPARATOR '؛ ') FROM emcore_business_card_contact_points cp WHERE cp.card_id=c.id AND cp.kind IN ('phone','mobile')) phones,
        (SELECT GROUP_CONCAT(cp.raw_value SEPARATOR '؛ ') FROM emcore_business_card_contact_points cp WHERE cp.card_id=c.id AND cp.kind='email') emails
        FROM emcore_business_cards c LEFT JOIN emcore_business_card_countries bc ON bc.code=c.business_country_code
        LEFT JOIN emcore_business_card_files f ON f.current_card_id=c.id WHERE ".$scope.' ORDER BY '.$sorts[$sort].' '.$direction.',c.id '.$direction.' LIMIT '.$size.' OFFSET '.$offset);
    $s->execute($params);emcore_json(['success'=>true,'data'=>$s->fetchAll(),'summary'=>$summary,'pagination'=>['page'=>$page,'page_size'=>$size,'total'=>$summary['total'],'total_pages'=>(int)ceil($summary['total']/$size)],'csrf_token'=>emcore_csrf_token(),'permissions'=>emcore_module_permissions('business_cards')]);
}

if ($action==='duplicate_candidates') {
    $id=isset($_POST['id']) && $_POST['id']!=='' ? emcore_bc_int($_POST['id']):0;
    $existing=$id?emcore_bc_get($id):null;
    $name=emcore_bc_normalize(emcore_bc_text($_POST['contact_name']??($existing['contact_name']??null),500));
    $org=emcore_bc_normalize(emcore_bc_text($_POST['organization_name']??($existing['organization_name']??null),500));
    $points=emcore_bc_array($_POST['contact_points']??($existing['contact_points']??[]));$reasons=[];
    if ($name && $org) {
        $s=$db->prepare('SELECT id FROM emcore_business_cards WHERE deleted_at IS NULL AND id<>? AND contact_key=? AND organization_key=? LIMIT 50');$s->execute([$id,$name,$org]);foreach($s->fetchAll() as $r) $reasons[$r['id']][]='نام مخاطب و سازمان یکسان';
    }
    foreach($points as $p) {
        if (!is_array($p)) throw new EmcoreHttpException(422,'راه ارتباطی نامعتبر است');
        $kind=$p['kind']??'other';$v=emcore_bc_point_normalized($kind,emcore_bc_text($p['raw_value']??null,1000));
        if (!$v || !in_array($kind,['phone','mobile','email'],true)) continue;
        $s=$db->prepare("SELECT DISTINCT c.id FROM emcore_business_cards c JOIN emcore_business_card_contact_points p ON p.card_id=c.id WHERE c.deleted_at IS NULL AND c.id<>? AND p.normalized_value=? AND p.kind IN ('phone','mobile','email') LIMIT 50");$s->execute([$id,$v]);foreach($s->fetchAll() as $r) $reasons[$r['id']][]='تلفن یا ایمیل یکسان';
    }
    if ($existing && $existing['image']) {
        $s=$db->prepare('SELECT DISTINCT c.id FROM emcore_business_cards c JOIN emcore_business_card_files f ON f.current_card_id=c.id WHERE c.deleted_at IS NULL AND c.id<>? AND f.sha256=? LIMIT 50');$s->execute([$id,$existing['image']['sha256']]);foreach($s->fetchAll() as $r) $reasons[$r['id']][]='تصویر یکسان';
    }
    $rows=[];foreach(array_slice($reasons,0,50,true) as $candidate=>$why) {$row=emcore_bc_get($candidate);$rows[]=['id'=>$candidate,'contact_name'=>$row['contact_name'],'organization_name'=>$row['organization_name'],'reasons'=>array_values(array_unique($why))];}
    emcore_json(['success'=>true,'data'=>$rows]);
}

emcore_require_csrf();$stored=null;$committed=false;
try {
    $db->beginTransaction();
    if ($action==='create') {
        $payload=emcore_bc_payload($_POST);$request=emcore_bc_request($_POST['request_id']??null);$hash=emcore_bc_hash($payload);
        $s=$db->prepare('SELECT id,create_payload_hash,deleted_at FROM emcore_business_cards WHERE created_by_usr_uid=? AND create_request_id=?');$s->execute([$actor,$request]);$old=$s->fetch();
        if ($old) {
            if ($old['deleted_at'] || !hash_equals($old['create_payload_hash'],$hash)) throw new EmcoreHttpException(409,'شناسه درخواست قبلاً برای داده دیگری استفاده شده است');
            $row=emcore_bc_get($old['id']);$db->commit();emcore_json(['success'=>true,'data'=>$row]);
        }
        $id=emcore_bc_create($payload,$actor,'manual',$request,$hash);$after=emcore_bc_get($id);emcore_audit('business_cards','create','business_card',$id,null,$after);
        $db->commit();emcore_json(['success'=>true,'data'=>$after],201);
    }
    $id=emcore_bc_int($_POST['id']??null);$before=emcore_bc_get($id,true);
    if (in_array($action,['upload_image','replace_image'],true)) {
        $request=emcore_bc_request($_POST['request_id']??null);$upload=$_FILES['file']??null;
        if (!is_array($upload) || is_array($upload['error']??null) || ($upload['error']??-1)!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']??'')) throw new EmcoreHttpException(422,'بارگذاری تصویر کامل نشد');
        $meta=emcore_bc_inspect_image($upload['tmp_name'],$upload['name']);$hash=emcore_bc_hash([$id,$action,$meta['sha256'],$meta['original_filename']]);
        $s=$db->prepare('SELECT id,card_id,payload_hash,current_card_id FROM emcore_business_card_files WHERE uploaded_by_usr_uid=? AND request_id=?');$s->execute([$actor,$request]);$old=$s->fetch();
        if ($old) {
            if (!hash_equals($old['payload_hash'],$hash) || !(int)$old['current_card_id']) throw new EmcoreHttpException(409,'شناسه درخواست متفاوت یا تصویر دیگر جاری نیست');
            $db->commit();emcore_json(['success'=>true,'data'=>$before]);
        }
        emcore_bc_version($before);$current=emcore_bc_current_file($id);
        if ($action==='upload_image' && $current) throw new EmcoreHttpException(409,'این کارت تصویر دارد؛ از جایگزینی تصویر استفاده کنید');
        if ($action==='replace_image' && !$current) throw new EmcoreHttpException(409,'این کارت تصویر ندارد؛ از افزودن تصویر استفاده کنید');
        $stored=emcore_bc_store_image($upload['tmp_name'],$upload['name']);
        if ($current) $db->prepare('UPDATE emcore_business_card_files SET superseded_at=NOW() WHERE id=?')->execute([$current['id']]);
        emcore_bc_insert('emcore_business_card_files',array_merge(['card_id'=>$id],$stored,['request_id'=>$request,'payload_hash'=>$hash,'uploaded_by_usr_uid'=>$actor,'replaces_file_id'=>$current?$current['id']:null]));
        emcore_bc_touch($id,$actor);
    } else {
        emcore_bc_version($before);
        if ($action==='update') {
            $payload=emcore_bc_payload($_POST,false,$before);$values=$payload;unset($values['contact_points'],$values['locations']);
            $s=$db->prepare('UPDATE emcore_business_cards SET '.implode(',',array_map(function($k){return $k.'=?';},array_keys($values))).' WHERE id=?');$s->execute(array_merge(array_values($values),[$id]));emcore_bc_children($id,$payload);emcore_bc_touch($id,$actor);
        } elseif ($action==='delete_image') {
            $current=emcore_bc_current_file($id);if (!$current) throw new EmcoreHttpException(404,'تصویر کارت موجود نیست');
            $db->prepare('UPDATE emcore_business_card_files SET deleted_at=NOW() WHERE id=?')->execute([$current['id']]);emcore_bc_touch($id,$actor);
        } elseif ($action==='delete') {
            $db->prepare('UPDATE emcore_business_cards SET deleted_at=NOW(),updated_at=NOW(),updated_by_usr_uid=?,lock_version=lock_version+1 WHERE id=?')->execute([$actor,$id]);
        }
    }
    $after=$action==='delete'?array_merge($before,['deleted'=>true,'lock_version'=>(int)$before['lock_version']+1]):emcore_bc_get($id);
    emcore_audit('business_cards',$action==='delete'?'delete':'update','business_card',$id,$before,$after,['operation'=>$action]);
    $db->commit();$committed=true;emcore_json(['success'=>true,'data'=>$after],in_array($action,['upload_image','replace_image'],true)?201:200);
} catch(Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();if (!$committed && $stored) emcore_bc_cleanup_image($stored);
    // Simultaneous retries may both observe an absent create key; the unique key
    // serializes insertion. After rollback, replay only the identical request.
    if ($action==='create' && $e instanceof PDOException && $e->getCode()==='23000' && isset($request,$hash)) {
        $s=$db->prepare('SELECT id,create_payload_hash,deleted_at FROM emcore_business_cards WHERE created_by_usr_uid=? AND create_request_id=?');$s->execute([$actor,$request]);$old=$s->fetch();
        if ($old) {
            if ($old['deleted_at'] || !hash_equals($old['create_payload_hash'],$hash)) throw new EmcoreHttpException(409,'شناسه درخواست قبلاً برای داده دیگری استفاده شده است');
            emcore_json(['success'=>true,'data'=>emcore_bc_get($old['id'])]);
        }
    }
    throw $e;
}
