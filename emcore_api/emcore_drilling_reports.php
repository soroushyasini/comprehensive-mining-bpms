<?php

require_once __DIR__ . '/_module_permissions.php';

const EMCORE_DRILLING_MODULE = 'drilling_daily_reports';

function emcore_drilling_decimal($name, $required = false, $maxIntegerDigits = 11)
{
    $raw = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
    if ($raw === '') {
        if ($required) {
            throw new EmcoreHttpException(422, 'اطلاعات ورودی نامعتبر است', [$name => 'required']);
        }
        return null;
    }
    $pattern = '/^\d{1,' . (int)$maxIntegerDigits . '}(?:\.\d{1,3})?$/';
    if (!preg_match($pattern, $raw)) {
        throw new EmcoreHttpException(422, 'مقدار عددی نامعتبر است', [$name => 'non_negative_decimal_required']);
    }
    return $raw;
}

function emcore_drilling_optional_int($name)
{
    $raw = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^\d{1,10}$/', $raw)) {
        throw new EmcoreHttpException(422, 'مقدار عدد صحیح نامعتبر است', [$name => 'non_negative_integer_required']);
    }
    return (int)$raw;
}

function emcore_drilling_enum($name, $allowed)
{
    $value = emcore_string($name, true, 32);
    if (!in_array($value, $allowed, true)) {
        throw new EmcoreHttpException(422, 'مقدار انتخاب‌شده نامعتبر است', [$name => 'invalid_choice']);
    }
    return $value;
}

function emcore_drilling_jalali_date($name)
{
    $date = emcore_string($name, true, 10);
    if (!preg_match('/^1[34][0-9]{2}\/(0[1-9]|1[0-2])\/([0-2][0-9]|3[01])$/', $date)) {
        throw new EmcoreHttpException(422, 'تاریخ باید با قالب YYYY/MM/DD باشد', [$name => 'invalid_jalali_date']);
    }
    return $date;
}

function emcore_drilling_time($name)
{
    $value = emcore_string($name, false, 5);
    if ($value !== null && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
        throw new EmcoreHttpException(422, 'زمان باید با قالب HH:MM باشد', [$name => 'invalid_time']);
    }
    return $value;
}

function emcore_drilling_json_array($name)
{
    $raw = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '[]';
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new EmcoreHttpException(422, 'داده ساختاریافته نامعتبر است', [$name => 'json_array_required']);
    }
    return $decoded;
}

function emcore_drilling_post_filter_id($name)
{
    $raw = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^[1-9][0-9]*$/', $raw)) {
        throw new EmcoreHttpException(422, 'شناسه فیلتر نامعتبر است', [$name => 'positive_integer_required']);
    }
    return (int)$raw;
}

function emcore_drilling_fetch_report($db, $id, $includeDeleted = false)
{
    $sql = "SELECT r.*, b.borehole_code, b.mine_id, m.mine_name,
                   g.serial_number AS rig_serial, g.display_name AS rig_name
            FROM emcore_drilling_reports r
            JOIN emcore_boreholes b ON b.id = r.borehole_id
            JOIN emcore_mines m ON m.id = b.mine_id
            JOIN emcore_drilling_rigs g ON g.id = r.rig_id
            WHERE r.id = :id" . ($includeDeleted ? '' : ' AND r.deleted_at IS NULL') . ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $id]);
    $report = $stmt->fetch();
    if (!$report) {
        return null;
    }

    $crew = $db->prepare(
        "SELECT c.id, c.role_key, c.person_id, c.worker_name_snapshot, c.worker_type, c.sort_order,
                p.first_name, p.last_name
         FROM emcore_drilling_report_crew c
         LEFT JOIN emcore_persons p ON p.id = c.person_id
         WHERE c.report_id = :id
         ORDER BY c.sort_order, c.id"
    );
    $crew->execute([':id' => $id]);
    $report['crew'] = $crew->fetchAll();

    $checklist = $db->prepare(
        "SELECT rc.item_key, rc.is_checked, rc.note, i.item_order, i.label_fa
         FROM emcore_drilling_report_checklist rc
         JOIN emcore_drilling_checklist_items i ON i.item_key = rc.item_key
         WHERE rc.report_id = :id
         ORDER BY i.item_order"
    );
    $checklist->execute([':id' => $id]);
    $report['checklist'] = $checklist->fetchAll();
    return $report;
}

