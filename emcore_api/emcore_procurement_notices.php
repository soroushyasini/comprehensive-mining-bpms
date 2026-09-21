<?php

require_once __DIR__ . '/_procurement_storage.php';

const EMCORE_PROCUREMENT_MODULE = 'procurement_notices';

function emcore_procurement_enum($name, $allowed, $required = true)
{
    $value = emcore_string($name, $required, 40);
    if ($value !== null && !in_array($value, $allowed, true)) {
        throw new EmcoreHttpException(422, 'مقدار ورودی نامعتبر است', [$name => 'invalid_value']);
    }
    return $value;
}

function emcore_procurement_page_value($name, $default, $maximum = null)
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

function emcore_procurement_bounded_int($name, $default, $minimum, $maximum)
{
    $raw = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
    if ($raw === '') {
        return $default;
    }
    if (!preg_match('/^[0-9]{1,10}$/', $raw)) {
        throw new EmcoreHttpException(422, 'عدد واردشده نامعتبر است', [$name => 'integer_required']);
    }
    $value = (int)$raw;
    if ($value < $minimum || $value > $maximum) {
        throw new EmcoreHttpException(422, 'عدد واردشده خارج از بازه مجاز است', [$name => 'out_of_range']);
    }
    return $value;
}

function emcore_procurement_nullable_bool($name)
{
    $raw = isset($_POST[$name]) ? strtolower(trim((string)$_POST[$name])) : '';
    if ($raw === '') {
        return null;
    }
    if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
        return 1;
    }
    if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
        return 0;
    }
    throw new EmcoreHttpException(422, 'مقدار وضعیت تمدید نامعتبر است', [$name => 'nullable_boolean_required']);
}

function emcore_procurement_jalali_date($db, $name, $required = false)
{
    $value = emcore_string($name, $required, 10);
    if ($value === null) {
        return [null, null];
    }
    if (!preg_match('/^1[34][0-9]{2}\/(0[1-9]|1[0-2])\/([0-2][0-9]|3[01])$/', $value)) {
        throw new EmcoreHttpException(422, 'تاریخ باید با قالب YYYY/MM/DD باشد', [
            $name => 'invalid_jalali_date',
        ]);
    }
    $stmt = $db->prepare('SELECT shamsi_slash_to_gregorian_date(:date_fa)');
    $stmt->execute([':date_fa' => $value]);
    $gregorian = $stmt->fetchColumn();
    if (!$gregorian) {
        throw new EmcoreHttpException(422, 'تاریخ شمسی معتبر نیست', [$name => 'invalid_jalali_date']);
    }
    return [$value, $gregorian];
}

function emcore_procurement_input($db)
{
    list($registeredFa, $registeredEn) = emcore_procurement_jalali_date($db, 'registered_on_fa', true);
    list($documentsFa, $documentsEn) = emcore_procurement_jalali_date($db, 'documents_deadline_fa');
    list($responseFa, $responseEn) = emcore_procurement_jalali_date($db, 'response_deadline_fa');
    if ($documentsEn !== null && $responseEn !== null && $documentsEn > $responseEn) {
        throw new EmcoreHttpException(422, 'مهلت دریافت اسناد نمی‌تواند پس از مهلت پاسخ باشد', [
            'documents_deadline_fa' => 'after_response_deadline',
        ]);
    }

    $participationStatus = emcore_procurement_enum('participation_status', [
        'registered', 'interested', 'documents_submitted', 'won', 'lost',
    ]);
    $interestReason = emcore_string('interest_reason', false, 5000);
    if ($participationStatus !== 'interested') {
        $interestReason = null;
    }
    $responsibleUnit = emcore_string('responsible_unit', false, 64);
    $categoryName = emcore_string('category_name', false, 255);
    $subcategoryName = emcore_string('subcategory_name', false, 255);
    $productName = emcore_string('product_name', false, 255);
    $drillingArea = emcore_string('drilling_area', false, 2000);
    if ($responsibleUnit !== 'بازرگانی') {
        $categoryName = null;
        $subcategoryName = null;
        $productName = null;
    }
    if (!in_array($responsibleUnit, ['حفاری', 'محدوده معدنی'], true)) {
        $drillingArea = null;
    }

    return [
        ':notice_type' => emcore_procurement_enum('notice_type', ['tender', 'auction']),
        ':source_name' => emcore_string('source_name', false, 255),
        ':supplier_name' => emcore_string('supplier_name', false, 255),
        ':title' => emcore_string('title', true, 1000),
        ':quantity_text' => emcore_string('quantity_text', false, 255),
        ':reference_number' => emcore_string('reference_number', false, 255),
        ':amount_text' => emcore_string('amount_text', false, 255),
        ':currency' => emcore_string('currency', false, 32),
        ':contracting_authority' => emcore_string('contracting_authority', false, 255),
        ':submission_method' => emcore_procurement_enum(
            'submission_method',
            ['physical', 'online', 'other'],
            false
        ),
        ':delivery_term' => emcore_string('delivery_term', false, 32),
        ':primary_guarantee' => emcore_string('primary_guarantee', false, 255),
        ':secondary_guarantee' => emcore_string('secondary_guarantee', false, 255),
        ':registered_on_fa' => $registeredFa,
        ':registered_on_en' => $registeredEn,
        ':documents_deadline_fa' => $documentsFa,
        ':documents_deadline_en' => $documentsEn,
        ':response_deadline_fa' => $responseFa,
        ':response_deadline_en' => $responseEn,
        ':alert_lead_days' => emcore_procurement_bounded_int('alert_lead_days', 5, 0, 365),
        ':responsible_unit' => $responsibleUnit,
        ':category_name' => $categoryName,
        ':subcategory_name' => $subcategoryName,
        ':product_name' => $productName,
        ':drilling_area' => $drillingArea,
        ':participation_status' => $participationStatus,
        ':interest_reason' => $interestReason,
        ':is_extended' => emcore_procurement_nullable_bool('is_extended'),
    ];
}

