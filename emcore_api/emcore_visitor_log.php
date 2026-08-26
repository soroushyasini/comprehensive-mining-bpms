<?php

require_once __DIR__ . '/_module_permissions.php';

const EMCORE_VISITOR_MODULE = 'visitor_log';

function emcore_visit_jalali_date($name, $required = true)
{
    $date = emcore_string($name, $required, 10);
    if ($date !== null && !preg_match('/^1[34][0-9]{2}\/(0[1-9]|1[0-2])\/([0-2][0-9]|3[01])$/', $date)) {
        throw new EmcoreHttpException(422, 'تاریخ باید با قالب YYYY/MM/DD باشد', [$name => 'invalid_jalali_date']);
    }
    return $date;
}

function emcore_visit_time($name, $required = true)
{
    $value = emcore_string($name, $required, 5);
    if ($value !== null && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
        throw new EmcoreHttpException(422, 'زمان باید با قالب HH:MM باشد', [$name => 'invalid_time']);
    }
    return $value;
}

function emcore_visit_optional_uid($name)
{
    $value = emcore_string($name, false, 32);
    if ($value !== null && !preg_match('/^[A-Za-z0-9]{32}$/', $value)) {
        throw new EmcoreHttpException(422, 'شناسه کاربر نامعتبر است', [$name => 'invalid_usr_uid']);
    }
    return $value;
}

function emcore_visit_filter_page($name, $default, $maximum = null)
{
    $raw = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
    if ($raw === '') {
        return $default;
    }
    if (!preg_match('/^[1-9][0-9]*$/', $raw)) {
        throw new EmcoreHttpException(422, 'شماره صفحه نامعتبر است', [$name => 'positive_integer_required']);
    }
    $value = (int)$raw;
    return $maximum === null ? $value : min($maximum, $value);
}

function emcore_visit_convert_date($db, $dateFa)
{
    $stmt = $db->prepare('SELECT shamsi_slash_to_gregorian_date(:date_fa)');
    $stmt->execute([':date_fa' => $dateFa]);
    $dateEn = $stmt->fetchColumn();
    if (!$dateEn) {
        throw new EmcoreHttpException(422, 'تاریخ شمسی معتبر نیست');
    }
    return $dateEn;
}

function emcore_visit_resolve_host($db, $hostUsrUid, $manualName)
{
    if ($hostUsrUid === null) {
        if ($manualName === null) {
            throw new EmcoreHttpException(422, 'نام شخص ملاقات‌شونده الزامی است', ['host_name_snapshot' => 'required']);
        }
        return $manualName;
    }

    $stmt = $db->prepare(
        "SELECT USR_USERNAME, USR_FIRSTNAME, USR_LASTNAME
         FROM USERS
         WHERE USR_UID = :usr_uid AND USR_STATUS = 'ACTIVE'
         LIMIT 1"
    );
    $stmt->execute([':usr_uid' => $hostUsrUid]);
    $host = $stmt->fetch();
    if (!$host) {
        throw new EmcoreHttpException(422, 'کاربر میزبان فعال یافت نشد', ['host_usr_uid' => 'active_user_required']);
    }
    $name = trim(trim((string)$host['USR_FIRSTNAME']) . ' ' . trim((string)$host['USR_LASTNAME']));
    return $name !== '' ? $name : (string)$host['USR_USERNAME'];
}

