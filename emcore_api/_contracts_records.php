<?php
require_once __DIR__.'/_contracts_domain.php';

function ct_create_record($entity,$fields,$contract,$stamp = true)
{
    if ($stamp) $fields += ct_stamp();
    $id = ct_insert($entity,$fields);
    $row = ct_row($entity,$id);
    ct_audit($entity,$id,null,$row,$contract ?? $id);
    return $row;
}

function ct_delete_record($entity,$row,$reason,$contract)
{
    $values = ['deleted_at'=>ct_now(),'deleted_by'=>ct_actor(),'delete_reason'=>ct_text($reason,500,true)];
    if (isset($row['lock_version'])) $values += ['lock_version'=>(int)$row['lock_version']+1,'updated_by'=>ct_actor(),'updated_at'=>ct_now()];
    ct_patch($entity,$row['id'],$values);
    $after = array_replace($row,$values);
    ct_audit($entity,$row['id'],$row,$after,$contract,null,'delete');
    return $after;
}

function ct_party($input,$contract)
{
    $kind = ct_enum($input['kind'] ?? null,['company','person','manual']);
    $reference = $kind === 'manual' ? null : ct_id($input['reference_id'] ?? null);
    $party = null;
    if ($kind === 'company') $party = ct_one('SELECT name_fa AS name,national_id,registration_number FROM emcore_companies WHERE id=? AND deleted_at IS NULL',[$reference]);
    if ($kind === 'person') $party = ct_one("SELECT CONCAT_WS(' ',first_name,last_name) AS name,national_id,address FROM emcore_persons WHERE id=? AND deleted_at IS NULL",[$reference]);
    if ($reference && !$party) ct_fail(422,'طرف انتخاب‌شده در اطلاعات پایه موجود نیست');
    $values = ['contract_id'=>$contract,'kind'=>$kind,'reference_id'=>$reference,
        'side'=>ct_enum($input['side'] ?? null,['ours','other']),
        'role_name'=>ct_text($input['role_name'] ?? null,100)];
    $data = ($party ?: []) + $input;
    foreach (['name'=>250,'national_id'=>50,'registration_number'=>50,'address'=>500] as $key=>$max) {
        $values[$key] = ct_text($data[$key] ?? null,$max,$key==='name');
    }
    return $values;
}

function ct_contract_write($action,$input,$row)
{
    if ($action === 'create') {
        $name=ct_text($input['counterparty_name'] ?? null,250);
        if ($name!==null) emcore_require_permission('contracts','update');
        $created=ct_create_record('contracts',ct_contract_fields($input,null),null);
        if ($name!==null) ct_create_record('parties',ct_party(['kind'=>'manual','side'=>'other','name'=>$name],$created['id']),$created['id'],false);
        return ct_contract_view($created);
    }
    $id = $row['id'];
    if ($row['merged_into']) ct_fail(409,'این پرونده به پروندهٔ دیگری منتقل شده است');
    switch ($action) {
        case 'update':
            $values = ct_contract_fields($input,$row);
            foreach (['title','official_number','signed_on','starts_on','ends_on','notice_on','amount','currency','amount_type','signature_status','legal_status','owner_uid'] as $key) {
                if (array_key_exists($key,$values) && (string)$values[$key] !== (string)$row[$key]) {
                    $values['review_status'] = 'needs_review';
                    break;
                }
            }
            if (array_key_exists('hold_reason',$input)) {
                $reason = ct_text($input['hold_reason'],500);
                if ($reason !== $row['hold_reason']) {
                    $holdChange = ct_text($input['reason'] ?? null,500) ?? $reason;
                    ct_text($holdChange,500,true);
                    $values += ['hold_reason'=>$reason,'hold_by'=>ct_actor(),'hold_at'=>ct_now()];
                }
            }
            return ct_contract_view(ct_change('contracts',$row,$values,$id,isset($holdChange) ? 'hold: '.$holdChange : 'update'));
        case 'archive':
        case 'unarchive':
            return ct_contract_view(ct_change('contracts',$row,['archived_at'=>$action==='archive'?ct_now():null],$id,$action));
        case 'delete':
            if ($row['hold_reason'] !== null) ct_fail(409,'پرونده دارای منع امحا است');
            return ct_contract_view(ct_delete_record('contracts',$row,$input['reason'] ?? null,$id));
        case 'restore':
            if (!$row['deleted_at']) ct_fail(409,'پرونده حذف نشده است');
            return ct_contract_view(ct_change('contracts',$row,['deleted_at'=>null,'deleted_by'=>null,'delete_reason'=>null],$id,'restore'));
        case 'party_create':
            $party = ct_create_record('parties',ct_party($input,$id),$id,false);
            ct_mark_review($row,'party_create');
            return $party;
        case 'party_update':
            $party = ct_row('parties',ct_id($input['id'] ?? null),$id,true);
            $values = ct_party($input,$id);
            // Keep the recorded identity snapshot unless the linked entity changes.
            if ($party['kind'] === $values['kind'] && (string)$party['reference_id'] === (string)$values['reference_id'] && $values['kind'] !== 'manual') {
                foreach (['name','national_id','registration_number','address'] as $key) $values[$key]=$party[$key];
            }
            ct_patch('parties',$party['id'],$values);
            ct_audit('parties',$party['id'],$party,$values,$id,'party_update');
            ct_mark_review($row,'party_update');
            return array_replace($party,$values);
        case 'mine_set':
            $mine = ct_id($input['mine_id'] ?? null);
            $enabled = ct_enum($input['enabled'] ?? null,[true,false]);
            if ($enabled && !ct_one('SELECT id FROM emcore_mines WHERE id=? AND deleted_at IS NULL',[$mine])) ct_fail(422,'معدن یافت نشد');
            $before = ct_one('SELECT mine_id FROM emcore_contract_mines WHERE contract_id=? AND mine_id=?',[$id,$mine]);
            if ($enabled && !$before) ct_insert('mines',['contract_id'=>$id,'mine_id'=>$mine]);
            if (!$enabled && $before) ct_query('DELETE FROM emcore_contract_mines WHERE contract_id=? AND mine_id=?',[$id,$mine]);
            $after = ['mine_id'=>$mine,'enabled'=>$enabled];
            ct_audit('mines',$mine,$before,$after,$id,'mine_set');
            return $after;
    }
    ct_fail(400,'عملیات پرونده نامعتبر است');
}