function emcore_procurement_select_columns()
{
    return "p.id, p.legacy_source_id, p.record_origin, p.notice_type,
            p.source_name, p.supplier_name, p.title, p.quantity_text,
            p.reference_number, p.amount_text, p.currency,
            p.contracting_authority, p.submission_method, p.delivery_term,
            p.primary_guarantee, p.secondary_guarantee,
            p.registered_on_fa, p.registered_on_en,
            p.documents_deadline_fa, p.documents_deadline_en,
            p.response_deadline_fa, p.response_deadline_en,
            p.alert_lead_days, p.responsible_unit,
            p.category_name, p.subcategory_name, p.product_name, p.drilling_area,
            p.participation_status, p.interest_reason, p.is_extended,
            p.created_by_usr_uid, p.updated_by_usr_uid,
            p.created_at, p.updated_at, p.deleted_at, p.lock_version,
            CASE
                WHEN p.response_deadline_en IS NULL THEN NULL
                ELSE DATEDIFF(p.response_deadline_en, CURDATE())
            END AS days_left,
            CASE
                WHEN p.response_deadline_en IS NULL THEN 'no_deadline'
                WHEN p.response_deadline_en < CURDATE() THEN 'expired'
                WHEN DATEDIFF(p.response_deadline_en, CURDATE()) <= p.alert_lead_days THEN 'urgent'
                ELSE 'open'
            END AS deadline_state";
}

