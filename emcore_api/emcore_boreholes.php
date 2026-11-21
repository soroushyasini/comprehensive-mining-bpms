<?php

require_once __DIR__ . '/_module_permissions.php';
require_once __DIR__ . '/_drilling_report_summary.php';

const EMCORE_BOREHOLE_MODULE = 'boreholes';

function emcore_borehole_query($db, $sql, $params = [])
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function emcore_borehole_text($key, $length, $required = false)
{
    $raw = $_POST[$key] ?? '';
    if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
        throw new EmcoreHttpException(422, 'مقدار ورودی نامعتبر است', [$key => 'invalid_text']);
    }
    $value = trim($raw);
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
        || mb_strlen($value, 'UTF-8') > $length || ($required && $value === '')) {
        throw new EmcoreHttpException(422, 'مقدار ورودی ناقص یا نامعتبر است', [$key => 'invalid_text']);
    }
    return $value === '' ? null : $value;
}

function emcore_borehole_digits($value)
{
    static $digits;
    if ($digits === null) {
        $digits = array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789'));
    }
    return strtr($value, $digits + ['٫' => '.']);
}

function emcore_borehole_decimal($key, $whole, $scale, $min = null, $max = null)
{
    $value = emcore_borehole_text($key, 32);
    if ($value === null) {
        return null;
    }
    $value = emcore_borehole_digits($value);
    if (!preg_match('/^-?\d{1,' . $whole . '}(?:\.\d{1,' . $scale . '})?$/D', $value)
        || ($min !== null && (float)$value < $min) || ($max !== null && (float)$value > $max)) {
        throw new EmcoreHttpException(422, 'مقدار عددی خارج از محدوده یا با دقت نامعتبر است', [$key => 'invalid_decimal']);
    }
    return $value;
}

function emcore_borehole_date($db, $key)
{
    $value = emcore_borehole_text($key, 10);
    if ($value === null) {
        return null;
    }
    $value = emcore_borehole_digits($value);
    if (!preg_match('/^(1[34]\d{2})\/(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/D', $value, $parts)
        || ((int)$parts[2] > 6 && $parts[3] === '31')) {
        throw new EmcoreHttpException(422, 'تاریخ شمسی نامعتبر است', [$key => 'invalid_date']);
    }
    $convert = $db->prepare('SELECT shamsi_slash_to_gregorian_date(?)');
    $convert->execute([$value]);
    $iso = $convert->fetchColumn();
    $convert->closeCursor();
    if (!$iso) {
        throw new EmcoreHttpException(422, 'تاریخ شمسی نامعتبر است', [$key => 'invalid_date']);
    }
    if ($parts[2] === '12' && $parts[3] === '30') {
        $convert->execute([((int)$parts[1] + 1) . '/01/01']);
        $nextYear = $convert->fetchColumn();
        if (!$nextYear || $iso >= $nextYear) {
            throw new EmcoreHttpException(422, 'تاریخ شمسی نامعتبر است', [$key => 'invalid_date']);
        }
    }
    return $value;
}

function emcore_borehole_fields($db)
{
    $start = emcore_borehole_date($db, 'profile_start_date_fa');
    $completion = emcore_borehole_date($db, 'profile_completion_date_fa');
    if ($start !== null && $completion !== null && $completion < $start) {
        throw new EmcoreHttpException(422, 'تاریخ اتمام نمی‌تواند پیش از تاریخ شروع باشد', ['profile_completion_date_fa' => 'before_start']);
    }
    $drillingStatus = emcore_borehole_text('profile_drilling_status', 24);
    if ($drillingStatus !== null && !in_array($drillingStatus, ['not_started', 'in_progress', 'paused', 'completed', 'cancelled'], true)) {
        throw new EmcoreHttpException(422, 'وضعیت حفاری نامعتبر است', ['profile_drilling_status' => 'invalid_choice']);
    }
    return [
        'mine_id' => emcore_positive_id('mine_id'),
        'borehole_code' => emcore_borehole_text('borehole_code', 100, true),
        'area_zone' => emcore_borehole_text('area_zone', 150),
        'collar_x' => emcore_borehole_decimal('collar_x', 11, 4),
        'collar_y' => emcore_borehole_decimal('collar_y', 11, 4),
        'collar_z' => emcore_borehole_decimal('collar_z', 11, 4),
        'coordinate_system' => emcore_borehole_text('coordinate_system', 100),
        'azimuth_deg' => emcore_borehole_decimal('azimuth_deg', 3, 3, 0, 360),
        'dip_deg' => emcore_borehole_decimal('dip_deg', 3, 3, -90, 90),
        'profile_depth_m' => emcore_borehole_decimal('profile_depth_m', 9, 3, 0),
        'profile_start_date_fa' => $start,
        'profile_completion_date_fa' => $completion,
        'profile_drilling_status' => $drillingStatus,
        'notes' => emcore_borehole_text('notes', 5000),
    ];
}

$action = emcore_action(['list', 'get', 'lookups', 'create', 'update']);
$write = $action === 'create' || $action === 'update';
emcore_require_permission(EMCORE_BOREHOLE_MODULE, $write ? $action : 'read');
$db = emcore_db();