function emcore_drilling_replace_crew($db, $reportId, $crewRows)
{
    if (count($crewRows) > 100) {
        throw new EmcoreHttpException(422, 'تعداد اعضای شیفت بیش از حد مجاز است');
    }

    $allowedRoles = [
        'supervisor', 'guard', 'geologist', 'driver', 'head_driller',
        'driller', 'worker', 'assistant_driller', 'additional_worker',
        'additional_assistant',
    ];
    $personLookup = $db->prepare(
        'SELECT id FROM emcore_persons WHERE id = :id AND deleted_at IS NULL AND is_active = 1 LIMIT 1'
    );
    $insert = $db->prepare(
        "INSERT INTO emcore_drilling_report_crew
            (report_id, role_key, person_id, worker_name_snapshot, worker_type, sort_order)
         VALUES
            (:report_id, :role_key, :person_id, :worker_name_snapshot, :worker_type, :sort_order)"
    );

    $db->prepare('DELETE FROM emcore_drilling_report_crew WHERE report_id = :id')
        ->execute([':id' => $reportId]);

    foreach ($crewRows as $index => $row) {
        if (!is_array($row)) {
            throw new EmcoreHttpException(422, 'اطلاعات پرسنل شیفت نامعتبر است');
        }
        $role = isset($row['role_key']) ? trim((string)$row['role_key']) : '';
        $name = isset($row['worker_name_snapshot']) ? trim((string)$row['worker_name_snapshot']) : '';
        if (!in_array($role, $allowedRoles, true) || $name === '' || mb_strlen($name, 'UTF-8') > 255) {
            throw new EmcoreHttpException(422, 'اطلاعات پرسنل شیفت نامعتبر است', ['crew_index' => $index]);
        }

        $personId = null;
        if (isset($row['person_id']) && $row['person_id'] !== '' && $row['person_id'] !== null) {
            if (!preg_match('/^[1-9][0-9]*$/', (string)$row['person_id'])) {
                throw new EmcoreHttpException(422, 'شناسه شخص نامعتبر است', ['crew_index' => $index]);
            }
            $personId = (int)$row['person_id'];
            $personLookup->execute([':id' => $personId]);
            if (!$personLookup->fetch()) {
                throw new EmcoreHttpException(422, 'شخص فعال یافت نشد', ['crew_index' => $index]);
            }
        }

        $insert->execute([
            ':report_id' => $reportId,
            ':role_key' => $role,
            ':person_id' => $personId,
            ':worker_name_snapshot' => $name,
            ':worker_type' => $personId === null ? 'temporary' : 'registered',
            ':sort_order' => $index + 1,
        ]);
    }
}

function emcore_drilling_replace_checklist($db, $reportId, $checkedKeys)
{
    $normalized = [];
    foreach ($checkedKeys as $key) {
        if (!is_scalar($key)) {
            throw new EmcoreHttpException(422, 'چک‌لیست نامعتبر است');
        }
        $normalized[trim((string)$key)] = true;
    }

    $items = $db->query(
        'SELECT item_key FROM emcore_drilling_checklist_items WHERE is_active = 1 ORDER BY item_order'
    )->fetchAll();
    $allowed = [];
    foreach ($items as $item) {
        $allowed[$item['item_key']] = true;
    }
    foreach ($normalized as $key => $unused) {
        if ($key === '' || !isset($allowed[$key])) {
            throw new EmcoreHttpException(422, 'گزینه چک‌لیست نامعتبر است', ['item_key' => $key]);
        }
    }

    $db->prepare('DELETE FROM emcore_drilling_report_checklist WHERE report_id = :id')
        ->execute([':id' => $reportId]);
    $insert = $db->prepare(
        'INSERT INTO emcore_drilling_report_checklist (report_id, item_key, is_checked)
         VALUES (:report_id, :item_key, :is_checked)'
    );
    foreach ($allowed as $key => $unused) {
        $insert->execute([
            ':report_id' => $reportId,
            ':item_key' => $key,
            ':is_checked' => isset($normalized[$key]) ? 1 : 0,
        ]);
    }
}