function emcore_procurement_row($db, $id, $forUpdate = false, $includeDeleted = false)
{
    $sql = 'SELECT ' . emcore_procurement_select_columns()
        . ' FROM emcore_procurement_notices p WHERE p.id = :id'
        . ($includeDeleted ? '' : ' AND p.deleted_at IS NULL')
        . ' LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function emcore_procurement_files($db, $procurementId)
{
    $stmt = $db->prepare(
        "SELECT id, procurement_id, file_role, record_origin, original_filename,
                extension, mime_type, file_size, sha256, legacy_reference,
                uploaded_by_usr_uid, created_at,
                CASE WHEN record_origin = 'managed' THEN 1 ELSE 0 END AS is_downloadable
         FROM emcore_procurement_files
         WHERE procurement_id = :procurement_id AND deleted_at IS NULL
         ORDER BY file_role, created_at DESC, id DESC"
    );
    $stmt->execute([':procurement_id' => $procurementId]);
    return $stmt->fetchAll();
}

function emcore_procurement_file_row($db, $id, $forUpdate = false, $internal = false)
{
    $columns = "f.id, f.procurement_id, f.file_role, f.record_origin,
                f.original_filename, f.extension, f.mime_type, f.file_size,
                f.sha256, f.legacy_reference, f.uploaded_by_usr_uid, f.created_at";
    if ($internal) {
        $columns .= ', f.stored_filename, f.storage_path';
    }
    $sql = "SELECT {$columns}
            FROM emcore_procurement_files f
            JOIN emcore_procurement_notices p
              ON p.id = f.procurement_id AND p.deleted_at IS NULL
            WHERE f.id = :id AND f.deleted_at IS NULL
            LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function emcore_procurement_distinct_lookup($db, $column, $limit = 200)
{
    $allowed = [
        'source_name', 'contracting_authority', 'responsible_unit',
        'category_name', 'subcategory_name', 'product_name', 'currency',
        'delivery_term', 'primary_guarantee', 'secondary_guarantee',
    ];
    if (!in_array($column, $allowed, true)) {
        throw new RuntimeException('Unknown procurement lookup column.');
    }
    $stmt = $db->query(
        "SELECT DISTINCT {$column} AS value
         FROM emcore_procurement_notices
         WHERE deleted_at IS NULL AND {$column} IS NOT NULL AND {$column} <> ''
         ORDER BY {$column}
         LIMIT " . (int)$limit
    );
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$action = emcore_action([
    'lookups', 'list', 'get', 'download_file',
    'create', 'update', 'upload_file', 'delete_file', 'delete',
]);
$capabilityMap = [
    'lookups' => 'read',
    'list' => 'read',
    'get' => 'read',
    'download_file' => 'read',
    'create' => 'create',
    'update' => 'update',
    'upload_file' => 'update',
    'delete_file' => 'delete',
    'delete' => 'delete',
];
emcore_require_permission(EMCORE_PROCUREMENT_MODULE, $capabilityMap[$action]);
$db = emcore_db();

if ($action === 'download_file') {
    $fileId = emcore_positive_id('file_id');
    $file = emcore_procurement_file_row($db, $fileId, false, true);
    if (!$file) {
        throw new EmcoreHttpException(404, 'فایل یافت نشد');
    }
    emcore_procurement_send_download($file);
}

if ($action === 'lookups') {
    $lookups = [];
    foreach ([
        'source_name', 'contracting_authority', 'responsible_unit',
        'category_name', 'subcategory_name', 'product_name', 'currency',
        'delivery_term', 'primary_guarantee', 'secondary_guarantee',
    ] as $column) {
        $lookups[$column] = emcore_procurement_distinct_lookup($db, $column);
    }
    $lookups['notice_types'] = ['tender', 'auction'];
    $lookups['submission_methods'] = ['physical', 'online', 'other'];
    $lookups['participation_statuses'] = [
        'registered', 'interested', 'documents_submitted', 'won', 'lost',
    ];
    $lookups['storage_ready'] = emcore_procurement_storage_ready();
    $lookups['max_upload_bytes'] = emcore_procurement_max_upload_bytes();
    $lookups['allowed_extensions'] = emcore_procurement_allowed_extensions();
    emcore_json([
        'success' => true,
        'data' => $lookups,
        'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions(EMCORE_PROCUREMENT_MODULE),
    ]);
}

if ($action === 'list') {
    $where = ['p.deleted_at IS NULL'];
    $params = [];

    $noticeType = emcore_procurement_enum('notice_type', ['tender', 'auction'], false);
    if ($noticeType !== null) {
        $where[] = 'p.notice_type = :notice_type';
        $params[':notice_type'] = $noticeType;
    }
    $participationStatus = emcore_procurement_enum('participation_status', [
        'registered', 'interested', 'documents_submitted', 'won', 'lost',
    ], false);
    if ($participationStatus !== null) {
        $where[] = 'p.participation_status = :participation_status';
        $params[':participation_status'] = $participationStatus;
    }
    $responsibleUnit = emcore_string('responsible_unit', false, 64);
    if ($responsibleUnit !== null) {
        $where[] = 'p.responsible_unit = :responsible_unit';
        $params[':responsible_unit'] = $responsibleUnit;
    }
    $sourceName = emcore_string('source_name', false, 255);
    if ($sourceName !== null) {
        $where[] = 'p.source_name = :source_name';
        $params[':source_name'] = $sourceName;
    }

    $deadlineState = emcore_procurement_enum(
        'deadline_state',
        ['open', 'urgent', 'expired', 'no_deadline'],
        false
    );
    if ($deadlineState === 'open') {
        $where[] = 'p.response_deadline_en IS NOT NULL'
            . ' AND DATEDIFF(p.response_deadline_en, CURDATE()) > p.alert_lead_days';
    } elseif ($deadlineState === 'urgent') {
        $where[] = 'p.response_deadline_en IS NOT NULL'
            . ' AND DATEDIFF(p.response_deadline_en, CURDATE()) BETWEEN 0 AND p.alert_lead_days';
    } elseif ($deadlineState === 'expired') {
        $where[] = 'p.response_deadline_en < CURDATE()';
    } elseif ($deadlineState === 'no_deadline') {
        $where[] = 'p.response_deadline_en IS NULL';
    }

    $search = emcore_string('search', false, 150);
    if ($search !== null) {
        $where[] = '(p.title LIKE :search_title'
            . ' OR p.reference_number LIKE :search_reference'
            . ' OR p.contracting_authority LIKE :search_authority'
            . ' OR p.source_name LIKE :search_source'
            . ' OR p.supplier_name LIKE :search_supplier'
            . ' OR p.category_name LIKE :search_category'
            . ' OR p.product_name LIKE :search_product)';
        foreach ([
            ':search_title', ':search_reference', ':search_authority', ':search_source',
            ':search_supplier', ':search_category', ':search_product',
        ] as $placeholder) {
            $params[$placeholder] = '%' . $search . '%';
        }
    }

    $page = emcore_procurement_page_value('page', 1);
    $pageSize = emcore_procurement_page_value('page_size', 25, 100);
    $offset = ($page - 1) * $pageSize;
    $whereSql = implode(' AND ', $where);

    $sortBy = isset($_POST['sort_by']) ? trim((string)$_POST['sort_by']) : 'created_at';
    $sortColumns = [
        'response_deadline' => 'p.response_deadline_en IS NULL, p.response_deadline_en',
        'days_left' => 'p.response_deadline_en IS NULL, p.response_deadline_en',
        'registered_on' => 'p.registered_on_en',
        'created_at' => 'p.created_at',
        'title' => 'p.title',
        'notice_type' => 'p.notice_type',
        'contracting_authority' => 'p.contracting_authority IS NULL, p.contracting_authority',
        'responsible_unit' => 'p.responsible_unit IS NULL, p.responsible_unit',
        'participation_status' => 'p.participation_status',
        'file_count' => 'file_count',
        'id' => 'p.id',
    ];
    if (!isset($sortColumns[$sortBy])) {
        throw new EmcoreHttpException(422, 'مرتب‌سازی نامعتبر است', ['sort_by' => 'invalid_value']);
    }
    $sortOrder = isset($_POST['sort_order']) ? strtolower(trim((string)$_POST['sort_order'])) : 'desc';
    if (!in_array($sortOrder, ['asc', 'desc'], true)) {
        throw new EmcoreHttpException(422, 'جهت مرتب‌سازی نامعتبر است', ['sort_order' => 'invalid_value']);
    }

    $count = $db->prepare("SELECT COUNT(*) FROM emcore_procurement_notices p WHERE {$whereSql}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = $db->prepare(
        'SELECT ' . emcore_procurement_select_columns() . ",
                (SELECT COUNT(*)
                 FROM emcore_procurement_files f
                 WHERE f.procurement_id = p.id AND f.deleted_at IS NULL) AS file_count
         FROM emcore_procurement_notices p
         WHERE {$whereSql}
         ORDER BY " . $sortColumns[$sortBy] . ' ' . strtoupper($sortOrder)
            . ', p.id DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset
    );
    $stmt->execute($params);

    $summary = $db->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(notice_type = 'tender'), 0) AS tender_count,
                COALESCE(SUM(notice_type = 'auction'), 0) AS auction_count,
                COALESCE(SUM(response_deadline_en < CURDATE()), 0) AS expired_count,
                COALESCE(SUM(response_deadline_en IS NOT NULL
                    AND DATEDIFF(response_deadline_en, CURDATE()) BETWEEN 0 AND alert_lead_days), 0)
                    AS urgent_count,
                COALESCE(SUM(participation_status = 'interested'), 0) AS interested_count,
                COALESCE(SUM(participation_status = 'won'), 0) AS won_count
         FROM emcore_procurement_notices
         WHERE deleted_at IS NULL"
    )->fetch();

    emcore_json([
        'success' => true,
        'data' => $stmt->fetchAll(),
        'summary' => $summary,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $pageSize)),
        ],
        'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions(EMCORE_PROCUREMENT_MODULE),
    ]);
}

