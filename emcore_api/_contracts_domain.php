<?php
require_once __DIR__.'/_mine_history_domain.php';

function ct_fail($status, $message) { throw new EmcoreHttpException($status, $message); }

function ct_text($value, $max, $required = false)
{
    $text = emcore_mh_text($value, $max, $required);
    return $text === '' ? null : $text;
}

function ct_enum($value, $allowed, $default = null)
{
    $value = $value ?? $default;
    if (!in_array($value, $allowed, true)) ct_fail(422, 'گزینهٔ انتخابی نامعتبر است');
    return $value;
}

function ct_id($value, $optional = false)
{
    if ($optional && ($value === null || $value === '')) return null;
    return emcore_mh_int($value);
}

function ct_range($value, $min, $max)
{
    $number = emcore_mh_int($value, $min === 0);
    if ($number < $min || $number > $max) ct_fail(422, 'عدد خارج از محدوده است');
    return $number;
}

function ct_date($value, $required = false, $past = false)
{
    if (!$required && ($value === null || $value === '')) return [null,null];
    return emcore_mh_date($value, $past ? ct_today() : '9999-12-31');
}

function ct_today() { return emcore_mh_today(); }
function ct_now() { return gmdate('Y-m-d H:i:s'); }

function ct_amount($value)
{
    if ($value === null || $value === '') return null;
    if (!is_string($value)) ct_fail(422, 'مبلغ را به صورت رشته وارد کنید');
    $value = emcore_mh_digits(trim($value));
    if (!preg_match('/^(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,4})?$/D', $value)) ct_fail(422, 'مبلغ نامعتبر است');
    return $value;
}

function ct_money($input)
{
    $amount = ct_amount($input['amount'] ?? null);
    $currency = ct_text($input['currency'] ?? null, 12);
    if (($amount === null) !== ($currency === null) || ($currency !== null && !preg_match('/^[A-Z]{3,12}$/D', $currency))) {
        ct_fail(422, 'مبلغ و واحد پول باید با هم ثبت شوند');
    }
    return ['amount'=>$amount, 'currency'=>$currency];
}