function ct_category_write($action,$input,$contract)
{
    $before = $action==='category_update' ? ct_row('categories',ct_id($input['id'] ?? null),null,true) : null;
    if ($before && (string)$before['contract_id'] !== (string)$contract) ct_fail(404,'دسته در این دامنه یافت نشد');
    $title = ct_text($input['title'] ?? null,160,true);
    $key = emcore_mh_category_key($title);
    if ($key === '') ct_fail(422,'عنوان دسته معتبر نیست');
    $values = ['title'=>$title,'title_key'=>$key,'sort_order'=>ct_range($input['sort_order'] ?? 0,0,2147483647),
        'is_active'=>(int)ct_enum($input['is_active'] ?? true,[true,false])];
    if (!$before) return ct_create_record('categories',$values+['contract_id'=>$contract],$contract);
    ct_version($before,$input['lock_version'] ?? null);
    return ct_change('categories',$before,$values,$contract,'category_update');
}

function ct_action_write($action,$input,$contract,$before)
{
    $id = $contract['id'];
    if ($action === 'action_complete') {
        if (!in_array($before['status'],['open','in_progress'],true)) ct_fail(409,'این اقدام باز نیست');
        [$fa,$en] = ct_date($input['completed_fa'] ?? null,true,true);
        $result = ct_text($input['result'] ?? null,5000,true);
        $document = ct_ref('documents',$input['document_id'] ?? $before['document_id'],$id);
        $entry = ct_create_record('history',['contract_id'=>$id,'category_id'=>$before['category_id'],
            'description'=>$result,'happened_fa'=>$fa,'happened_on'=>$en,'action_id'=>$before['id'],'document_id'=>$document],$id);
        $row = ct_change('actions',$before,['completed_fa'=>$fa,'completed_on'=>$en,'result'=>$result,'status'=>'done'],$id,$action);
        return ['action'=>$row,'history'=>$entry];
    }
    if ($action === 'action_reopen') {
        if (!in_array($before['status'],['done','cancelled'],true)) ct_fail(409,'این اقدام بسته نیست');
        $reason = ct_text($input['reason'] ?? null,5000,true);
        [$fa,$en] = ct_date($input['happened_fa'] ?? null,true,true);
        ct_create_record('history',['contract_id'=>$id,'category_id'=>$before['category_id'],
            'description'=>'بازگشایی اقدام: '.$reason,'happened_fa'=>$fa,'happened_on'=>$en,'action_id'=>$before['id']],$id);
        return ct_change('actions',$before,['status'=>'open','completed_fa'=>null,'completed_on'=>null,'result'=>null],$id,$action);
    }
    if ($before && !in_array($before['status'],['open','in_progress'],true)) ct_fail(409,'ابتدا اقدام را بازگشایی کنید');
    $fields = $input + ($before ?: []);
    [$fa,$en] = ct_date($fields['due_fa'] ?? null);
    $values = [
        'contract_id'=>$id,'title'=>ct_text($fields['title'] ?? null,250,true),
        'description'=>ct_text($fields['description'] ?? null,5000),
        'category_id'=>ct_category($fields['category_id'] ?? null,$id,$before['category_id'] ?? null),
        'owner_uid'=>ct_owner($fields['owner_uid'] ?? null,$contract['owner_uid']),
        'due_fa'=>$fa,'due_on'=>$en,
        'priority'=>ct_enum($fields['priority'] ?? null,['low','normal','high'],'normal'),
        'status'=>ct_enum($fields['status'] ?? null,['open','in_progress','cancelled'],'open')
    ];
    foreach (['document_id'=>'documents','amendment_id'=>'amendments','obligation_id'=>'obligations'] as $field=>$entity) {
        $values[$field]=ct_ref($entity,$fields[$field] ?? null,$id);
    }
    return $before ? ct_change('actions',$before,$values,$id,$action) : ct_create_record('actions',$values,$id);
}

