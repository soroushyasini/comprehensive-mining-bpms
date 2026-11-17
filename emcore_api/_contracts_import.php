<?php
require_once __DIR__.'/_contracts_storage.php';

function ct_hash($value)
{
    if (!is_string($value) || !preg_match('/^[a-f0-9]{64}$/D',$value)) ct_fail(422,'شناسه یا هش ورودی نامعتبر است');
    return $value;
}

function ct_staged($hash,$limit)
{
    $hash = ct_hash($hash);
    $path = ct_private_path('staging/'.$hash);
    if (filesize($path)>$limit || !hash_equals($hash,hash_file('sha256',$path))) ct_fail(409,'فایل آماده‌شده با فهرست ورودی سازگار نیست');
    return $path;
}

function ct_manifest($hash)
{
    $manifest = ct_decode(file_get_contents(ct_staged($hash,16*1024*1024)));
    if (($manifest['schema_version'] ?? null)!==1 || !isset($manifest['items']) || !is_array($manifest['items'])
        || !$manifest['items'] || count($manifest['items'])>10000) ct_fail(422,'نسخه یا اندازهٔ فهرست ورودی نامعتبر است');
    return $manifest;
}

function ct_import_preview($hash,$input)
{
    $manifest = ct_manifest($hash);
    $counts = [];
    foreach ($manifest['items'] as $item) {
        $status = $item['status'];
        $counts[$status] = ($counts[$status] ?? 0)+1;
        if (!empty($item['same_hash_doc_ids'])) $counts['same_hash'] = ($counts['same_hash'] ?? 0)+1;
        if (!empty($item['metadata']['archive_member_path'])) $counts['archive_member'] = ($counts['archive_member'] ?? 0)+1;
        if ($status==='ready') $bundles[$item['bundle_id']]=true;
    }
    [$limit,$offset] = ct_page($input);
    return ['manifest_hash'=>$hash,'total'=>count($manifest['items']),'bundles'=>count($bundles ?? []),'counts'=>$counts,
        'coverage'=>$manifest['coverage'],'items'=>array_slice($manifest['items'],$offset,$limit)];
}