if ($action === 'get') {
    $id = emcore_positive_id('id');
    $notice = emcore_procurement_row($db, $id);
    if (!$notice) {
        throw new EmcoreHttpException(404, 'مناقصه یا مزایده یافت نشد');
    }
    $notice['files'] = emcore_procurement_files($db, $id);
    emcore_json(['success' => true, 'data' => $notice]);
}

emcore_require_csrf();
$actor = emcore_current_user();

if ($action === 'delete') {
    $id = emcore_positive_id('id');
    $db->beginTransaction();
    try {
        $before = emcore_procurement_row($db, $id, true);
        if (!$before) {
            throw new EmcoreHttpException(404, 'مناقصه یا مزایده یافت نشد');
        }
        $stmt = $db->prepare(
            "UPDATE emcore_procurement_notices
             SET deleted_at = NOW(), updated_at = NOW(),
                 updated_by_usr_uid = :updated_by_usr_uid,
                 lock_version = lock_version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute([':updated_by_usr_uid' => $actor['USR_UID'], ':id' => $id]);
        $after = emcore_procurement_row($db, $id, false, true);
        emcore_audit(EMCORE_PROCUREMENT_MODULE, 'delete', 'procurement_notice', $id, $before, $after);
        $db->commit();
        emcore_json(['success' => true]);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

if ($action === 'delete_file') {
    $fileId = emcore_positive_id('file_id');
    $db->beginTransaction();
    try {
        $before = emcore_procurement_file_row($db, $fileId, true);
        if (!$before) {
            throw new EmcoreHttpException(404, 'فایل یافت نشد');
        }
        $db->prepare('UPDATE emcore_procurement_files SET deleted_at = NOW() WHERE id = :id')
            ->execute([':id' => $fileId]);
        $after = $before;
        $after['deleted_at'] = date('Y-m-d H:i:s');
        emcore_audit(
            EMCORE_PROCUREMENT_MODULE,
            'delete',
            'procurement_file',
            $fileId,
            $before,
            $after
        );
        $db->commit();
        emcore_json(['success' => true]);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

if ($action === 'upload_file') {
    $procurementId = emcore_positive_id('procurement_id');
    $fileRole = emcore_procurement_enum('file_role', ['notice_document', 'final_submission']);
    $file = null;
    $db->beginTransaction();
    try {
        $notice = emcore_procurement_row($db, $procurementId, true);
        if (!$notice) {
            throw new EmcoreHttpException(404, 'مناقصه یا مزایده یافت نشد');
        }
        $file = emcore_procurement_store_upload('file', $procurementId);
        $stmt = $db->prepare(
            "INSERT INTO emcore_procurement_files
                (procurement_id, file_role, record_origin, original_filename,
                 stored_filename, storage_path, extension, mime_type, file_size,
                 sha256, uploaded_by_usr_uid)
             VALUES
                (:procurement_id, :file_role, 'managed', :original_filename,
                 :stored_filename, :storage_path, :extension, :mime_type, :file_size,
                 :sha256, :uploaded_by_usr_uid)"
        );
        $stmt->execute([
            ':procurement_id' => $procurementId,
            ':file_role' => $fileRole,
            ':original_filename' => $file['original_filename'],
            ':stored_filename' => $file['stored_filename'],
            ':storage_path' => $file['storage_path'],
            ':extension' => $file['extension'],
            ':mime_type' => $file['mime_type'],
            ':file_size' => $file['file_size'],
            ':sha256' => $file['sha256'],
            ':uploaded_by_usr_uid' => $actor['USR_UID'],
        ]);
        $fileId = (int)$db->lastInsertId();
        $after = emcore_procurement_file_row($db, $fileId);
        emcore_audit(
            EMCORE_PROCUREMENT_MODULE,
            'create',
            'procurement_file',
            $fileId,
            null,
            $after,
            ['procurement_id' => $procurementId, 'file_role' => $fileRole]
        );
        $db->commit();
        emcore_json(['success' => true, 'id' => $fileId, 'data' => $after], 201);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        emcore_procurement_remove_failed_upload($file);
        throw $exception;
    }
}

$input = emcore_procurement_input($db);
$db->beginTransaction();
try {
    if ($action === 'create') {
        $stmt = $db->prepare(
            "INSERT INTO emcore_procurement_notices
                (record_origin, notice_type, source_name, supplier_name, title,
                 quantity_text, reference_number, amount_text, currency,
                 contracting_authority, submission_method, delivery_term,
                 primary_guarantee, secondary_guarantee,
                 registered_on_fa, registered_on_en,
                 documents_deadline_fa, documents_deadline_en,
                 response_deadline_fa, response_deadline_en, alert_lead_days,
                 responsible_unit, category_name, subcategory_name, product_name,
                 drilling_area, participation_status, interest_reason, is_extended,
                 created_by_usr_uid, updated_by_usr_uid)
             VALUES
                ('managed', :notice_type, :source_name, :supplier_name, :title,
                 :quantity_text, :reference_number, :amount_text, :currency,
                 :contracting_authority, :submission_method, :delivery_term,
                 :primary_guarantee, :secondary_guarantee,
                 :registered_on_fa, :registered_on_en,
                 :documents_deadline_fa, :documents_deadline_en,
                 :response_deadline_fa, :response_deadline_en, :alert_lead_days,
                 :responsible_unit, :category_name, :subcategory_name, :product_name,
                 :drilling_area, :participation_status, :interest_reason, :is_extended,
                 :created_by_usr_uid, :updated_by_usr_uid)"
        );
        $input[':created_by_usr_uid'] = $actor['USR_UID'];
        $input[':updated_by_usr_uid'] = $actor['USR_UID'];
        $stmt->execute($input);
        $id = (int)$db->lastInsertId();
        $after = emcore_procurement_row($db, $id);
        emcore_audit(EMCORE_PROCUREMENT_MODULE, 'create', 'procurement_notice', $id, null, $after);
        $db->commit();
        emcore_json(['success' => true, 'id' => $id, 'data' => $after], 201);
    }

    $id = emcore_positive_id('id');
    $expectedVersion = emcore_procurement_bounded_int('lock_version', null, 1, 2147483647);
    if ($expectedVersion === null) {
        throw new EmcoreHttpException(422, 'نسخه رکورد الزامی است', ['lock_version' => 'required']);
    }
    $before = emcore_procurement_row($db, $id, true);
    if (!$before) {
        throw new EmcoreHttpException(404, 'مناقصه یا مزایده یافت نشد');
    }
    if ((int)$before['lock_version'] !== $expectedVersion) {
        throw new EmcoreHttpException(409, 'این رکورد توسط کاربر دیگری تغییر کرده است؛ دوباره بارگذاری کنید', [
            'lock_version' => 'stale',
            'current_version' => (int)$before['lock_version'],
        ]);
    }

    $input[':updated_by_usr_uid'] = $actor['USR_UID'];
    $input[':id'] = $id;
    $input[':lock_version'] = $expectedVersion;
    $stmt = $db->prepare(
        "UPDATE emcore_procurement_notices SET
            notice_type = :notice_type,
            source_name = :source_name,
            supplier_name = :supplier_name,
            title = :title,
            quantity_text = :quantity_text,
            reference_number = :reference_number,
            amount_text = :amount_text,
            currency = :currency,
            contracting_authority = :contracting_authority,
            submission_method = :submission_method,
            delivery_term = :delivery_term,
            primary_guarantee = :primary_guarantee,
            secondary_guarantee = :secondary_guarantee,
            registered_on_fa = :registered_on_fa,
            registered_on_en = :registered_on_en,
            documents_deadline_fa = :documents_deadline_fa,
            documents_deadline_en = :documents_deadline_en,
            response_deadline_fa = :response_deadline_fa,
            response_deadline_en = :response_deadline_en,
            alert_lead_days = :alert_lead_days,
            responsible_unit = :responsible_unit,
            category_name = :category_name,
            subcategory_name = :subcategory_name,
            product_name = :product_name,
            drilling_area = :drilling_area,
            participation_status = :participation_status,
            interest_reason = :interest_reason,
            is_extended = :is_extended,
            updated_by_usr_uid = :updated_by_usr_uid,
            updated_at = NOW(),
            lock_version = lock_version + 1
         WHERE id = :id AND deleted_at IS NULL AND lock_version = :lock_version"
    );
    $stmt->execute($input);
    if ($stmt->rowCount() !== 1) {
        throw new EmcoreHttpException(409, 'رکورد هم‌زمان تغییر کرده است؛ دوباره بارگذاری کنید');
    }
    $after = emcore_procurement_row($db, $id);
    emcore_audit(EMCORE_PROCUREMENT_MODULE, 'update', 'procurement_notice', $id, $before, $after);
    $db->commit();
    emcore_json(['success' => true, 'id' => $id, 'data' => $after]);
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