function ct_history_write($action,$input,$id,$before)
{
    if ($action === 'history_delete') return ct_delete_record('history',$before,$input['reason'] ?? null,$id);
    if ($before) ct_text($input['reason'] ?? null,500,true);
    $fields = $input + ($before ?: []);
    if (!empty($input['new_category'])) {
        if ($before) ct_fail(422,'دستهٔ تازه را جداگانه ثبت کنید');
        emcore_require_permission('contracts','update');
        $category=ct_category_write('category_create',['title'=>$input['new_category']],$id);
        $fields['category_id']=$category['id'];
    }
    if (isset($input['reminder'])) {
        if ($before || !is_array($input['reminder']) || !empty($fields['action_id'])) ct_fail(422,'یادآوری مرتبط نامعتبر است');
        emcore_require_permission('contracts','create');
        $reminder=$input['reminder'];
        ct_date($reminder['due_fa'] ?? null,true);
        $reminder['category_id']=$fields['category_id'] ?? null;
        $reminder['status']='open';
        $linked=ct_action_write('action_create',$reminder,ct_row('contracts',$id),null);
        $fields['action_id']=$linked['id'];
    }
    [$fa,$en] = ct_date($fields['happened_fa'] ?? null,true,true);
    $values = ['contract_id'=>$id,'category_id'=>ct_category($fields['category_id'] ?? null,$id,$before['category_id'] ?? null),
        'description'=>ct_text($fields['description'] ?? null,5000,true),'happened_fa'=>$fa,'happened_on'=>$en,
        'action_id'=>ct_ref('actions',$fields['action_id'] ?? null,$id),
        'document_id'=>ct_ref('documents',$fields['document_id'] ?? null,$id)];
    return $before ? ct_change('history',$before,$values,$id,'history_update: '.ct_text($input['reason'],500))
        : ct_create_record('history',$values,$id);
}

function ct_obligation_write($action,$input,$id,$before)
{
    if ($action === 'obligation_delete') {
        if (ct_one('SELECT id FROM emcore_contract_actions WHERE obligation_id=? LIMIT 1',[$before['id']])) ct_fail(409,'این قلم به اقدامی متصل است');
        return ct_delete_record('obligations',$before,$input['reason'] ?? null,$id);
    }
    $fields = $input + ($before ?: []);
    [$fa,$en] = ct_date($fields['due_fa'] ?? null);
    $values = ['contract_id'=>$id,'kind'=>ct_enum($fields['kind'] ?? null,['obligation','guarantee','insurance']),
        'title'=>ct_text($fields['title'] ?? null,250,true),'owner_uid'=>ct_owner($fields['owner_uid'] ?? null),
        'party_id'=>ct_ref('parties',$fields['party_id'] ?? null,$id),'issuer'=>ct_text($fields['issuer'] ?? null,250),
        'number'=>ct_text($fields['number'] ?? null,100),'due_fa'=>$fa,'due_on'=>$en,
        'status'=>ct_enum($fields['status'] ?? null,['open','fulfilled','released','cancelled'],'open'),
        'notes'=>ct_text($fields['notes'] ?? null,5000),'document_id'=>ct_ref('documents',$fields['document_id'] ?? null,$id)]+ct_money($fields);
    return $before ? ct_change('obligations',$before,$values,$id,$action) : ct_create_record('obligations',$values,$id);
}