function ct_import_status($hash,$input)
{
    [$limit,$offset] = ct_page($input);
    $batch = ct_one('SELECT * FROM emcore_contract_import_batches WHERE manifest_hash=?',[$hash]);
    $docIds = array_column(ct_manifest($hash)['items'],'doc_id');
    $totals = [];
    foreach (array_chunk($docIds,500) as $chunk) {
        $placeholders = implode(',',array_fill(0,count($chunk),'?'));
        $stats = ct_all("SELECT status,COUNT(*) AS total FROM emcore_contract_sources WHERE doc_id IN ($placeholders) GROUP BY status",$chunk);
        foreach ($stats as $entry) {
            $status=$entry['status'];
            $totals[$status]=($totals[$status] ?? 0)+(int)$entry['total'];
        }
    }
    $counts = [];
    foreach ($totals as $status=>$total) $counts[]=['status'=>$status,'total'=>$total];
    $items = [];
    $page = array_slice($docIds,$offset,$limit);
    if ($page) {
        $placeholders = implode(',',array_fill(0,count($page),'?'));
        $rows = ct_all("SELECT s.id,s.doc_id,s.bundle_id,s.relative_path,s.status,s.sha256,s.version_id,s.metadata,d.contract_id
            FROM emcore_contract_sources s LEFT JOIN emcore_contract_versions v ON v.id=s.version_id
            LEFT JOIN emcore_contract_documents d ON d.id=v.document_id WHERE s.doc_id IN ($placeholders)",$page);
        $indexed = array_column($rows,null,'doc_id');
        foreach ($page as $id) if (isset($indexed[$id])) $items[]=$indexed[$id];
    }
    if ($batch) $batch['cursor'] = (int)$batch['next_index'];
    return ['batch'=>$batch ?: null,'counts'=>$counts,'items'=>$items];
}

function ct_import_role($role)
{
    foreach (['الحاقیه'=>'amendment','تمدید'=>'amendment','پیش‌نویس'=>'draft','چک'=>'guarantee','تضمین'=>'guarantee',
        'مالی'=>'payment','رسید'=>'payment','هویتی'=>'identity','صورت‌جلسه'=>'minutes','مکاتبه'=>'correspondence',
        'اصلی'=>'main','پیوست'=>'attachment'] as $term=>$code) {
        if (mb_strpos($role,$term)!==false) return $code;
    }
    return 'other';
}

function ct_import_blob_info($item,$path)
{
    return ['path'=>$path,'sha256'=>$item['sha256'],'original_filename'=>ct_text($item['filename'],255,true),
        'mime_type'=>(new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream','file_size'=>filesize($path)];
}

function ct_import_document($item,$contract,$file,&$stored)
{
    $role = ct_import_role($item['metadata']['document_role'] ?? '');
    $document = ct_create_record('documents',['contract_id'=>$contract,'title'=>$file['original_filename'],'role_code'=>$role],$contract);
    $extracted = file_get_contents(ct_staged($item['text_hash'],8*1024*1024));
    if (!mb_check_encoding($extracted,'UTF-8')) ct_fail(422,'متن استخراج‌شده معتبر نیست');
    return ct_version_create($document['id'],$contract,$file,'ورود از آرشیو موجود',$stored,$extracted);
}

function ct_import_batch($hash,$input,&$stored)
{
    $manifest = ct_manifest($hash);
    $total = count($manifest['items']);
    ct_query('INSERT INTO emcore_contract_import_batches (manifest_hash,total,created_by,created_at,updated_at)
        VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE manifest_hash=VALUES(manifest_hash)',[$hash,$total,ct_actor(),ct_now(),ct_now()]);
    $batch = ct_one('SELECT * FROM emcore_contract_import_batches WHERE manifest_hash=? FOR UPDATE',[$hash]);
    $cursor = ct_range($input['cursor'] ?? 0,0,$total);
    if ($cursor!==(int)$batch['next_index']) ct_fail(409,'پیشرفت ورود تغییر کرده است؛ وضعیت را دوباره بخوانید');
    $chunk = array_slice($manifest['items'],$cursor,25);
    $counts = [];
    foreach ($chunk as $item) {
        $docId = ct_text($item['doc_id'] ?? null,40,true);
        if (!preg_match('/^DOC-(?:(?:ARC-)?[A-F0-9]{12}|EXTRA-[A-F0-9]{20})$/D',$docId)) ct_fail(422,'شناسهٔ مدرک مبدأ نامعتبر است');
        $previous = ct_one('SELECT id,sha256,relative_path,status FROM emcore_contract_sources WHERE doc_id=? FOR UPDATE',[$docId]);
        if ($previous && $previous['status']!=='blocked') {
            if ($previous['sha256']!==($item['sha256'] ?? null) || $previous['relative_path']!==$item['relative_path']) ct_fail(409,'مدرک قبلی با نسخهٔ ورودی جدید متفاوت است');
            $counts['existing'] = ($counts['existing'] ?? 0)+1;
            continue;
        }
        $status = ct_enum($item['status'] ?? null,['ready','blocked','reference','container','quarantine','ungrouped']);
        $key = $version = $contract = null;
        if ($status!=='blocked') {
            $file = ct_import_blob_info($item,ct_staged($item['sha256'],256*1024*1024));
            if ($status==='ready') {
                $bundle = ct_text($item['bundle_id'] ?? null,40,true);
                if (!preg_match('/^BND-[A-F0-9]{12}$/D',$bundle)) ct_fail(422,'شناسهٔ بسته نامعتبر است');
                $row = ct_one('SELECT * FROM emcore_contracts WHERE origin_bundle=? FOR UPDATE',[$bundle]);
                if (!$row) $row = ct_create_record('contracts',['title'=>ct_text($item['bundle_title'],250,true),
                    'origin_bundle'=>$bundle,'review_status'=>'provisional','owner_uid'=>ct_actor()],null);
                $visited = [];
                while ($row['merged_into']) {
                    if (isset($visited[$row['id']])) ct_fail(409,'زنجیرهٔ ادغام پرونده معتبر نیست');
                    $visited[$row['id']]=true;
                    $row=ct_row('contracts',$row['merged_into'],null,true);
                }
                if ($row['deleted_at']) ct_fail(409,'پروندهٔ مقصد قبلاً حذف شده است');
                $contract = $row['id'];
                $version = ct_import_document($item,$contract,$file,$stored)['id'];
                $status = 'imported';
            } else {
                $key = ct_blob($file['path'],$file['sha256'],$stored);
            }
        }
        $values = ['doc_id'=>$docId,'bundle_id'=>$item['bundle_id'] ?? null,'manifest_hash'=>$hash,
            'relative_path'=>$item['relative_path'],'sha256'=>$item['sha256'] ?? null,
            'metadata'=>ct_json($item),'status'=>$status,'version_id'=>$version,'storage_key'=>$key,
            'created_by'=>ct_actor(),'created_at'=>ct_now()];
        if ($previous) {
            ct_patch('sources',$previous['id'],$values);
            $sourceId = $previous['id'];
        } else $sourceId = ct_insert('sources',$values);
        ct_audit('sources',$sourceId,$previous,['doc_id'=>$docId,'status'=>$status,'version_id'=>$version],$contract,'archive_import');
        $counts[$status] = ($counts[$status] ?? 0)+1;
    }
    $next = $cursor+count($chunk);
    ct_query('UPDATE emcore_contract_import_batches SET next_index=?,updated_at=? WHERE manifest_hash=?',[$next,ct_now(),$hash]);
    ct_audit('import',$hash,null,['cursor'=>$next,'total'=>$total,'counts'=>$counts],null,'import_apply_batch');
    return ['manifest_hash'=>$hash,'cursor'=>$next,'total'=>$total,'done'=>$next===$total,'counts'=>$counts];
}

function ct_attach_source($input,$contract,&$stored)
{
    $source = ct_row('sources',ct_id($input['id'] ?? null),null,true);
    if (!in_array($source['status'],['ungrouped','reference'],true)) ct_fail(409,'این قلم قابل اتصال نیست');
    $item = ct_decode($source['metadata']);
    $file = ct_validate_file(ct_private_path($source['storage_key']),$item['filename'],256*1024*1024);
    if ($file['sha256']!==$source['sha256']) ct_fail(409,'هش مدرک مبدأ تغییر کرده است');
    $version = ct_import_document($item,$contract['id'],$file,$stored);
    ct_patch('sources',$source['id'],['status'=>'imported','version_id'=>$version['id']]);
    ct_audit('sources',$source['id'],['status'=>$source['status']],['status'=>'imported','version_id'=>$version['id']],$contract['id'],'source_attach');
    return $version;
}

function ct_structure($action,$input,$apply)
{
    $source = ct_id($input['contract_id'] ?? null);
    $target = $action==='split' ? null : ct_id($input['target_id'] ?? null);
    if ($source===$target) ct_fail(422,'پروندهٔ مقصد باید متفاوت باشد');
    $ids = $target ? [$source,$target] : [$source];
    sort($ids,SORT_NUMERIC);
    $rows=[];
    foreach ($ids as $id) {
        $rows[$id]=ct_row('contracts',$id,null,$apply);
        if ($rows[$id]['merged_into']) ct_fail(409,'پرونده قبلاً ادغام شده است');
    }
    $from=$rows[$source];
    $into=$target ? $rows[$target] : null;
    if ($from['hold_reason'] && $action!=='merge') ct_fail(409,'انتقال جداگانه از پروندهٔ دارای منع امحا مجاز نیست');
    if ($apply) {
        if (($input['confirmed'] ?? false)!==true) ct_fail(422,'تغییر ساختار را تأیید کنید');
        ct_version($from,$input['contract_version'] ?? null);
        if ($into) ct_version($into,$input['target_version'] ?? null);
    }
    $docs = ct_all('SELECT id,title,current_version_id,lock_version FROM emcore_contract_documents WHERE contract_id=? ORDER BY id',[$source]);
    if ($action!=='merge') {
        $selection = $input['document_ids'] ?? [];
        if (!is_array($selection) || !$selection || count($selection)>1000) ct_fail(422,'مدارک را انتخاب کنید');
        $selection = array_unique(array_map('ct_id',$selection));
        $docs = array_values(array_filter($docs,static function ($d) use ($selection) { return in_array((int)$d['id'],$selection,true); }));
        if (count($docs)!==count($selection)) ct_fail(404,'مدرکی در پروندهٔ مبدأ یافت نشد');
        foreach ($docs as $doc) {
            $dependents = ct_one('SELECT
                EXISTS(SELECT 1 FROM emcore_contract_actions WHERE document_id=?) OR
                EXISTS(SELECT 1 FROM emcore_contract_history WHERE document_id=?) OR
                EXISTS(SELECT 1 FROM emcore_contract_history_files f JOIN emcore_contract_versions v ON v.id=f.version_id WHERE v.document_id=? AND f.deleted_at IS NULL) OR
                EXISTS(SELECT 1 FROM emcore_contract_obligations WHERE document_id=?) OR
                EXISTS(SELECT 1 FROM emcore_contract_amendments a JOIN emcore_contract_versions v ON a.basis_version_id=v.id WHERE v.document_id=?) OR
                EXISTS(SELECT 1 FROM emcore_contract_evidence e JOIN emcore_contract_versions v ON e.version_id=v.id WHERE v.document_id=?) OR
                EXISTS(SELECT 1 FROM emcore_contract_versions WHERE id=? AND document_id=?) AS used',
                [$doc['id'],$doc['id'],$doc['id'],$doc['id'],$doc['id'],$doc['id'],$from['basis_version_id'],$doc['id']]);
            if ($dependents['used']) ct_fail(409,'مدرک به سابقه، اقدام یا شاهد پرونده متصل است و جداگانه قابل انتقال نیست');
        }
    }
    $revision = hash('sha256',ct_json([$source,$target,$from['lock_version'],$into['lock_version'] ?? null,$docs]));
    $preview = ['source'=>ct_contract_view($from),'target'=>$into ? ct_contract_view($into) : null,'documents'=>$docs,'revision'=>$revision];
    if (!$apply) return $preview;
    if (!hash_equals($revision,ct_hash($input['revision'] ?? null))) ct_fail(409,'مدارک یا پروندهٔ پیش‌نمایش تغییر کرده‌اند');
    if ($action==='split') {
        $into=ct_create_record('contracts',['title'=>ct_text($input['title'] ?? null,250,true),'owner_uid'=>ct_actor(),'review_status'=>'provisional'],null);
        $target=$into['id'];
    }
    if ($action==='merge') {
        if (($input['keep_target_values'] ?? false)!==true) ct_fail(422,'حفظ مقادیر مقصد را صریحاً انتخاب کنید');
        foreach (ct_all('SELECT * FROM emcore_contract_categories WHERE contract_id=? ORDER BY id',[$source]) as $category) {
            $replacement = ct_one('SELECT id FROM emcore_contract_categories WHERE contract_id=? AND title_key=?',[$target,$category['title_key']]);
            if (!$replacement) $replacement = ct_create_record('categories',[
                'contract_id'=>$target,'title'=>$category['title'],'title_key'=>$category['title_key'],
                'is_active'=>$category['is_active'],'sort_order'=>$category['sort_order']],$target);
            foreach (['actions','history'] as $entity) ct_query('UPDATE '.ct_table($entity).' SET category_id=? WHERE contract_id=? AND category_id=?',[$replacement['id'],$source,$category['id']]);
        }
        foreach (['parties','actions','history','amendments','obligations','evidence'] as $entity) {
            ct_query('UPDATE '.ct_table($entity).' SET contract_id=? WHERE contract_id=?',[$target,$source]);
        }
        ct_query('INSERT IGNORE INTO emcore_contract_mines (contract_id,mine_id) SELECT ?,mine_id FROM emcore_contract_mines WHERE contract_id=?',[$target,$source]);
        if ($from['hold_reason']) {
            $reasons = $into['hold_reason'] ? $into['hold_reason'].' / '.$from['hold_reason'] : $from['hold_reason'];
            if (mb_strlen($reasons)>500) ct_fail(422,'پیش از ادغام، دلیل منع امحای مقصد را خلاصه کنید');
            $into = ct_change('contracts',$into,['hold_reason'=>$reasons,'hold_at'=>ct_now(),'hold_by'=>ct_actor()],$target,'merge_hold');
        }
    }
    foreach ($docs as $doc) {
        $existing = ct_row('documents',$doc['id'],$source,true);
        ct_change('documents',$existing,['contract_id'=>$target],$target,$action);
    }
    ct_change('contracts',$from,$action==='merge'?['merged_into'=>$target,'archived_at'=>ct_now()]:[],$source,$action);
    $into = ct_change('contracts',$into,['review_status'=>$into['review_status']==='provisional'?'provisional':'needs_review'],$target,$action);
    ct_audit('structure',$target,['contract_id'=>$source],['contract_id'=>$target,'document_ids'=>array_column($docs,'id')],$source,$action);
    return ['source_id'=>$source,'target_id'=>$target,'document_count'=>count($docs),'target'=>ct_contract_view($into)];
}