function emcore_visit_fetch($db, $id, $includeDeleted = false)
{
    $sql = "SELECT v.*,
                   CASE WHEN v.exited_at IS NULL THEN 'inside' ELSE 'completed' END AS visit_status,
                   TIMESTAMPDIFF(MINUTE, v.entered_at, COALESCE(v.exited_at, NOW())) AS duration_minutes,
                   hu.USR_USERNAME AS host_username,
                   TRIM(CONCAT_WS(' ', hu.USR_FIRSTNAME, hu.USR_LASTNAME)) AS host_current_name,
                   cu.USR_USERNAME AS created_by_username,
                   TRIM(CONCAT_WS(' ', cu.USR_FIRSTNAME, cu.USR_LASTNAME)) AS created_by_name
            FROM emcore_visits v
            LEFT JOIN USERS hu ON hu.USR_UID = v.host_usr_uid
            LEFT JOIN USERS cu ON cu.USR_UID = v.created_by_usr_uid
            WHERE v.id = :id" . ($includeDeleted ? '' : ' AND v.deleted_at IS NULL') . ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function emcore_visit_input($db)
{
    $visitorName = emcore_string('visitor_name', true, 200);
    $organizationName = emcore_string('organization_name', false, 200);
    $purpose = emcore_string('purpose', true, 1000);
    $hostUsrUid = emcore_visit_optional_uid('host_usr_uid');
    $manualHost = emcore_string('host_name_snapshot', false, 200);
    $hostName = emcore_visit_resolve_host($db, $hostUsrUid, $manualHost);
    $visitDateFa = emcore_visit_jalali_date('visit_date_fa');
    $visitDateEn = emcore_visit_convert_date($db, $visitDateFa);
    $entryTime = emcore_visit_time('entry_time');
    $enteredAt = $visitDateEn . ' ' . $entryTime . ':00';

    $exitDateFa = emcore_visit_jalali_date('exit_date_fa', false);
    $exitTime = emcore_visit_time('exit_time', false);
    if (($exitDateFa === null) !== ($exitTime === null)) {
        throw new EmcoreHttpException(422, 'تاریخ و ساعت خروج باید با هم وارد شوند', [
            'exit_date_fa' => 'both_or_neither',
            'exit_time' => 'both_or_neither',
        ]);
    }
    $exitedAt = null;
    if ($exitDateFa !== null) {
        $exitDateEn = emcore_visit_convert_date($db, $exitDateFa);
        $exitedAt = $exitDateEn . ' ' . $exitTime . ':00';
        if ($exitedAt < $enteredAt) {
            throw new EmcoreHttpException(422, 'زمان خروج نمی‌تواند پیش از زمان ورود باشد');
        }
    }

    return [
        ':visitor_name' => $visitorName,
        ':organization_name' => $organizationName,
        ':purpose' => $purpose,
        ':host_usr_uid' => $hostUsrUid,
        ':host_name_snapshot' => $hostName,
        ':visit_date_fa' => $visitDateFa,
        ':visit_date_en' => $visitDateEn,
        ':entered_at' => $enteredAt,
        ':exited_at' => $exitedAt,
        ':notes' => emcore_string('notes', false, 5000),
    ];
}

$action = emcore_action(['list', 'get', 'lookups', 'create', 'update', 'checkout', 'delete']);
$capabilityMap = [
    'list' => 'read',
    'get' => 'read',
    'lookups' => 'read',
    'create' => 'create',
    'update' => 'update',
    'checkout' => 'update',
    'delete' => 'delete',
];
emcore_require_permission(EMCORE_VISITOR_MODULE, $capabilityMap[$action]);
$db = emcore_db();

if ($action === 'lookups') {
    $hosts = $db->query(
        "SELECT USR_UID, USR_USERNAME, USR_FIRSTNAME, USR_LASTNAME,
                TRIM(CONCAT_WS(' ', USR_FIRSTNAME, USR_LASTNAME)) AS display_name
         FROM USERS
         WHERE USR_STATUS = 'ACTIVE'
         ORDER BY USR_LASTNAME, USR_FIRSTNAME, USR_USERNAME"
    )->fetchAll();
    emcore_json([
        'success' => true,
        'data' => ['hosts' => $hosts],
        'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions(EMCORE_VISITOR_MODULE),
    ]);
}

if ($action === 'list') {
    $where = ['v.deleted_at IS NULL'];
    $params = [];

    $status = emcore_string('status', false, 16);
    if ($status !== null) {
        if (!in_array($status, ['inside', 'completed'], true)) {
            throw new EmcoreHttpException(422, 'وضعیت فیلتر نامعتبر است');
        }
        $where[] = $status === 'inside' ? 'v.exited_at IS NULL' : 'v.exited_at IS NOT NULL';
    }

    $hostUsrUid = emcore_visit_optional_uid('host_usr_uid');
    if ($hostUsrUid !== null) {
        $where[] = 'v.host_usr_uid = :host_usr_uid';
        $params[':host_usr_uid'] = $hostUsrUid;
    }

    $dateFrom = emcore_visit_jalali_date('date_from_fa', false);
    $dateTo = emcore_visit_jalali_date('date_to_fa', false);
    if ($dateFrom !== null) {
        $where[] = 'v.visit_date_fa >= :date_from_fa';
        $params[':date_from_fa'] = $dateFrom;
    }
    if ($dateTo !== null) {
        $where[] = 'v.visit_date_fa <= :date_to_fa';
        $params[':date_to_fa'] = $dateTo;
    }
    if ($dateFrom !== null && $dateTo !== null && $dateTo < $dateFrom) {
        throw new EmcoreHttpException(422, 'بازه تاریخ نامعتبر است');
    }

    $search = emcore_string('search', false, 100);
    if ($search !== null) {
        $where[] = '(v.visitor_name LIKE :search_visitor OR v.organization_name LIKE :search_organization '
            . 'OR v.purpose LIKE :search_purpose OR v.host_name_snapshot LIKE :search_host)';
        foreach ([':search_visitor', ':search_organization', ':search_purpose', ':search_host'] as $key) {
            $params[$key] = '%' . $search . '%';
        }
    }

    $page = emcore_visit_filter_page('page', 1);
    $pageSize = emcore_visit_filter_page('page_size', 50, 200);
    $offset = ($page - 1) * $pageSize;
    $whereSql = implode(' AND ', $where);

    $count = $db->prepare("SELECT COUNT(*) FROM emcore_visits v WHERE {$whereSql}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = $db->prepare(
        "SELECT v.id, v.visitor_name, v.organization_name, v.purpose,
                v.host_usr_uid, v.host_name_snapshot, v.visit_date_fa,
                v.entered_at, v.exited_at,
                CASE WHEN v.exited_at IS NULL THEN 'inside' ELSE 'completed' END AS visit_status,
                TIMESTAMPDIFF(MINUTE, v.entered_at, COALESCE(v.exited_at, NOW())) AS duration_minutes
         FROM emcore_visits v
         WHERE {$whereSql}
         ORDER BY (v.exited_at IS NULL) DESC, v.entered_at DESC, v.id DESC
         LIMIT {$pageSize} OFFSET {$offset}"
    );
    $stmt->execute($params);

    $summary = $db->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(exited_at IS NULL), 0) AS inside_count,
                COALESCE(SUM(exited_at IS NOT NULL), 0) AS completed_count,
                COALESCE(SUM(visit_date_en = CURDATE()), 0) AS today_count
         FROM emcore_visits
         WHERE deleted_at IS NULL"
    )->fetch();

    emcore_json([
        'success' => true,
        'data' => $stmt->fetchAll(),
        'summary' => $summary,
        'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total],
        'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions(EMCORE_VISITOR_MODULE),
    ]);
}