function ct_version_in_contract($id,$contract,$allowDeleted=false)
{
    $row = ct_one('SELECT v.* FROM emcore_contract_versions v JOIN emcore_contract_documents d ON d.id=v.document_id
        WHERE v.id=? AND d.contract_id=?'.($allowDeleted?'':' AND v.deleted_at IS NULL'),[ct_id($id),$contract]);
    if (!$row) ct_fail(404,'این نسخه در پرونده یافت نشد');
    return $row;
}

function ct_verify($input,$row)
{
    $id = $row['id'];
    $basis = ct_version_in_contract($input['basis_version_id'] ?? null,$id);
    ct_owner($row['owner_uid']);
    $sides = ct_all('SELECT DISTINCT side FROM emcore_contract_parties WHERE contract_id=?',[$id]);
    if (count($sides)!==2) ct_fail(422,'حداقل یک طرف خودی و یک طرف مقابل لازم است');
    $checks = $input['checks'] ?? [];
    if (!is_array($checks)) ct_fail(422,'نتیجهٔ بررسی لازم است');
    foreach (['official_number','signed_fa','starts_fa','ends_fa','notice_fa','amount','signature_status','parties'] as $field) {
        $check = $checks[$field] ?? [];
        if (!is_array($check)) ct_fail(422,'نتیجهٔ بررسی نامعتبر است');
        $outcome = ct_enum($check['outcome'] ?? null,['confirmed','not_stated','pending']);
        $value = $field==='parties' ? ct_json(ct_all('SELECT id,side,name FROM emcore_contract_parties WHERE contract_id=? ORDER BY id',[$id])) : $row[$field];
        $known = $value !== null && $value !== 'unknown';
        if ($outcome==='pending' || ($outcome==='confirmed')!==$known || ($field==='parties' && $outcome!=='confirmed')) ct_fail(422,'نتیجهٔ بررسی با مقدار پرونده سازگار نیست');
        $source = isset($check['version_id']) ? ct_version_in_contract($check['version_id'],$id) : $basis;
        $evidence = ['contract_id'=>$id,'version_id'=>$source['id'],'field_name'=>$field,'outcome'=>$outcome,
            'verified_value'=>$outcome==='confirmed' ? $value : null,
            'raw_value'=>ct_text($check['raw_value'] ?? null,5000),'locator'=>ct_text($check['locator'] ?? null,200,true),
            'created_by'=>ct_actor(),'created_at'=>ct_now()];
        ct_create_record('evidence',$evidence,$id,false);
    }
    return ct_contract_view(ct_change('contracts',$row,['review_status'=>'verified','basis_version_id'=>$basis['id']],$id,'verify'));
}

function ct_amendment_write($action,$input,$contract,$before)
{
    $id = $contract['id'];
    if ($before && $before['applied_at']) ct_fail(409,'الحاقیه اعمال شده است؛ رکورد اصلاحی ثبت کنید');
    if ($action === 'amendment_apply') {
        if (($input['confirmed'] ?? false)!==true) ct_fail(422,'اعمال الحاقیه را تأیید کنید');
        if ($before['effective_on'] > ct_today()) ct_fail(409,'تاریخ اثر الحاقیه هنوز نرسیده است');
        ct_version($contract,$input['contract_version'] ?? null);
        ct_version_in_contract($before['basis_version_id'],$id);
        $changes = ct_contract_fields(ct_decode($before['proposed']),$contract);
        $previous = array_intersect_key($contract,$changes);
        if ($contract['review_status']==='verified') $changes['review_status']='needs_review';
        ct_change('contracts',$contract,$changes,$id,'amendment_apply');
        return ct_change('amendments',$before,['previous_values'=>ct_json($previous),'applied_at'=>ct_now(),'applied_by'=>ct_actor()],$id,$action);
    }
    $fields = $input + ($before ?: []);
    $basis = ct_version_in_contract($fields['basis_version_id'] ?? null,$id);
    [$effectiveFa,$effectiveOn] = ct_date($fields['effective_fa'] ?? null,true);
    [$signedFa,$signedOn] = ct_date($fields['signed_fa'] ?? null);
    $proposed = $fields['proposed'] ?? [];
    if (is_string($proposed)) $proposed=ct_decode($proposed);
    if (!is_array($proposed) || !$proposed || array_diff(array_keys($proposed),['starts_fa','ends_fa','notice_fa','amount','currency','amount_type'])) {
        ct_fail(422,'تغییر پیشنهادی الحاقیه نامعتبر است');
    }
    ct_contract_fields($proposed,$contract);
    $corrects = ct_ref('amendments',$fields['corrects_id'] ?? null,$id);
    if ($corrects && !ct_row('amendments',$corrects,$id)['applied_at']) ct_fail(422,'رکورد اصلاحی باید به الحاقیهٔ اعمال‌شده ارجاع دهد');
    $values = ['contract_id'=>$id,'basis_version_id'=>$basis['id'],'corrects_id'=>$corrects,
        'number'=>ct_text($fields['number'] ?? null,100),'signed_fa'=>$signedFa,'signed_on'=>$signedOn,
        'effective_fa'=>$effectiveFa,'effective_on'=>$effectiveOn,'description'=>ct_text($fields['description'] ?? null,5000,true),
        'proposed'=>ct_json($proposed)];
    return $before ? ct_change('amendments',$before,$values,$id,$action) : ct_create_record('amendments',$values,$id);
}