$action = emcore_action(['list', 'get', 'lookups', 'create', 'update', 'delete']);
$capabilityMap = [
    'list' => 'read',
    'get' => 'read',
    'lookups' => 'read',
    'create' => 'create',
    'update' => 'update',
    'delete' => 'delete',
];
emcore_require_permission(EMCORE_DRILLING_MODULE, $capabilityMap[$action]);
$db = emcore_db();

if ($action === 'lookups') {
    $mines = $db->query(
        'SELECT id, mine_name, alias_name FROM emcore_mines WHERE deleted_at IS NULL ORDER BY mine_name'
    )->fetchAll();
    $boreholes = $db->query(
        "SELECT b.id, b.mine_id, b.borehole_code, b.status
         FROM emcore_boreholes b
         JOIN emcore_mines m ON m.id = b.mine_id AND m.deleted_at IS NULL
         WHERE b.deleted_at IS NULL
         ORDER BY b.mine_id, b.borehole_code"
    )->fetchAll();
    $rigs = $db->query(
        "SELECT id, serial_number, display_name, status
         FROM emcore_drilling_rigs
         WHERE deleted_at IS NULL
         ORDER BY serial_number"
    )->fetchAll();
    $persons = $db->query(
        "SELECT id, first_name, last_name
         FROM emcore_persons
         WHERE deleted_at IS NULL AND is_active = 1
         ORDER BY last_name, first_name"
    )->fetchAll();
    $checklist = $db->query(
        "SELECT item_key, item_order, label_fa
         FROM emcore_drilling_checklist_items
         WHERE is_active = 1
         ORDER BY item_order"
    )->fetchAll();
    emcore_json([
        'success' => true,
        'data' => [
            'mines' => $mines,
            'boreholes' => $boreholes,
            'rigs' => $rigs,
            'persons' => $persons,
            'checklist' => $checklist,
        ],
        'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions(EMCORE_DRILLING_MODULE),
    ]);
}