if ($action === 'get') {
    $id = emcore_positive_id('id');
    $visit = emcore_visit_fetch($db, $id);
    if (!$visit) {
        throw new EmcoreHttpException(404, 'رکورد مراجعه یافت نشد');
    }
    emcore_json(['success' => true, 'data' => $visit]);
}

emcore_require_csrf();

if ($action === 'delete') {
    $id = emcore_positive_id('id');
    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT id FROM emcore_visits WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
        $lock->execute([':id' => $id]);
        if (!$lock->fetch()) {
            throw new EmcoreHttpException(404, 'رکورد مراجعه یافت نشد');
        }
        $before = emcore_visit_fetch($db, $id);
        $user = emcore_current_user();
        $db->prepare(
            'UPDATE emcore_visits SET deleted_at = NOW(), updated_at = NOW(), updated_by_usr_uid = :usr_uid WHERE id = :id'
        )->execute([':usr_uid' => $user['USR_UID'], ':id' => $id]);
        $after = emcore_visit_fetch($db, $id, true);
        emcore_audit(EMCORE_VISITOR_MODULE, 'delete', 'visit', $id, $before, $after);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    emcore_json(['success' => true]);
}

if ($action === 'checkout') {
    $id = emcore_positive_id('id');
    $db->beginTransaction();
    try {
        $lock = $db->prepare(
            'SELECT entered_at, exited_at FROM emcore_visits WHERE id = :id AND deleted_at IS NULL FOR UPDATE'
        );
        $lock->execute([':id' => $id]);
        $row = $lock->fetch();
        if (!$row) {
            throw new EmcoreHttpException(404, 'رکورد مراجعه یافت نشد');
        }
        if ($row['exited_at'] !== null) {
            throw new EmcoreHttpException(409, 'خروج این مراجعه‌کننده قبلاً ثبت شده است');
        }
        $now = $db->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')")->fetchColumn();
        if ($now < $row['entered_at']) {
            throw new EmcoreHttpException(422, 'زمان فعلی پیش از زمان ورود ثبت‌شده است');
        }
        $before = emcore_visit_fetch($db, $id);
        $user = emcore_current_user();
        $db->prepare(
            'UPDATE emcore_visits SET exited_at = NOW(), updated_at = NOW(), updated_by_usr_uid = :usr_uid WHERE id = :id'
        )->execute([':usr_uid' => $user['USR_UID'], ':id' => $id]);
        $after = emcore_visit_fetch($db, $id);
        emcore_audit(EMCORE_VISITOR_MODULE, 'update', 'visit', $id, $before, $after, ['operation' => 'checkout']);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    emcore_json(['success' => true, 'data' => $after]);
}

$values = emcore_visit_input($db);
$user = emcore_current_user();
$db->beginTransaction();
try {
    if ($action === 'create') {
        $stmt = $db->prepare(
            "INSERT INTO emcore_visits
                (visitor_name, organization_name, purpose, host_usr_uid, host_name_snapshot,
                 visit_date_fa, visit_date_en, entered_at, exited_at, notes,
                 created_by_usr_uid, updated_by_usr_uid, created_at, updated_at)
             VALUES
                (:visitor_name, :organization_name, :purpose, :host_usr_uid, :host_name_snapshot,
                 :visit_date_fa, :visit_date_en, :entered_at, :exited_at, :notes,
                 :created_by_usr_uid, :updated_by_usr_uid, NOW(), NOW())"
        );
        $values[':created_by_usr_uid'] = $user['USR_UID'];
        $values[':updated_by_usr_uid'] = $user['USR_UID'];
        $stmt->execute($values);
        $id = (int)$db->lastInsertId();
        $after = emcore_visit_fetch($db, $id);
        emcore_audit(EMCORE_VISITOR_MODULE, 'create', 'visit', $id, null, $after);
        $db->commit();
        emcore_json(['success' => true, 'id' => $id, 'data' => $after], 201);
    }

    $id = emcore_positive_id('id');
    $lock = $db->prepare('SELECT id FROM emcore_visits WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
    $lock->execute([':id' => $id]);
    if (!$lock->fetch()) {
        throw new EmcoreHttpException(404, 'رکورد مراجعه یافت نشد');
    }
    $before = emcore_visit_fetch($db, $id);
    $values[':updated_by_usr_uid'] = $user['USR_UID'];
    $values[':id'] = $id;
    $stmt = $db->prepare(
        "UPDATE emcore_visits SET
            visitor_name = :visitor_name,
            organization_name = :organization_name,
            purpose = :purpose,
            host_usr_uid = :host_usr_uid,
            host_name_snapshot = :host_name_snapshot,
            visit_date_fa = :visit_date_fa,
            visit_date_en = :visit_date_en,
            entered_at = :entered_at,
            exited_at = :exited_at,
            notes = :notes,
            updated_by_usr_uid = :updated_by_usr_uid,
            updated_at = NOW()
         WHERE id = :id AND deleted_at IS NULL"
    );
    $stmt->execute($values);
    $after = emcore_visit_fetch($db, $id);
    emcore_audit(EMCORE_VISITOR_MODULE, 'update', 'visit', $id, $before, $after, ['operation' => 'edit']);
    $db->commit();
    emcore_json(['success' => true, 'id' => $id, 'data' => $after]);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}