function ct_json($value)
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function ct_decode($value)
{
    if (!is_string($value)) ct_fail(422, 'ساختار درخواست نامعتبر است');
    try { $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { ct_fail(422, 'ساختار JSON نامعتبر است'); }
    if (!is_array($decoded)) ct_fail(422, 'ساختار درخواست نامعتبر است');
    return $decoded;
}

function ct_query($sql, $args = [])
{
    $stmt = emcore_db()->prepare($sql);
    $stmt->execute($args);
    return $stmt;
}
function ct_one($sql, $args = []) { return ct_query($sql,$args)->fetch(); }
function ct_all($sql, $args = []) { return ct_query($sql,$args)->fetchAll(); }

function ct_table($entity)
{
    if ($entity === 'contracts') return 'emcore_contracts';
    return 'emcore_contract_'.ct_enum($entity, [
        'parties','mines','categories','documents','versions','amendments','obligations',
        'actions','history','history_files','evidence','import_batches','sources','commands','downloads'
    ]);
}

// Only explicitly constructed field maps inside the module reach these helpers.
function ct_insert($entity, $data)
{
    ct_query('INSERT INTO '.ct_table($entity).' ('.implode(',',array_keys($data)).') VALUES ('.
        implode(',',array_fill(0,count($data),'?')).')',array_values($data));
    return emcore_db()->lastInsertId();
}

function ct_patch($entity, $id, $data)
{
    $assign = array_map(function ($key) { return $key.'=?'; },array_keys($data));
    ct_query('UPDATE '.ct_table($entity).' SET '.implode(',',$assign).' WHERE id=?',array_merge(array_values($data),[$id]));
}

function ct_row($entity, $id, $contract = null, $locked = false, $allowDeleted = false)
{
    $args = [$id];
    $sql = 'SELECT * FROM '.ct_table($entity).' WHERE id=?';
    if ($contract !== null) { $sql .= ' AND contract_id=?'; $args[] = $contract; }
    if (!$allowDeleted && in_array($entity,['contracts','versions','history','obligations'],true)) $sql .= ' AND deleted_at IS NULL';
    $row = ct_one($sql.($locked ? ' FOR UPDATE' : ''),$args);
    if (!$row) ct_fail(404,'رکورد یافت نشد');
    return $row;
}

function ct_version($row, $value)
{
    if ((int)$row['lock_version'] !== ct_id($value)) ct_fail(409,'رکورد تغییر کرده است. آن را دوباره باز کنید.');
}

function ct_actor() { return emcore_current_user()['USR_UID']; }
function ct_stamp()
{
    return ['created_by'=>ct_actor(),'updated_by'=>ct_actor(),'created_at'=>ct_now(),'updated_at'=>ct_now()];
}

function ct_audit($entity, $id, $before, $after, $contract = null, $operation = null, $verb = null)
{
    foreach (['storage_key','extracted_text'] as $private) {
        if (is_array($before)) unset($before[$private]);
        if (is_array($after)) unset($after[$private]);
    }
    emcore_audit('contracts',$verb ?? ($before ? 'update' : 'create'),'contract_'.$entity,(string)$id,
        $before,$after,['contract_id'=>$contract === null ? null : (string)$contract,'operation'=>$operation]);
}

function ct_change($entity, $row, $values, $contract, $operation = null)
{
    $values += ['updated_by'=>ct_actor(),'updated_at'=>ct_now(),'lock_version'=>(int)$row['lock_version']+1];
    ct_patch($entity,$row['id'],$values);
    $updated = array_replace($row,$values);
    ct_audit($entity,$row['id'],$row,$updated,$contract,$operation);
    return $updated;
}

function ct_mark_review($row, $operation)
{
    if ($row['review_status'] !== 'verified') return $row;
    return ct_change('contracts',$row,['review_status'=>'needs_review'],$row['id'],$operation);
}

function ct_owner($value, $default = null)
{
    $uid = ct_text($value,32) ?? $default;
    if ($uid !== null && (!preg_match('/^[a-zA-Z0-9]{32}$/D',$uid) || !ct_one(
        "SELECT u.USR_UID FROM USERS u JOIN emcore_user_permissions p ON p.usr_uid=u.USR_UID
         WHERE u.USR_UID=? AND u.USR_STATUS='ACTIVE' AND p.module_key='contracts' AND p.can_read=1",[$uid]
    ))) ct_fail(422,'مسئول باید کاربر فعال و دارای دسترسی مشاهدهٔ قراردادها باشد');
    return $uid;
}

function ct_category($id, $contract, $previous = null)
{
    $row = ct_row('categories',ct_id($id),null,true);
    if (($row['contract_id'] !== null && (string)$row['contract_id'] !== (string)$contract)
        || (!$row['is_active'] && (string)$id !== (string)$previous)) ct_fail(422,'دسته برای این قرارداد معتبر نیست');
    return $row['id'];
}

function ct_ref($entity, $value, $contract)
{
    $id = ct_id($value,true);
    return $id === null ? null : ct_row($entity,$id,$contract)['id'];
}

function ct_contract_view($row)
{
    $today = ct_today();
    $row['code'] = 'CTR-'.str_pad((string)$row['id'],8,'0',STR_PAD_LEFT);
    $row['time_status'] = ($row['ends_on'] && $row['ends_on'] < $today) ? 'expired' :
        (($row['starts_on'] && $row['starts_on'] > $today) ? 'not_started' :
        (($row['starts_on'] || $row['ends_on']) ? 'current' : 'unknown'));
    return $row;
}

function ct_public($value)
{
    if (!is_array($value)) return $value;
    unset($value['storage_key']);
    foreach ($value as $key=>$item) {
        if (is_string($key) && preg_match('/(?:^id$|_id$|^merged_into$)/',$key) && is_int($item)) $value[$key]=(string)$item;
        elseif (is_array($item)) $value[$key]=ct_public($item);
    }
    return $value;
}

function ct_page($input)
{
    return [ct_range($input['limit'] ?? 25,1,100),ct_range($input['offset'] ?? 0,0,10000000)];
}

function ct_contract_fields($input, $before)
{
    $values = [];
    foreach (['title'=>250,'official_number'=>100,'contract_type'=>80,'subject'=>500,'description'=>10000,
        'unit_name'=>150,'project_name'=>200,'site_name'=>200,'quantity_unit'=>80,'tax_note'=>500,
        'payment_terms'=>5000,'original_location'=>255,'archive_code'=>100,'custodian'=>150,'archive_notes'=>5000] as $field=>$max) {
        if (array_key_exists($field,$input)) $values[$field]=ct_text($input[$field],$max,$field==='title');
    }
    if (!$before && !isset($values['title'])) ct_fail(422,'عنوان قرارداد الزامی است');
    foreach (['signed','starts','ends','notice','retention_review'] as $key) {
        if (array_key_exists($key.'_fa',$input)) [$values[$key.'_fa'],$values[$key.'_on']]=ct_date($input[$key.'_fa']);
    }
    $values += ct_contract_money($input,$before);
    foreach ([
        'legal_status'=>['unknown','draft','awaiting_signature','active','suspended','terminated','closed'],
        'signature_status'=>['unknown','unsigned','partial','signed'],
        'original_status'=>['unknown','available','missing','digital_only']
    ] as $field=>$options) if (array_key_exists($field,$input)) $values[$field]=ct_enum($input[$field],$options);
    if (!$before || array_key_exists('owner_uid',$input)) $values['owner_uid']=ct_owner($input['owner_uid'] ?? null,ct_actor());
    if (array_key_exists('alert_days',$input)) $values['alert_days']=ct_range($input['alert_days'],1,365);
    $effective = $values + ($before ?: []);
    if (!empty($effective['starts_on']) && !empty($effective['ends_on']) && $effective['starts_on']>$effective['ends_on']) {
        ct_fail(422,'تاریخ شروع پس از پایان است');
    }
    return $values;
}

function ct_contract_money($input, $before)
{
    $values = [];
    if (array_key_exists('amount',$input) || array_key_exists('currency',$input)) $values=ct_money($input+($before ?: []));
    if (array_key_exists('amount_type',$input)) $values['amount_type']=ct_enum($input['amount_type'],['unknown','fixed','unit_rate','ceiling']);
    return $values;
}

function ct_mutate($action, $requestId, $input, $file, $handler)
{
    $db = emcore_db();
    $db->beginTransaction();
    $stored = [];
    $committing = false;
    try {
        if ($requestId !== null) {
            if (!is_string($requestId) || !preg_match('/^[a-f0-9]{32}$/D',$requestId)) ct_fail(422,'شناسهٔ درخواست نامعتبر است');
            $fingerprint = hash('sha256',ct_json([$action,$input,$file ? [$file['sha256'],$file['original_filename']] : null]));
            ct_query('INSERT INTO emcore_contract_commands (actor_uid,request_id,fingerprint,created_at)
                VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE request_id=VALUES(request_id)',[ct_actor(),$requestId,$fingerprint,ct_now()]);
            $command = ct_one('SELECT fingerprint,result FROM emcore_contract_commands WHERE actor_uid=? AND request_id=? FOR UPDATE',[ct_actor(),$requestId]);
            if (!hash_equals($command['fingerprint'],$fingerprint)) ct_fail(409,'شناسهٔ درخواست قبلاً برای محتوای دیگری استفاده شده است');
            if ($command['result'] !== null) {
                $db->commit();
                return [ct_decode($command['result']),200];
            }
        }
        $result = ct_public($handler($stored));
        if ($requestId !== null) ct_query('UPDATE emcore_contract_commands SET result=? WHERE actor_uid=? AND request_id=?',
            [ct_json($result),ct_actor(),$requestId]);
        $committing = true;
        $db->commit();
        return [$result,200];
    } catch (Throwable $e) {
        $rolledBack = $db->inTransaction() && $db->rollBack();
        // A lost connection during commit leaves its outcome uncertain. Retain bytes for reconciliation.
        if (!$committing || $rolledBack) foreach ($stored as $path) if (is_file($path)) unlink($path);
        if ($e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0),[1062,1205,1213],true)) {
            ct_fail(409,'تداخل داده یا ویرایش هم‌زمان؛ اطلاعات را دوباره بررسی کنید');
        }
        throw $e;
    }
}