if ($action === 'list') {
    $where = ['r.deleted_at IS NULL'];
    $params = [];
    $mineId = emcore_drilling_post_filter_id('mine_id');
    $boreholeId = emcore_drilling_post_filter_id('borehole_id');
    $rigId = emcore_drilling_post_filter_id('rig_id');
    if ($mineId !== null) {
        $where[] = 'b.mine_id = :mine_id';
        $params[':mine_id'] = $mineId;
    }
    if ($boreholeId !== null) {
        $where[] = 'r.borehole_id = :borehole_id';
        $params[':borehole_id'] = $boreholeId;
    }
    if ($rigId !== null) {
        $where[] = 'r.rig_id = :rig_id';
        $params[':rig_id'] = $rigId;
    }
    $shift = emcore_string('shift', false, 10);
    if ($shift !== null) {
        if (!in_array($shift, ['DAY', 'NIGHT'], true)) {
            throw new EmcoreHttpException(422, 'شیفت نامعتبر است');
        }
        $where[] = 'r.shift = :shift';
        $params[':shift'] = $shift;
    }
    foreach (['date_from_fa' => '>=', 'date_to_fa' => '<='] as $name => $operator) {
        $value = emcore_string($name, false, 10);
        if ($value !== null) {
            if (!preg_match('/^1[34][0-9]{2}\/(0[1-9]|1[0-2])\/([0-2][0-9]|3[01])$/', $value)) {
                throw new EmcoreHttpException(422, 'تاریخ فیلتر نامعتبر است', [$name => 'invalid_jalali_date']);
            }
            $where[] = 'r.report_date_fa ' . $operator . ' :' . $name;
            $params[':' . $name] = $value;
        }
    }
    $search = emcore_string('search', false, 100);
    if ($search !== null) {
        $where[] = '(r.report_number LIKE :search OR r.legacy_form_serial LIKE :search OR r.operation_description LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    $page = isset($_POST['page']) && preg_match('/^[1-9][0-9]*$/', (string)$_POST['page'])
        ? (int)$_POST['page'] : 1;
    $pageSize = isset($_POST['page_size']) && preg_match('/^[1-9][0-9]*$/', (string)$_POST['page_size'])
        ? min(200, (int)$_POST['page_size']) : 100;
    $offset = ($page - 1) * $pageSize;
    $whereSql = implode(' AND ', $where);

    $count = $db->prepare(
        "SELECT COUNT(*)
         FROM emcore_drilling_reports r
         JOIN emcore_boreholes b ON b.id = r.borehole_id
         WHERE {$whereSql}"
    );
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $sql = "SELECT r.id, r.legacy_id, r.report_number, r.legacy_form_serial,
                   r.report_date_fa, r.shift, r.rig_hours, r.drill_start_depth,
                   r.drill_end_depth, r.drill_amount, r.operation_state,
                   r.stop_duration_hours, r.updated_at, b.borehole_code, b.mine_id,
                   m.mine_name, g.serial_number AS rig_serial,
                   (SELECT COUNT(*) FROM emcore_drilling_report_crew c WHERE c.report_id = r.id) AS crew_count
            FROM emcore_drilling_reports r
            JOIN emcore_boreholes b ON b.id = r.borehole_id
            JOIN emcore_mines m ON m.id = b.mine_id
            JOIN emcore_drilling_rigs g ON g.id = r.rig_id
            WHERE {$whereSql}
            ORDER BY r.report_date_en DESC, r.shift, r.id DESC
            LIMIT {$pageSize} OFFSET {$offset}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    emcore_json([
        'success' => true,
        'data' => $stmt->fetchAll(),
        'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total],
        'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions(EMCORE_DRILLING_MODULE),
    ]);
}

if ($action === 'get') {
    $id = emcore_positive_id('id');
    $report = emcore_drilling_fetch_report($db, $id);
    if (!$report) {
        throw new EmcoreHttpException(404, 'گزارش حفاری یافت نشد');
    }
    emcore_json(['success' => true, 'data' => $report]);
}

emcore_require_csrf();

if ($action === 'delete') {
    $id = emcore_positive_id('id');
    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT id FROM emcore_drilling_reports WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([':id' => $id]);
        if (!$lock->fetch()) {
            throw new EmcoreHttpException(404, 'گزارش حفاری یافت نشد');
        }
        $before = emcore_drilling_fetch_report($db, $id);
        $db->prepare('UPDATE emcore_drilling_reports SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $id]);
        $after = emcore_drilling_fetch_report($db, $id, true);
        emcore_audit(EMCORE_DRILLING_MODULE, 'delete', 'drilling_report', $id, $before, $after);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    emcore_json(['success' => true]);
}

$boreholeId = emcore_positive_id('borehole_id');
$rigId = emcore_positive_id('rig_id');
$reportDateFa = emcore_drilling_jalali_date('report_date_fa');
$shift = emcore_drilling_enum('shift', ['DAY', 'NIGHT']);
$operationState = emcore_drilling_enum('operation_state', ['drilling', 'partially_stopped', 'no_drilling']);
$drillStart = emcore_drilling_decimal('drill_start_depth', true);
$drillEnd = emcore_drilling_decimal('drill_end_depth', true);
if ((float)$drillEnd < (float)$drillStart) {
    throw new EmcoreHttpException(422, 'عمق پایان نمی‌تواند کمتر از عمق شروع باشد');
}
$drillAmount = number_format((float)$drillEnd - (float)$drillStart, 2, '.', '');
if ($operationState === 'no_drilling' && abs((float)$drillAmount) > 0.0001) {
    throw new EmcoreHttpException(422, 'در وضعیت بدون حفاری، عمق شروع و پایان باید برابر باشند');
}

$stopCauses = emcore_string('stop_causes', false, 255);
$stopDuration = emcore_drilling_decimal('stop_duration_hours', false, 2);
if ($operationState === 'drilling') {
    $stopCauses = null;
    $stopDuration = null;
} elseif ($operationState === 'partially_stopped') {
    if ($stopCauses === null || $stopDuration === null || (float)$stopDuration <= 0 || (float)$stopDuration > 12) {
        throw new EmcoreHttpException(422, 'برای توقف جزئی، علت و مدت بین صفر تا ۱۲ ساعت الزامی است');
    }
} else {
    if ($stopCauses === null) {
        throw new EmcoreHttpException(422, 'علت عدم حفاری الزامی است');
    }
    $stopDuration = null;
}

$borehole = $db->prepare(
    "SELECT b.id FROM emcore_boreholes b
     JOIN emcore_mines m ON m.id = b.mine_id AND m.deleted_at IS NULL
     WHERE b.id = :id AND b.deleted_at IS NULL LIMIT 1"
);
$borehole->execute([':id' => $boreholeId]);
if (!$borehole->fetch()) {
    throw new EmcoreHttpException(422, 'گمانه فعال یافت نشد');
}
$rig = $db->prepare(
    "SELECT id FROM emcore_drilling_rigs
     WHERE id = :id AND deleted_at IS NULL AND status <> 'retired' LIMIT 1"
);
$rig->execute([':id' => $rigId]);
if (!$rig->fetch()) {
    throw new EmcoreHttpException(422, 'دستگاه حفاری فعال یافت نشد');
}
$convert = $db->prepare('SELECT shamsi_slash_to_gregorian_date(:date_fa)');
$convert->execute([':date_fa' => $reportDateFa]);
$reportDateEn = $convert->fetchColumn();
if (!$reportDateEn) {
    throw new EmcoreHttpException(422, 'تاریخ شمسی معتبر نیست');
}

$crewRows = emcore_drilling_json_array('crew_json');
$checkedKeys = emcore_drilling_json_array('checklist_json');
$values = [
    ':legacy_form_serial' => emcore_string('legacy_form_serial', false, 100),
    ':borehole_id' => $boreholeId,
    ':rig_id' => $rigId,
    ':report_date_fa' => $reportDateFa,
    ':report_date_en' => $reportDateEn,
    ':shift' => $shift,
    ':start_time' => emcore_drilling_time('start_time'),
    ':end_time' => emcore_drilling_time('end_time'),
    ':rig_hours' => emcore_drilling_decimal('rig_hours', false),
    ':drill_start_depth' => $drillStart,
    ':drill_end_depth' => $drillEnd,
    ':drill_amount' => $drillAmount,
    ':corebox_start' => emcore_drilling_optional_int('corebox_start'),
    ':corebox_end' => emcore_drilling_optional_int('corebox_end'),
    ':water_amount' => emcore_drilling_decimal('water_amount', false) ?: '0',
    ':diesel_amount' => emcore_drilling_decimal('diesel_amount', false) ?: '0',
    ':oil_amount' => emcore_drilling_decimal('oil_amount', false) ?: '0',
    ':supermix_amount' => emcore_drilling_decimal('supermix_amount', false) ?: '0',
    ':bentonite_amount' => emcore_drilling_decimal('bentonite_amount', false) ?: '0',
    ':soda_amount' => emcore_drilling_decimal('soda_amount', false) ?: '0',
    ':cement_amount' => emcore_drilling_decimal('cement_amount', false) ?: '0',
    ':lv_pack' => emcore_string('lv_pack', false, 100),
    ':operation_state' => $operationState,
    ':stop_causes' => $stopCauses,
    ':stop_duration_hours' => $stopDuration,
    ':incoming_equipment' => emcore_string('incoming_equipment', false, 10000),
    ':outgoing_equipment' => emcore_string('outgoing_equipment', false, 10000),
    ':checklist_notes' => emcore_string('checklist_notes', false, 5000),
    ':operation_description' => emcore_string('operation_description', false, 20000),
    ':issues_suggestions' => emcore_string('issues_suggestions', false, 10000),
];

$db->beginTransaction();
try {
    if ($action === 'create') {
        $user = emcore_current_user();
        $stmt = $db->prepare(
            "INSERT INTO emcore_drilling_reports
                (legacy_form_serial, borehole_id, rig_id, report_date_fa, report_date_en,
                 shift, start_time, end_time, rig_hours, drill_start_depth, drill_end_depth,
                 drill_amount, corebox_start, corebox_end, water_amount, diesel_amount,
                 oil_amount, supermix_amount, bentonite_amount, soda_amount, cement_amount,
                 lv_pack, operation_state, stop_causes, stop_duration_hours,
                 incoming_equipment, outgoing_equipment, checklist_notes,
                 operation_description, issues_suggestions, created_by_usr_uid)
             VALUES
                (:legacy_form_serial, :borehole_id, :rig_id, :report_date_fa, :report_date_en,
                 :shift, :start_time, :end_time, :rig_hours, :drill_start_depth, :drill_end_depth,
                 :drill_amount, :corebox_start, :corebox_end, :water_amount, :diesel_amount,
                 :oil_amount, :supermix_amount, :bentonite_amount, :soda_amount, :cement_amount,
                 :lv_pack, :operation_state, :stop_causes, :stop_duration_hours,
                 :incoming_equipment, :outgoing_equipment, :checklist_notes,
                 :operation_description, :issues_suggestions, :created_by_usr_uid)"
        );
        $values[':created_by_usr_uid'] = $user['USR_UID'];
        $stmt->execute($values);
        $id = (int)$db->lastInsertId();
        $reportNumber = 'DR-' . str_pad((string)$id, 8, '0', STR_PAD_LEFT);
        $db->prepare('UPDATE emcore_drilling_reports SET report_number = :number WHERE id = :id')
            ->execute([':number' => $reportNumber, ':id' => $id]);
        emcore_drilling_replace_crew($db, $id, $crewRows);
        emcore_drilling_replace_checklist($db, $id, $checkedKeys);
        $after = emcore_drilling_fetch_report($db, $id);
        emcore_audit(EMCORE_DRILLING_MODULE, 'create', 'drilling_report', $id, null, $after);
        $db->commit();
        emcore_json(['success' => true, 'id' => $id, 'report_number' => $reportNumber], 201);
    }

    $id = emcore_positive_id('id');
    $lock = $db->prepare('SELECT id FROM emcore_drilling_reports WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
    $lock->execute([':id' => $id]);
    if (!$lock->fetch()) {
        throw new EmcoreHttpException(404, 'گزارش حفاری یافت نشد');
    }
    $before = emcore_drilling_fetch_report($db, $id);
    $values[':id'] = $id;
    $stmt = $db->prepare(
        "UPDATE emcore_drilling_reports SET
            legacy_form_serial = :legacy_form_serial, borehole_id = :borehole_id,
            rig_id = :rig_id, report_date_fa = :report_date_fa, report_date_en = :report_date_en,
            shift = :shift, start_time = :start_time, end_time = :end_time,
            rig_hours = :rig_hours, drill_start_depth = :drill_start_depth,
            drill_end_depth = :drill_end_depth, drill_amount = :drill_amount,
            corebox_start = :corebox_start, corebox_end = :corebox_end,
            water_amount = :water_amount, diesel_amount = :diesel_amount,
            oil_amount = :oil_amount, supermix_amount = :supermix_amount,
            bentonite_amount = :bentonite_amount, soda_amount = :soda_amount,
            cement_amount = :cement_amount, lv_pack = :lv_pack,
            operation_state = :operation_state, stop_causes = :stop_causes,
            stop_duration_hours = :stop_duration_hours,
            incoming_equipment = :incoming_equipment, outgoing_equipment = :outgoing_equipment,
            checklist_notes = :checklist_notes, operation_description = :operation_description,
            issues_suggestions = :issues_suggestions, updated_at = NOW()
         WHERE id = :id AND deleted_at IS NULL"
    );
    $stmt->execute($values);
    emcore_drilling_replace_crew($db, $id, $crewRows);
    emcore_drilling_replace_checklist($db, $id, $checkedKeys);
    $after = emcore_drilling_fetch_report($db, $id);
    emcore_audit(EMCORE_DRILLING_MODULE, 'update', 'drilling_report', $id, $before, $after);
    $db->commit();
    emcore_json(['success' => true, 'id' => $id]);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
