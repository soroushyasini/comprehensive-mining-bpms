<?php

require_once __DIR__ . '/_trade_storage.php';

const EMCORE_TRADE_MODULE = 'trade_documents';

function emcore_trade_enum($name, $allowed, $required = true)
{
    $value = emcore_string($name, $required, 40);
    if ($value !== null && !in_array($value, $allowed, true)) {
        throw new EmcoreHttpException(422, 'مقدار ورودی نامعتبر است', [$name => 'invalid_value']);
    }
    return $value;
}

function emcore_trade_page_value($name, $default, $maximum = null)
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

function emcore_trade_date($name)
{
    $value = emcore_string($name, false, 10);
    if ($value === null) {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors !== false
        && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value) {
        throw new EmcoreHttpException(422, 'تاریخ سند نامعتبر است', [$name => 'yyyy_mm_dd_required']);
    }
    return $value;
}

function emcore_trade_case_row($db, $id, $forUpdate = false, $includeDeleted = false)
{
    $sql = "SELECT c.id, c.issuer_id, c.record_origin, c.sequence_number, c.number_year, c.pi_number,
                   c.numbering_issue, c.numbering_note,
                   c.direction, c.summary, c.counterparty, c.coordinator_usr_uid,
                   c.case_status, c.notes, c.created_by_usr_uid, c.updated_by_usr_uid,
                   c.created_at, c.updated_at, c.deleted_at,
                   i.issuer_key, i.name_fa AS issuer_name_fa, i.name_en AS issuer_name_en,
                   i.code_prefix,
                   TRIM(CONCAT_WS(' ', u.USR_FIRSTNAME, u.USR_LASTNAME)) AS coordinator_name,
                   u.USR_USERNAME AS coordinator_username
            FROM emcore_trade_cases c
            JOIN emcore_trade_issuers i ON i.id = c.issuer_id
            LEFT JOIN USERS u ON u.USR_UID = c.coordinator_usr_uid
            WHERE c.id = :id" . ($includeDeleted ? '' : ' AND c.deleted_at IS NULL')
            . ' LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function emcore_trade_document_row($db, $id, $forUpdate = false)
{
    $sql = "SELECT d.id, d.case_id, d.document_type, d.record_origin,
                   d.document_number, d.document_date,
                   d.document_status, d.approved_by_name, d.approved_at,
                   d.status_note, d.updated_by_usr_uid, d.created_at, d.updated_at,
                   c.pi_number, c.issuer_id, c.record_origin AS case_origin, i.issuer_key
            FROM emcore_trade_documents d
            JOIN emcore_trade_cases c ON c.id = d.case_id AND c.deleted_at IS NULL
            JOIN emcore_trade_issuers i ON i.id = c.issuer_id
            WHERE d.id = :id
            LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function emcore_trade_assert_document_prerequisite($db, $document)
{
    if ($document['case_origin'] === 'legacy') {
        return;
    }
    if ($document['document_type'] === 'pi') {
        return;
    }
    $requiredType = $document['document_type'] === 'ci' ? 'pi' : 'ci';
    $stmt = $db->prepare(
        "SELECT document_status
         FROM emcore_trade_documents
         WHERE case_id = :case_id AND document_type = :document_type
         LIMIT 1"
    );
    $stmt->execute([
        ':case_id' => $document['case_id'],
        ':document_type' => $requiredType,
    ]);
    $requiredStatus = $stmt->fetchColumn();
    if (!in_array($requiredStatus, ['approved', 'issued'], true)) {
        $requiredLabel = strtoupper($requiredType);
        throw new EmcoreHttpException(409, "ابتدا سند {$requiredLabel} را تأیید یا صادر کنید");
    }
}

function emcore_trade_case_input()
{
    return [
        'direction' => emcore_trade_enum('direction', ['export', 'import']),
        'summary' => emcore_string('summary', true, 500),
        'counterparty' => emcore_string('counterparty', false, 255),
        'notes' => emcore_string('notes', false, 10000),
    ];
}

function emcore_trade_legacy_documents_input()
{
    $documents = [];
    foreach (['pi', 'ci', 'pl'] as $type) {
        $documents[$type] = [
            'number' => emcore_string($type . '_number', false, 64),
            'date' => emcore_trade_date($type . '_date'),
        ];
    }
    return $documents;
}

function emcore_trade_legacy_number_conflicts($db, $documents, $excludeCaseId = null)
{
    $numbers = [];
    foreach ($documents as $document) {
        if ($document['number'] !== null) {
            $numbers[] = $document['number'];
        }
    }
    $duplicates = [];
    foreach (array_count_values($numbers) as $number => $count) {
        if ($count > 1) {
            $duplicates[] = $number;
        }
    }
    if (!$numbers) {
        return ['numbers' => [], 'duplicates' => []];
    }
    $placeholders = implode(',', array_fill(0, count($numbers), '?'));
    $params = $numbers;
    $excludeSql = '';
    if ($excludeCaseId !== null) {
        $excludeSql = ' AND case_id <> ?';
        $params[] = $excludeCaseId;
    }
    $stmt = $db->prepare(
        "SELECT DISTINCT document_number
         FROM emcore_trade_documents
         WHERE document_number IN ({$placeholders}){$excludeSql}"
    );
    $stmt->execute($params);
    return [
        'numbers' => $numbers,
        'duplicates' => array_values(array_unique(array_merge(
            $duplicates,
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        ))),
    ];
}

function emcore_trade_file_insert_values($file)
{
    return [
        ':original_filename' => $file['original_filename'],
        ':stored_filename' => $file['stored_filename'],
        ':storage_path' => $file['storage_path'],
        ':extension' => $file['extension'],
        ':mime_type' => $file['mime_type'],
        ':file_size' => $file['file_size'],
        ':sha256' => $file['sha256'],
    ];
}

function emcore_trade_download_row($db, $kind, $id)
{
    if ($kind === 'version') {
        $stmt = $db->prepare(
            "SELECT v.id, v.original_filename, v.storage_path, v.extension, v.mime_type
             FROM emcore_trade_document_versions v
             JOIN emcore_trade_documents d ON d.id = v.document_id
             JOIN emcore_trade_cases c ON c.id = d.case_id AND c.deleted_at IS NULL
             WHERE v.id = :id AND v.deleted_at IS NULL LIMIT 1"
        );
    } elseif ($kind === 'attachment') {
        $stmt = $db->prepare(
            "SELECT a.id, a.original_filename, a.storage_path, a.extension, a.mime_type
             FROM emcore_trade_attachments a
             JOIN emcore_trade_cases c ON c.id = a.case_id AND c.deleted_at IS NULL
             WHERE a.id = :id AND a.deleted_at IS NULL LIMIT 1"
        );
    } elseif ($kind === 'template') {
        $stmt = $db->prepare(
            "SELECT t.id, t.original_filename, t.storage_path, t.extension, t.mime_type
             FROM emcore_trade_templates t
             JOIN emcore_trade_issuers i ON i.id = t.issuer_id AND i.is_active = 1
             WHERE t.id = :id AND t.deleted_at IS NULL LIMIT 1"
        );
    } else {
        throw new EmcoreHttpException(422, 'نوع فایل نامعتبر است');
    }
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

$action = emcore_action([
    'lookups', 'list', 'get', 'download',
    'create', 'create_legacy', 'update', 'set_document_status',
    'upload_document', 'upload_attachment', 'upload_template',
    'delete_file', 'delete',
]);
$capabilityMap = [
    'lookups' => 'read',
    'list' => 'read',
    'get' => 'read',
    'download' => 'read',
    'create' => 'create',
    'create_legacy' => 'create',
    'update' => 'update',
    'set_document_status' => 'update',
    'upload_document' => 'update',
    'upload_attachment' => 'update',
    'upload_template' => 'update',
    'delete_file' => 'delete',
    'delete' => 'delete',
];
emcore_require_permission(EMCORE_TRADE_MODULE, $capabilityMap[$action]);
$db = emcore_db();

if ($action === 'download') {
    $kind = emcore_trade_enum('file_kind', ['version', 'attachment', 'template']);
    $id = emcore_positive_id('file_id');
    $row = emcore_trade_download_row($db, $kind, $id);
    if (!$row) {
        throw new EmcoreHttpException(404, 'فایل یافت نشد');
    }
    emcore_trade_send_download($row, $kind);
}

if ($action === 'lookups') {
    $issuers = $db->query(
        "SELECT id, issuer_key, name_fa, name_en, code_prefix, next_sequence
         FROM emcore_trade_issuers
         WHERE is_active = 1
         ORDER BY id"
    )->fetchAll();
    $templates = $db->query(
        "SELECT t.id, t.issuer_id, t.document_type, t.revision_number,
                t.original_filename, t.file_size, t.template_note, t.created_at,
                t.uploaded_by_usr_uid,
                TRIM(CONCAT_WS(' ', u.USR_FIRSTNAME, u.USR_LASTNAME)) AS uploaded_by_name
         FROM emcore_trade_templates t
         LEFT JOIN USERS u ON u.USR_UID = t.uploaded_by_usr_uid
         WHERE t.is_active = 1 AND t.deleted_at IS NULL
         ORDER BY t.issuer_id, FIELD(t.document_type, 'pi', 'ci', 'pl')"
    )->fetchAll();
    $storageReady = emcore_trade_storage_ready();
    emcore_json([
        'success' => true,
        'data' => [
            'issuers' => $issuers,
            'templates' => $templates,
            'storage_ready' => $storageReady,
            'max_upload_bytes' => emcore_trade_max_upload_bytes(),
        ],
        'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions(EMCORE_TRADE_MODULE),
    ]);
}

if ($action === 'list') {
    $where = ['c.deleted_at IS NULL'];
    $params = [];
    $issuerRaw = isset($_POST['issuer_id']) ? trim((string)$_POST['issuer_id']) : '';
    if ($issuerRaw !== '') {
        if (!preg_match('/^[1-9][0-9]*$/', $issuerRaw)) {
            throw new EmcoreHttpException(422, 'شرکت نامعتبر است');
        }
        $where[] = 'c.issuer_id = :issuer_id';
        $params[':issuer_id'] = (int)$issuerRaw;
    }
    $origin = emcore_trade_enum('record_origin', ['managed', 'legacy'], false);
    if ($origin !== null) {
        $where[] = 'c.record_origin = :record_origin';
        $params[':record_origin'] = $origin;
    }
    $status = emcore_trade_enum('case_status', ['open', 'completed', 'cancelled', 'archived'], false);
    if ($status !== null) {
        $where[] = 'c.case_status = :case_status';
        $params[':case_status'] = $status;
    }
    $search = emcore_string('search', false, 100);
    if ($search !== null) {
        $where[] = '(c.pi_number LIKE :search_pi OR c.summary LIKE :search_summary '
            . 'OR c.counterparty LIKE :search_counterparty OR EXISTS '
            . '(SELECT 1 FROM emcore_trade_documents sd '
            . 'WHERE sd.case_id = c.id AND sd.document_number LIKE :search_document))';
        $params[':search_pi'] = '%' . $search . '%';
        $params[':search_summary'] = '%' . $search . '%';
        $params[':search_counterparty'] = '%' . $search . '%';
        $params[':search_document'] = '%' . $search . '%';
    }
    $page = emcore_trade_page_value('page', 1);
    $pageSize = emcore_trade_page_value('page_size', 50, 200);
    $offset = ($page - 1) * $pageSize;
    $whereSql = implode(' AND ', $where);
    $count = $db->prepare("SELECT COUNT(*) FROM emcore_trade_cases c WHERE {$whereSql}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $stmt = $db->prepare(
        "SELECT c.id, c.pi_number, c.record_origin, c.numbering_issue, c.numbering_note,
                c.direction, c.summary, c.counterparty,
                c.case_status, c.coordinator_usr_uid, c.created_at, c.updated_at,
                i.name_fa AS issuer_name_fa, i.name_en AS issuer_name_en,
                pi.document_number AS pi_document_number, pi.document_status AS pi_status,
                ci.document_number AS ci_document_number, ci.document_status AS ci_status,
                pl.document_number AS pl_document_number, pl.document_status AS pl_status,
                (SELECT COUNT(*) FROM emcore_trade_attachments a
                 WHERE a.case_id = c.id AND a.deleted_at IS NULL) AS attachment_count
         FROM emcore_trade_cases c
         JOIN emcore_trade_issuers i ON i.id = c.issuer_id
         JOIN emcore_trade_documents pi ON pi.case_id = c.id AND pi.document_type = 'pi'
         JOIN emcore_trade_documents ci ON ci.case_id = c.id AND ci.document_type = 'ci'
         JOIN emcore_trade_documents pl ON pl.case_id = c.id AND pl.document_type = 'pl'
         WHERE {$whereSql}
         ORDER BY c.updated_at DESC, c.id DESC
         LIMIT {$pageSize} OFFSET {$offset}"
    );
    $stmt->execute($params);
    emcore_json([
        'success' => true,
        'data' => $stmt->fetchAll(),
        'pagination' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total],
        'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions(EMCORE_TRADE_MODULE),
    ]);
}

if ($action === 'get') {
    $id = emcore_positive_id('id');
    $case = emcore_trade_case_row($db, $id);
    if (!$case) {
        throw new EmcoreHttpException(404, 'پرونده یافت نشد');
    }
    $documentStmt = $db->prepare(
        "SELECT id, case_id, document_type, record_origin, document_number, document_date, document_status,
                approved_by_name, approved_at, status_note, updated_at
         FROM emcore_trade_documents
         WHERE case_id = :case_id
         ORDER BY FIELD(document_type, 'pi', 'ci', 'pl')"
    );
    $documentStmt->execute([':case_id' => $id]);
    $documents = $documentStmt->fetchAll();
    $versionStmt = $db->prepare(
        "SELECT v.id, v.document_id, v.revision_number, v.version_state, v.file_role,
                v.original_filename, v.extension, v.mime_type, v.file_size,
                v.sha256, v.change_note, v.uploaded_by_usr_uid, v.created_at,
                TRIM(CONCAT_WS(' ', u.USR_FIRSTNAME, u.USR_LASTNAME)) AS uploaded_by_name
         FROM emcore_trade_document_versions v
         JOIN emcore_trade_documents d ON d.id = v.document_id
         LEFT JOIN USERS u ON u.USR_UID = v.uploaded_by_usr_uid
         WHERE d.case_id = :case_id AND v.deleted_at IS NULL
         ORDER BY v.document_id, v.revision_number DESC"
    );
    $versionStmt->execute([':case_id' => $id]);
    $versionsByDocument = [];
    foreach ($versionStmt->fetchAll() as $version) {
        $documentId = (string)$version['document_id'];
        if (!isset($versionsByDocument[$documentId])) {
            $versionsByDocument[$documentId] = [];
        }
        $versionsByDocument[$documentId][] = $version;
    }
    foreach ($documents as &$document) {
        $document['versions'] = isset($versionsByDocument[(string)$document['id']])
            ? $versionsByDocument[(string)$document['id']]
            : [];
    }
    unset($document);
    $attachmentStmt = $db->prepare(
        "SELECT a.id, a.case_id, a.category, a.title, a.reference_number, a.notes,
                a.original_filename, a.extension, a.mime_type, a.file_size,
                a.sha256, a.uploaded_by_usr_uid, a.created_at,
                TRIM(CONCAT_WS(' ', u.USR_FIRSTNAME, u.USR_LASTNAME)) AS uploaded_by_name
         FROM emcore_trade_attachments a
         LEFT JOIN USERS u ON u.USR_UID = a.uploaded_by_usr_uid
         WHERE a.case_id = :case_id AND a.deleted_at IS NULL
         ORDER BY a.created_at DESC, a.id DESC"
    );
    $attachmentStmt->execute([':case_id' => $id]);
    emcore_json([
        'success' => true,
        'data' => [
            'case' => $case,
            'documents' => $documents,
            'attachments' => $attachmentStmt->fetchAll(),
        ],
        'csrf_token' => emcore_csrf_token(),
        'permissions' => emcore_module_permissions(EMCORE_TRADE_MODULE),
    ]);
}

emcore_require_csrf();
$actor = emcore_current_user();

if ($action === 'create') {
    $issuerId = emcore_positive_id('issuer_id');
    $input = emcore_trade_case_input();
    $db->beginTransaction();
    try {
        $issuerStmt = $db->prepare(
            "SELECT id, issuer_key, code_prefix, next_sequence
             FROM emcore_trade_issuers
             WHERE id = :id AND is_active = 1
             LIMIT 1 FOR UPDATE"
        );
        $issuerStmt->execute([':id' => $issuerId]);
        $issuer = $issuerStmt->fetch();
        if (!$issuer) {
            throw new EmcoreHttpException(422, 'شرکت فعال یافت نشد');
        }
        $year = (int)$db->query('SELECT YEAR(CURDATE())')->fetchColumn();
        $sequence = (int)$issuer['next_sequence'];
        $skippedSequences = [];
        $numberConflictStmt = $db->prepare(
            "SELECT COUNT(*) FROM emcore_trade_documents
             WHERE document_number IN (:pi_number, :ci_number, :pl_number)"
        );
        do {
            $piNumber = $issuer['code_prefix'] . $sequence . '-' . $year;
            $numberConflictStmt->execute([
                ':pi_number' => $piNumber,
                ':ci_number' => $piNumber . 'CI',
                ':pl_number' => $piNumber . 'PL',
            ]);
            if ((int)$numberConflictStmt->fetchColumn() === 0) {
                break;
            }
            $skippedSequences[] = $sequence;
            $sequence++;
            if (count($skippedSequences) > 10000) {
                throw new EmcoreHttpException(409, 'شمارهٔ آزاد برای این شرکت پیدا نشد');
            }
        } while (true);
        $db->prepare(
            'UPDATE emcore_trade_issuers SET next_sequence = :next_sequence WHERE id = :id'
        )->execute([':next_sequence' => $sequence + 1, ':id' => $issuerId]);
        $caseStmt = $db->prepare(
            "INSERT INTO emcore_trade_cases
                (issuer_id, record_origin, sequence_number, number_year, pi_number, direction,
                 summary, counterparty, coordinator_usr_uid, case_status, notes,
                 created_by_usr_uid, updated_by_usr_uid)
             VALUES
                (:issuer_id, 'managed', :sequence_number, :number_year, :pi_number, :direction,
                 :summary, :counterparty, :coordinator_usr_uid, 'open', :notes,
                 :created_by_usr_uid, :updated_by_usr_uid)"
        );
        $caseStmt->execute([
            ':issuer_id' => $issuerId,
            ':sequence_number' => $sequence,
            ':number_year' => $year,
            ':pi_number' => $piNumber,
            ':direction' => $input['direction'],
            ':summary' => $input['summary'],
            ':counterparty' => $input['counterparty'],
            ':coordinator_usr_uid' => $actor['USR_UID'],
            ':notes' => $input['notes'],
            ':created_by_usr_uid' => $actor['USR_UID'],
            ':updated_by_usr_uid' => $actor['USR_UID'],
        ]);
        $caseId = (int)$db->lastInsertId();
        $documentStmt = $db->prepare(
            "INSERT INTO emcore_trade_documents
                (case_id, document_type, record_origin, document_number, document_status, updated_by_usr_uid)
             VALUES (:case_id, :document_type, 'managed', :document_number, :document_status, :updated_by_usr_uid)"
        );
        foreach ([
            ['pi', $piNumber, 'draft'],
            ['ci', $piNumber . 'CI', 'not_started'],
            ['pl', $piNumber . 'PL', 'not_started'],
        ] as $document) {
            $documentStmt->execute([
                ':case_id' => $caseId,
                ':document_type' => $document[0],
                ':document_number' => $document[1],
                ':document_status' => $document[2],
                ':updated_by_usr_uid' => $actor['USR_UID'],
            ]);
        }
        $after = emcore_trade_case_row($db, $caseId, false);
        emcore_audit(EMCORE_TRADE_MODULE, 'create', 'trade_case', $caseId, null, $after, [
            'reserved_sequence' => $sequence,
            'skipped_sequences' => $skippedSequences,
            'document_numbers' => [$piNumber, $piNumber . 'CI', $piNumber . 'PL'],
        ]);
        $db->commit();
        emcore_json(['success' => true, 'id' => $caseId, 'pi_number' => $piNumber], 201);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

if ($action === 'create_legacy') {
    $issuerId = emcore_positive_id('issuer_id');
    $input = emcore_trade_case_input();
    $documents = emcore_trade_legacy_documents_input();
    $numberingIssue = emcore_trade_enum('numbering_issue', [
        'none', 'suspected', 'duplicate', 'missing', 'unknown',
    ]);
    $numberingNote = emcore_string('numbering_note', false, 1000);
    $db->beginTransaction();
    try {
        $issuerStmt = $db->prepare(
            'SELECT id FROM emcore_trade_issuers WHERE id = :id AND is_active = 1 LIMIT 1 FOR UPDATE'
        );
        $issuerStmt->execute([':id' => $issuerId]);
        if (!$issuerStmt->fetchColumn()) {
            throw new EmcoreHttpException(422, 'شرکت فعال یافت نشد');
        }

        $numberConflicts = emcore_trade_legacy_number_conflicts($db, $documents);
        $documentNumbers = $numberConflicts['numbers'];
        $duplicateNumbers = $numberConflicts['duplicates'];
        if ($duplicateNumbers) {
            $numberingIssue = 'duplicate';
        } elseif (count($documentNumbers) < 3 && $numberingIssue === 'none') {
            $numberingIssue = 'missing';
        }

        $caseStmt = $db->prepare(
            "INSERT INTO emcore_trade_cases
                (issuer_id, record_origin, sequence_number, number_year, pi_number,
                 numbering_issue, numbering_note, direction, summary, counterparty,
                 coordinator_usr_uid, case_status, notes, created_by_usr_uid, updated_by_usr_uid)
             VALUES
                (:issuer_id, 'legacy', NULL, NULL, :pi_number,
                 :numbering_issue, :numbering_note, :direction, :summary, :counterparty,
                 :coordinator_usr_uid, 'archived', :notes, :created_by_usr_uid, :updated_by_usr_uid)"
        );
        $caseStmt->execute([
            ':issuer_id' => $issuerId,
            ':pi_number' => $documents['pi']['number'],
            ':numbering_issue' => $numberingIssue,
            ':numbering_note' => $numberingNote,
            ':direction' => $input['direction'],
            ':summary' => $input['summary'],
            ':counterparty' => $input['counterparty'],
            ':coordinator_usr_uid' => $actor['USR_UID'],
            ':notes' => $input['notes'],
            ':created_by_usr_uid' => $actor['USR_UID'],
            ':updated_by_usr_uid' => $actor['USR_UID'],
        ]);
        $caseId = (int)$db->lastInsertId();
        $documentStmt = $db->prepare(
            "INSERT INTO emcore_trade_documents
                (case_id, document_type, record_origin, document_number, document_date,
                 document_status, updated_by_usr_uid)
             VALUES
                (:case_id, :document_type, 'legacy', :document_number, :document_date,
                 'archived', :updated_by_usr_uid)"
        );
        foreach ($documents as $type => $document) {
            $documentStmt->execute([
                ':case_id' => $caseId,
                ':document_type' => $type,
                ':document_number' => $document['number'],
                ':document_date' => $document['date'],
                ':updated_by_usr_uid' => $actor['USR_UID'],
            ]);
        }
        $after = emcore_trade_case_row($db, $caseId);
        emcore_audit(EMCORE_TRADE_MODULE, 'create', 'trade_case', $caseId, null, $after, [
            'record_origin' => 'legacy',
            'counter_advanced' => false,
            'document_numbers' => $documentNumbers,
            'duplicate_numbers' => $duplicateNumbers,
        ]);
        $db->commit();
        emcore_json([
            'success' => true,
            'id' => $caseId,
            'reference' => $documents['pi']['number']
                ?: ($documents['ci']['number'] ?: ($documents['pl']['number'] ?: ('سابقه #' . $caseId))),
            'duplicate_numbers' => $duplicateNumbers,
        ], 201);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

if ($action === 'update') {
    $id = emcore_positive_id('id');
    $input = emcore_trade_case_input();
    $caseStatus = emcore_trade_enum('case_status', ['open', 'completed', 'cancelled', 'archived']);
    $legacyDocuments = emcore_trade_legacy_documents_input();
    $numberingIssue = emcore_trade_enum('numbering_issue', [
        'none', 'suspected', 'duplicate', 'missing', 'unknown',
    ], false);
    $numberingNote = emcore_string('numbering_note', false, 1000);
    $db->beginTransaction();
    try {
        $before = emcore_trade_case_row($db, $id, true);
        if (!$before) {
            throw new EmcoreHttpException(404, 'پرونده یافت نشد');
        }
        if ($before['record_origin'] === 'managed' && $caseStatus === 'archived') {
            throw new EmcoreHttpException(422, 'پروندهٔ جاری را نمی‌توان به سابقهٔ قبلی تبدیل کرد');
        }
        if ($before['record_origin'] === 'legacy' && !in_array($caseStatus, ['archived', 'cancelled'], true)) {
            throw new EmcoreHttpException(422, 'وضعیت سابقهٔ قبلی نامعتبر است');
        }
        $duplicateNumbers = [];
        $legacyIssuerId = null;
        if ($before['record_origin'] === 'legacy') {
            $legacyIssuerId = emcore_positive_id('issuer_id');
            $issuerStmt = $db->prepare(
                'SELECT id FROM emcore_trade_issuers WHERE id = :id AND is_active = 1 LIMIT 1 FOR UPDATE'
            );
            $issuerStmt->execute([':id' => $legacyIssuerId]);
            if (!$issuerStmt->fetchColumn()) {
                throw new EmcoreHttpException(422, 'شرکت فعال یافت نشد');
            }
            $numberConflicts = emcore_trade_legacy_number_conflicts($db, $legacyDocuments, $id);
            $duplicateNumbers = $numberConflicts['duplicates'];
            if ($duplicateNumbers) {
                $numberingIssue = 'duplicate';
            } elseif (count($numberConflicts['numbers']) < 3 && ($numberingIssue === null || $numberingIssue === 'none')) {
                $numberingIssue = 'missing';
            }
        }
        if ($before['record_origin'] === 'managed' && $caseStatus === 'completed') {
            $completedStmt = $db->prepare(
                "SELECT COUNT(*)
                 FROM emcore_trade_documents
                 WHERE case_id = :case_id
                   AND document_status IN ('approved', 'issued')"
            );
            $completedStmt->execute([':case_id' => $id]);
            if ((int)$completedStmt->fetchColumn() !== 3) {
                throw new EmcoreHttpException(409, 'برای تکمیل پرونده، هر سه سند PI، CI و PL باید تأیید یا صادر شده باشند');
            }
        }
        $params = [
            ':direction' => $input['direction'],
            ':summary' => $input['summary'],
            ':counterparty' => $input['counterparty'],
            ':case_status' => $caseStatus,
            ':notes' => $input['notes'],
            ':updated_by_usr_uid' => $actor['USR_UID'],
            ':id' => $id,
        ];
        $legacySet = '';
        if ($before['record_origin'] === 'legacy') {
            $legacySet = ', issuer_id = :legacy_issuer_id, pi_number = :pi_number, numbering_issue = :numbering_issue, '
                . 'numbering_note = :numbering_note';
            $params[':legacy_issuer_id'] = $legacyIssuerId;
            $params[':pi_number'] = $legacyDocuments['pi']['number'];
            $params[':numbering_issue'] = $numberingIssue ?: 'none';
            $params[':numbering_note'] = $numberingNote;
        }
        $stmt = $db->prepare(
            "UPDATE emcore_trade_cases
             SET direction = :direction, summary = :summary, counterparty = :counterparty,
                 case_status = :case_status, notes = :notes{$legacySet},
                 updated_by_usr_uid = :updated_by_usr_uid, updated_at = NOW()
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute($params);
        if ($before['record_origin'] === 'legacy') {
            $documentStmt = $db->prepare(
                "UPDATE emcore_trade_documents
                 SET document_number = :document_number, document_date = :document_date,
                     updated_by_usr_uid = :updated_by_usr_uid, updated_at = NOW()
                 WHERE case_id = :case_id AND document_type = :document_type"
            );
            foreach ($legacyDocuments as $type => $document) {
                $documentStmt->execute([
                    ':document_number' => $document['number'],
                    ':document_date' => $document['date'],
                    ':updated_by_usr_uid' => $actor['USR_UID'],
                    ':case_id' => $id,
                    ':document_type' => $type,
                ]);
            }
        }
        $after = emcore_trade_case_row($db, $id);
        emcore_audit(EMCORE_TRADE_MODULE, 'update', 'trade_case', $id, $before, $after, [
            'legacy_document_numbers' => $before['record_origin'] === 'legacy'
                ? array_column($legacyDocuments, 'number')
                : null,
        ]);
        $db->commit();
        emcore_json(['success' => true, 'duplicate_numbers' => $duplicateNumbers]);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

if ($action === 'set_document_status') {
    $documentId = emcore_positive_id('document_id');
    $status = emcore_trade_enum('document_status', [
        'not_started', 'draft', 'under_review', 'revision_requested', 'approved', 'issued',
    ]);
    $approvedByName = emcore_string('approved_by_name', false, 200);
    $statusNote = emcore_string('status_note', false, 1000);
    $documentDate = emcore_trade_date('document_date');
    $db->beginTransaction();
    try {
        $before = emcore_trade_document_row($db, $documentId, true);
        if (!$before) {
            throw new EmcoreHttpException(404, 'سند یافت نشد');
        }
        if ($before['case_origin'] === 'legacy') {
            throw new EmcoreHttpException(409, 'وضعیت اسناد سابقهٔ قبلی از مسیر ویرایش همان سابقه ثبت می‌شود');
        }
        if ($status !== 'not_started') {
            emcore_trade_assert_document_prerequisite($db, $before);
        }
        if (in_array($status, ['approved', 'issued'], true)) {
            $versionCount = $db->prepare(
                'SELECT COUNT(*) FROM emcore_trade_document_versions WHERE document_id = :id AND deleted_at IS NULL'
            );
            $versionCount->execute([':id' => $documentId]);
            if ((int)$versionCount->fetchColumn() === 0) {
                throw new EmcoreHttpException(409, 'پیش از تأیید، حداقل یک نسخه فایل ثبت کنید');
            }
        }
        $approved = in_array($status, ['approved', 'issued'], true);
        $stmt = $db->prepare(
            "UPDATE emcore_trade_documents
             SET document_status = :document_status,
                 document_date = :document_date,
                 approved_by_name = :approved_by_name,
                 approved_at = " . ($approved ? 'NOW()' : 'NULL') . ",
                 status_note = :status_note,
                 updated_by_usr_uid = :updated_by_usr_uid,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ':document_status' => $status,
            ':document_date' => $documentDate,
            ':approved_by_name' => $approved ? $approvedByName : null,
            ':status_note' => $statusNote,
            ':updated_by_usr_uid' => $actor['USR_UID'],
            ':id' => $documentId,
        ]);
        $after = emcore_trade_document_row($db, $documentId);
        emcore_audit(EMCORE_TRADE_MODULE, 'update', 'trade_document', $documentId, $before, $after, [
            'operation' => 'status_change',
        ]);
        $db->commit();
        emcore_json(['success' => true]);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

if ($action === 'upload_document') {
    $documentId = emcore_positive_id('document_id');
    $isFinal = emcore_post_bool('is_final');
    $requestedFileRole = emcore_trade_enum('file_role', [
        'editable', 'issued_copy', 'other',
    ], false);
    $changeNote = emcore_string('change_note', false, 1000);
    $file = null;
    $db->beginTransaction();
    try {
        $document = emcore_trade_document_row($db, $documentId, true);
        if (!$document) {
            throw new EmcoreHttpException(404, 'سند یافت نشد');
        }
        emcore_trade_assert_document_prerequisite($db, $document);
        $isLegacy = $document['case_origin'] === 'legacy';
        $fileRole = $isLegacy ? ($requestedFileRole ?: 'other') : 'revision';
        $versionState = $isLegacy ? 'historical' : ($isFinal ? 'final' : 'draft');
        $revisionStmt = $db->prepare(
            'SELECT COALESCE(MAX(revision_number), 0) + 1 FROM emcore_trade_document_versions WHERE document_id = :id'
        );
        $revisionStmt->execute([':id' => $documentId]);
        $revision = (int)$revisionStmt->fetchColumn();
        $file = emcore_trade_store_upload(
            'file',
            'document',
            'cases/' . $document['case_id'] . '/' . $document['document_type']
        );
        $params = emcore_trade_file_insert_values($file);
        $params[':document_id'] = $documentId;
        $params[':revision_number'] = $revision;
        $params[':version_state'] = $versionState;
        $params[':file_role'] = $fileRole;
        $params[':change_note'] = $changeNote;
        $params[':uploaded_by_usr_uid'] = $actor['USR_UID'];
        $stmt = $db->prepare(
            "INSERT INTO emcore_trade_document_versions
                (document_id, revision_number, version_state, file_role, original_filename,
                 stored_filename, storage_path, extension, mime_type, file_size,
                 sha256, change_note, uploaded_by_usr_uid)
             VALUES
                (:document_id, :revision_number, :version_state, :file_role, :original_filename,
                 :stored_filename, :storage_path, :extension, :mime_type, :file_size,
                 :sha256, :change_note, :uploaded_by_usr_uid)"
        );
        $stmt->execute($params);
        $versionId = (int)$db->lastInsertId();
        $newStatus = $isLegacy ? 'archived' : ($isFinal ? 'issued' : 'draft');
        $approvalReset = !$isLegacy && !$isFinal;
        $approvalResetSql = $approvalReset ? ', approved_by_name = NULL, approved_at = NULL' : '';
        $db->prepare(
            "UPDATE emcore_trade_documents
             SET document_status = :status, updated_by_usr_uid = :usr_uid,
                 updated_at = NOW(){$approvalResetSql}
             WHERE id = :id"
        )->execute([':status' => $newStatus, ':usr_uid' => $actor['USR_UID'], ':id' => $documentId]);
        $after = [
            'id' => $versionId,
            'document_id' => $documentId,
            'revision_number' => $revision,
            'version_state' => $versionState,
            'file_role' => $fileRole,
            'original_filename' => $file['original_filename'],
            'extension' => $file['extension'],
            'file_size' => $file['file_size'],
            'sha256' => $file['sha256'],
            'change_note' => $changeNote,
        ];
        emcore_audit(
            EMCORE_TRADE_MODULE,
            'create',
            'trade_document_version',
            $versionId,
            null,
            $after,
            [
                'document_status_before' => $document['document_status'],
                'document_status_after' => $newStatus,
                'approval_reset' => $approvalReset,
            ]
        );
        $db->commit();
        emcore_json(['success' => true, 'id' => $versionId, 'revision_number' => $revision], 201);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        emcore_trade_remove_failed_upload($file);
        throw $exception;
    }
}

if ($action === 'upload_attachment') {
    $caseId = emcore_positive_id('case_id');
    $category = emcore_trade_enum('category', [
        'bill_of_lading', 'certificate_of_origin', 'mtc', 'weighbridge',
        'insurance', 'customs', 'other',
    ]);
    $title = emcore_string('title', true, 255);
    $referenceNumber = emcore_string('reference_number', false, 150);
    $notes = emcore_string('attachment_notes', false, 1000);
    $file = null;
    $db->beginTransaction();
    try {
        $case = emcore_trade_case_row($db, $caseId, true);
        if (!$case) {
            throw new EmcoreHttpException(404, 'پرونده یافت نشد');
        }
        $file = emcore_trade_store_upload('file', 'attachment', 'cases/' . $caseId . '/attachments');
        $params = emcore_trade_file_insert_values($file);
        $params[':case_id'] = $caseId;
        $params[':category'] = $category;
        $params[':title'] = $title;
        $params[':reference_number'] = $referenceNumber;
        $params[':notes'] = $notes;
        $params[':uploaded_by_usr_uid'] = $actor['USR_UID'];
        $stmt = $db->prepare(
            "INSERT INTO emcore_trade_attachments
                (case_id, category, title, reference_number, notes, original_filename,
                 stored_filename, storage_path, extension, mime_type, file_size,
                 sha256, uploaded_by_usr_uid)
             VALUES
                (:case_id, :category, :title, :reference_number, :notes, :original_filename,
                 :stored_filename, :storage_path, :extension, :mime_type, :file_size,
                 :sha256, :uploaded_by_usr_uid)"
        );
        $stmt->execute($params);
        $attachmentId = (int)$db->lastInsertId();
        $after = [
            'id' => $attachmentId,
            'case_id' => $caseId,
            'category' => $category,
            'title' => $title,
            'reference_number' => $referenceNumber,
            'original_filename' => $file['original_filename'],
            'file_size' => $file['file_size'],
            'sha256' => $file['sha256'],
        ];
        emcore_audit(EMCORE_TRADE_MODULE, 'create', 'trade_attachment', $attachmentId, null, $after);
        $db->commit();
        emcore_json(['success' => true, 'id' => $attachmentId], 201);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        emcore_trade_remove_failed_upload($file);
        throw $exception;
    }
}

if ($action === 'upload_template') {
    $issuerId = emcore_positive_id('issuer_id');
    $documentType = emcore_trade_enum('document_type', ['pi', 'ci', 'pl']);
    $templateNote = emcore_string('template_note', false, 1000);
    $file = null;
    $db->beginTransaction();
    try {
        $issuerStmt = $db->prepare(
            "SELECT id, issuer_key FROM emcore_trade_issuers
             WHERE id = :id AND is_active = 1 LIMIT 1 FOR UPDATE"
        );
        $issuerStmt->execute([':id' => $issuerId]);
        $issuer = $issuerStmt->fetch();
        if (!$issuer) {
            throw new EmcoreHttpException(422, 'شرکت فعال یافت نشد');
        }
        $revisionStmt = $db->prepare(
            "SELECT COALESCE(MAX(revision_number), 0) + 1
             FROM emcore_trade_templates
             WHERE issuer_id = :issuer_id AND document_type = :document_type"
        );
        $revisionStmt->execute([':issuer_id' => $issuerId, ':document_type' => $documentType]);
        $revision = (int)$revisionStmt->fetchColumn();
        $file = emcore_trade_store_upload(
            'file',
            'template',
            'templates/' . $issuer['issuer_key'] . '/' . $documentType
        );
        $db->prepare(
            "UPDATE emcore_trade_templates
             SET is_active = 0, superseded_at = NOW()
             WHERE issuer_id = :issuer_id AND document_type = :document_type
               AND is_active = 1 AND deleted_at IS NULL"
        )->execute([':issuer_id' => $issuerId, ':document_type' => $documentType]);
        $params = emcore_trade_file_insert_values($file);
        $params[':issuer_id'] = $issuerId;
        $params[':document_type'] = $documentType;
        $params[':revision_number'] = $revision;
        $params[':template_note'] = $templateNote;
        $params[':uploaded_by_usr_uid'] = $actor['USR_UID'];
        $stmt = $db->prepare(
            "INSERT INTO emcore_trade_templates
                (issuer_id, document_type, revision_number, is_active,
                 original_filename, stored_filename, storage_path, extension,
                 mime_type, file_size, sha256, template_note, uploaded_by_usr_uid)
             VALUES
                (:issuer_id, :document_type, :revision_number, 1,
                 :original_filename, :stored_filename, :storage_path, :extension,
                 :mime_type, :file_size, :sha256, :template_note, :uploaded_by_usr_uid)"
        );
        $stmt->execute($params);
        $templateId = (int)$db->lastInsertId();
        $after = [
            'id' => $templateId,
            'issuer_id' => $issuerId,
            'document_type' => $documentType,
            'revision_number' => $revision,
            'original_filename' => $file['original_filename'],
            'file_size' => $file['file_size'],
            'sha256' => $file['sha256'],
            'template_note' => $templateNote,
        ];
        emcore_audit(EMCORE_TRADE_MODULE, 'create', 'trade_template', $templateId, null, $after);
        $db->commit();
        emcore_json(['success' => true, 'id' => $templateId, 'revision_number' => $revision], 201);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        emcore_trade_remove_failed_upload($file);
        throw $exception;
    }
}

if ($action === 'delete_file') {
    $kind = emcore_trade_enum('file_kind', ['version', 'attachment', 'template']);
    $fileId = emcore_positive_id('file_id');
    $tableMap = [
        'version' => ['emcore_trade_document_versions', 'trade_document_version'],
        'attachment' => ['emcore_trade_attachments', 'trade_attachment'],
        'template' => ['emcore_trade_templates', 'trade_template'],
    ];
    $table = $tableMap[$kind][0];
    $entityType = $tableMap[$kind][1];
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM {$table} WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE");
        $stmt->execute([':id' => $fileId]);
        $before = $stmt->fetch();
        if (!$before) {
            throw new EmcoreHttpException(404, 'فایل یافت نشد');
        }
        if ($kind === 'template' && (int)$before['is_active'] === 1) {
            throw new EmcoreHttpException(409, 'قالب فعال حذف نمی‌شود؛ ابتدا قالب جدید را جایگزین کنید');
        }
        $db->prepare("UPDATE {$table} SET deleted_at = NOW() WHERE id = :id")->execute([':id' => $fileId]);
        $after = $before;
        $after['deleted_at'] = date('Y-m-d H:i:s');
        emcore_audit(EMCORE_TRADE_MODULE, 'delete', $entityType, $fileId, $before, $after, [
            'physical_file_retained' => true,
        ]);
        $db->commit();
        emcore_json(['success' => true]);
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

$id = emcore_positive_id('id');
$db->beginTransaction();
try {
    $before = emcore_trade_case_row($db, $id, true);
    if (!$before) {
        throw new EmcoreHttpException(404, 'پرونده یافت نشد');
    }
    $db->prepare(
        "UPDATE emcore_trade_cases
         SET case_status = 'cancelled', deleted_at = NOW(), updated_at = NOW(),
             updated_by_usr_uid = :usr_uid
         WHERE id = :id AND deleted_at IS NULL"
    )->execute([':usr_uid' => $actor['USR_UID'], ':id' => $id]);
    $after = emcore_trade_case_row($db, $id, false, true);
    emcore_audit(EMCORE_TRADE_MODULE, 'delete', 'trade_case', $id, $before, $after, [
        'number_retained' => true,
        'physical_files_retained' => true,
    ]);
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
emcore_json(['success' => true]);