if ($action === 'lookups') {
    $mines = $db->query('SELECT id, mine_name FROM emcore_mines WHERE deleted_at IS NULL ORDER BY mine_name, id')->fetchAll();
    emcore_json(['success' => true, 'data' => ['mines' => $mines], 'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions(EMCORE_BOREHOLE_MODULE)]);
}

if ($action === 'list') {
    $where = ['b.deleted_at IS NULL'];
    $params = [];
    if (emcore_borehole_text('mine_id', 20) !== null) {
        $where[] = 'b.mine_id = ?';
        $params[] = emcore_positive_id('mine_id');
    }
    $search = emcore_borehole_text('search', 100);
    if ($search !== null) {
        $where[] = "b.borehole_code LIKE ? ESCAPE '='";
        $params[] = '%' . strtr($search, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
    }
    $page = emcore_borehole_text('page', 12) === null ? 1 : emcore_positive_id('page');
    $pageSize = 50;
    $from = 'FROM emcore_boreholes b JOIN emcore_mines m ON m.id = b.mine_id AND m.deleted_at IS NULL WHERE ' . implode(' AND ', $where);
    $total = (int)emcore_borehole_query($db, "SELECT COUNT(*) {$from}", $params)->fetchColumn();
    $page = min($page, max(1, (int)ceil($total / $pageSize)));
    $offset = ($page - 1) * $pageSize;
    $rows = emcore_borehole_query($db,
        "SELECT b.id, b.mine_id, m.mine_name, b.borehole_code, b.area_zone, b.status, b.profile_drilling_status
         {$from} ORDER BY m.mine_name, b.borehole_code, b.id LIMIT {$pageSize} OFFSET {$offset}", $params)->fetchAll();
    emcore_json(['success' => true, 'data' => $rows, 'meta' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total]]);
}

if ($action === 'get') {
    $row = emcore_borehole_query($db,
        'SELECT b.*, m.mine_name FROM emcore_boreholes b JOIN emcore_mines m ON m.id = b.mine_id AND m.deleted_at IS NULL
         WHERE b.id = ? AND b.deleted_at IS NULL', [emcore_positive_id('id')])->fetch();
    if (!$row) {
        throw new EmcoreHttpException(404, 'گمانه یافت نشد');
    }
    $canReadReports = emcore_module_permissions('drilling_daily_reports')['can_read'] === 1;
    $row['daily_report_summary'] = $canReadReports ? emcore_drilling_report_boundaries($db, (int)$row['id']) : null;
    emcore_json(['success' => true, 'data' => $row]);
}

emcore_require_csrf();
$fields = emcore_borehole_fields($db);
$status = emcore_borehole_text('status', 24);
if ($status !== null && !in_array($status, ['active', 'inactive'], true)) {
    throw new EmcoreHttpException(422, 'وضعیت انتخاب گمانه نامعتبر است', ['status' => 'invalid_choice']);
}
$creating = $action === 'create';
$id = $creating ? null : emcore_positive_id('id');
$version = $creating ? 1 : emcore_positive_id('lock_version');

$db->beginTransaction();
try {
    $before = null;
    if (!$creating) {
        $before = emcore_borehole_query($db, 'SELECT * FROM emcore_boreholes WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id])->fetch();
        if (!$before) {
            throw new EmcoreHttpException(404, 'گمانه یافت نشد');
        }
        if ((int)$before['lock_version'] !== $version) {
            throw new EmcoreHttpException(409, 'شناسنامه تغییر کرده است. آن را دوباره باز کنید');
        }
        if ((int)$before['mine_locked'] === 1 && (int)$before['mine_id'] !== $fields['mine_id']) {
            throw new EmcoreHttpException(409, 'معدن گمانه‌ای که سابقهٔ گزارش حفاری دارد قابل تغییر نیست', ['mine_id' => 'locked_by_report']);
        }
    }
    if ($fields['profile_completion_date_fa'] !== null
        && $fields['profile_completion_date_fa'] !== ($before['profile_completion_date_fa'] ?? null)
        && ($_POST['completion_confirmed'] ?? null) !== '1') {
        throw new EmcoreHttpException(422, 'ثبت تاریخ اتمام حفاری را تأیید کنید', ['completion_confirmed' => 'required']);
    }
    $mine = emcore_borehole_query($db, 'SELECT id FROM emcore_mines WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$fields['mine_id']])->fetchColumn();
    if (!$mine) {
        throw new EmcoreHttpException(422, 'معدن انتخاب‌شده یافت نشد', ['mine_id' => 'invalid_mine']);
    }
    $fields['status'] = $status ?? ($before['status'] ?? 'active');
    $columns = array_keys($fields);
    if ($creating) {
        emcore_borehole_query($db, 'INSERT INTO emcore_boreholes (' . implode(', ', $columns) . ')
            VALUES (:' . implode(', :', $columns) . ')', $fields);
        $id = (int)$db->lastInsertId();
    } else {
        $assignments = array_map(function ($column) { return "$column = :$column"; }, $columns);
        emcore_borehole_query($db, 'UPDATE emcore_boreholes SET ' . implode(', ', $assignments) . ',
            lock_version = lock_version + 1, updated_at = NOW() WHERE id = :id', $fields + ['id' => $id]);
        $version++;
    }
    $after = emcore_borehole_query($db, 'SELECT * FROM emcore_boreholes WHERE id = ?', [$id])->fetch();
    emcore_audit(EMCORE_BOREHOLE_MODULE, $action, 'borehole', $id, $before, $after);
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($error instanceof PDOException && (int)($error->errorInfo[1] ?? 0) === 1062) {
        throw new EmcoreHttpException(409, 'این کد گمانه در معدن انتخاب‌شده ثبت شده است', ['borehole_code' => 'duplicate_in_mine']);
    }
    throw $error;
}

emcore_json(['success' => true, 'id' => $id, 'lock_version' => $version], $creating ? 201 : 200);
