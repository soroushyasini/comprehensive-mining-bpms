<?php

// Dry-run-first backfill from the authoritative legacy tender/auction table.
//
// Usage:
//   php tools/import_legacy_procurement_notices.php
//   php tools/import_legacy_procurement_notices.php \
//       --commit --actor-usr-uid=<32-character ProcessMaker USR_UID>
//
// The source table is read-only. The importer never treats legacy file
// identifiers as downloadable files.
// It records them as traceable references until an operator migrates the
// corresponding physical content into the private EMCORE storage root.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This importer is CLI-only.\n");
    exit(2);
}

function procurement_import_option($prefix)
{
    global $argv;
    foreach ($argv as $argument) {
        if (strpos($argument, $prefix . '=') === 0) {
            return substr($argument, strlen($prefix) + 1);
        }
    }
    return null;
}

function procurement_import_has_flag($flag)
{
    global $argv;
    return in_array($flag, $argv, true);
}

function procurement_import_json($value)
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode procurement import JSON.');
    }
    return $json;
}

function procurement_import_normalize_text($value)
{
    $normalized = str_replace(['ي', 'ك'], ['ی', 'ک'], trim((string)$value));
    $normalized = preg_replace('/[\x{200C}\s]+/u', ' ', $normalized);
    return $normalized === null ? trim((string)$value) : trim($normalized);
}

function procurement_import_latin_digits($value)
{
    return strtr((string)$value, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function procurement_import_date_pair($db, $value, $field, &$errors)
{
    $normalized = procurement_import_latin_digits(trim((string)$value));
    if ($normalized === '' || strpos($normalized, '0000-00-00') === 0) {
        return [null, null];
    }
    $normalized = str_replace('-', '/', substr($normalized, 0, 10));
    if (!preg_match('/^1[34][0-9]{2}\/(0[1-9]|1[0-2])\/([0-2][0-9]|3[01])$/', $normalized)) {
        $errors[] = $field . ':invalid_format';
        return [null, null];
    }
    $stmt = $db->prepare('SELECT shamsi_slash_to_gregorian_date(:date_fa)');
    $stmt->execute([':date_fa' => $normalized]);
    $gregorian = $stmt->fetchColumn();
    if (!$gregorian) {
        $errors[] = $field . ':invalid_date';
        return [null, null];
    }
    return [$normalized, $gregorian];
}

function procurement_import_created_at($value)
{
    $value = procurement_import_latin_digits(trim((string)$value));
    foreach (['j/n/Y H:i:s', 'd/m/Y H:i:s', 'Y-m-d H:i:s'] as $format) {
        $date = DateTime::createFromFormat('!' . $format, $value);
        $dateErrors = DateTime::getLastErrors();
        if ($date !== false && ($dateErrors === false
            || ((int)$dateErrors['warning_count'] === 0 && (int)$dateErrors['error_count'] === 0))) {
            return $date->format('Y-m-d H:i:s');
        }
    }
    return null;
}

function procurement_import_notice_type($value, &$errors)
{
    $normalized = procurement_import_normalize_text($value);
    if ($normalized === 'مناقصه') {
        return 'tender';
    }
    if ($normalized === 'مزایده') {
        return 'auction';
    }
    $errors[] = 'type:unknown';
    return 'unknown';
}

function procurement_import_submission_method($value, &$errors)
{
    $normalized = procurement_import_normalize_text($value);
    if ($normalized === '') {
        return null;
    }
    if ($normalized === 'فیزیکی') {
        return 'physical';
    }
    if ($normalized === 'آنلاین') {
        return 'online';
    }
    $errors[] = 'nahve_sherkat:mapped_to_other';
    return 'other';
}

function procurement_import_participation_status($value, &$errors)
{
    $map = [
        'ثبت' => 'registered',
        'علاقه مند' => 'interested',
        'علاقه‌مند' => 'interested',
        'ارسال مدارک' => 'documents_submitted',
        'برنده' => 'won',
        'شکست' => 'lost',
    ];
    $normalized = procurement_import_normalize_text($value);
    if (isset($map[$normalized])) {
        return $map[$normalized];
    }
    $errors[] = 'vaze_sherkat:unknown';
    return 'unknown';
}

function procurement_import_extended($value, &$errors)
{
    $normalized = procurement_import_normalize_text($value);
    if ($normalized === '') {
        return null;
    }
    if ($normalized === 'بله') {
        return 1;
    }
    if ($normalized === 'خیر') {
        return 0;
    }
    $errors[] = 'vaze_tamdid:unknown';
    return null;
}

function procurement_import_alert_days($value, &$errors)
{
    $normalized = procurement_import_normalize_text($value);
    if ($normalized === '' || $normalized === 'پنج روز') {
        return 5;
    }
    if ($normalized === 'یک روز') {
        return 1;
    }
    if ($normalized === 'سه روز') {
        return 3;
    }
    $numeric = procurement_import_latin_digits($normalized);
    if (preg_match('/^[0-9]{1,3}$/', $numeric) && (int)$numeric <= 365) {
        return (int)$numeric;
    }
    $errors[] = 'alarm:defaulted_to_5';
    return 5;
}

function procurement_import_add_legacy_file($db, $procurementId, $role, $reference, $name, $actorUsrUid)
{
    $reference = trim((string)$reference);
    if ($reference === '') {
        return false;
    }
    $name = trim((string)$name);
    $extension = $name !== '' ? strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) : null;
    $stmt = $db->prepare(
        "INSERT INTO emcore_procurement_files
            (procurement_id, file_role, record_origin, original_filename,
             extension, legacy_reference, uploaded_by_usr_uid)
         VALUES
            (:procurement_id, :file_role, 'legacy_reference', :original_filename,
             :extension, :legacy_reference, :uploaded_by_usr_uid)"
    );
    $stmt->execute([
        ':procurement_id' => $procurementId,
        ':file_role' => $role,
        ':original_filename' => $name !== '' ? mb_substr($name, 0, 255, 'UTF-8') : null,
        ':extension' => $extension !== '' ? mb_substr($extension, 0, 20, 'UTF-8') : null,
        ':legacy_reference' => mb_substr($reference, 0, 255, 'UTF-8'),
        ':uploaded_by_usr_uid' => $actorUsrUid,
    ]);
    return true;
}

$commit = procurement_import_has_flag('--commit');
$actorUsrUid = trim((string)procurement_import_option('--actor-usr-uid'));
if ($commit && !preg_match('/^[A-Za-z0-9]{32}$/', $actorUsrUid)) {
    fwrite(STDERR, "--actor-usr-uid=<32-character ProcessMaker USR_UID> is required with --commit.\n");
    exit(2);
}

$localFile = dirname(__DIR__) . '/emcore_api/emcore_config.php';
$local = file_exists($localFile) ? require $localFile : [];
if (!is_array($local)) {
    throw new RuntimeException('Invalid EMCORE local configuration.');
}
$dsn = getenv('EMCORE_DB_DSN') ?: (isset($local['db_dsn']) ? $local['db_dsn'] : '');
$dbUser = getenv('EMCORE_DB_USER') ?: (isset($local['db_user']) ? $local['db_user'] : '');
$passwordFromEnvironment = getenv('EMCORE_DB_PASSWORD');
$dbPassword = $passwordFromEnvironment !== false
    ? $passwordFromEnvironment
    : (isset($local['db_password']) ? $local['db_password'] : '');
if ($dsn === '' || $dbUser === '') {
    throw new RuntimeException('EMCORE database configuration is missing.');
}
$db = new PDO($dsn, $dbUser, $dbPassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

if ($commit) {
    $actor = $db->prepare(
        "SELECT u.USR_UID
         FROM USERS u
         JOIN emcore_user_permissions p ON p.usr_uid = u.USR_UID
         WHERE u.USR_UID = :usr_uid AND u.USR_STATUS = 'ACTIVE'
           AND p.module_key = 'procurement_notices' AND p.can_create = 1
         LIMIT 1"
    );
    $actor->execute([':usr_uid' => $actorUsrUid]);
    if (!$actor->fetch()) {
        throw new RuntimeException('The import actor is not active or lacks procurement create permission.');
    }
}

$sourceTable = 'prc_db_mozayedat_monaghesat_copy1';
$expectedColumns = [
    'type', 'akhz', 'name', 'tonage', 'mozayede_shomare', 'mablagh', 'vahed',
    'dastgahejrai', 'nahve_sherkat', 'deadline_asnad', 'tahvil_bar',
    'deadline_pasokh', 'alarm', 'zemanat_nameh', 'zemanat_nameh_2', 'insert_date',
    'vahed_marbute', 'id', 'tamin_konnande', 'date_created', 'file_field',
    'LEFT_DAYS', 'category', 'subcategory', 'products', 'haffari_area', 'file',
    'file_name', 'file_nahaee', 'file_name_nahaee', 'vaze_tamdid', 'vaze_sherkat',
    'dalil_alaghe',
];
$columnsStmt = $db->prepare(
    "SELECT COLUMN_NAME
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name
     ORDER BY ORDINAL_POSITION"
);
$columnsStmt->execute([':table_name' => $sourceTable]);
$actualColumns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
if (!$actualColumns) {
    throw new RuntimeException('The legacy procurement source table does not exist.');
}
$missingColumns = array_values(array_diff($expectedColumns, $actualColumns));
if ($missingColumns) {
    throw new RuntimeException(
        'The legacy procurement source table is missing columns: ' . implode(', ', $missingColumns)
    );
}
$columnSql = '`' . implode('`,`', $expectedColumns) . '`';
$source = $db->query(
    'SELECT ' . $columnSql . ' FROM `prc_db_mozayedat_monaghesat_copy1` ORDER BY `id`'
);

$batchId = bin2hex(random_bytes(16));
$sourceName = $sourceTable;
$stats = [
    'source_rows' => 0,
    'ready' => 0,
    'imported' => 0,
    'already_imported' => 0,
    'needs_review' => 0,
    'skipped' => 0,
    'legacy_file_references' => 0,
];
$reviewSamples = [];

if ($commit) {
    $stmt = $db->prepare(
        "INSERT INTO emcore_procurement_import_batches
            (batch_id, source_name, actor_usr_uid, started_at)
         VALUES (:batch_id, :source_name, :actor_usr_uid, NOW())"
    );
    $stmt->execute([
        ':batch_id' => $batchId,
        ':source_name' => $sourceName,
        ':actor_usr_uid' => $actorUsrUid,
    ]);
}

while (($row = $source->fetch()) !== false) {
    $stats['source_rows']++;
    $errors = [];
    $legacyIdRaw = procurement_import_latin_digits(trim((string)$row['id']));
    $legacyId = preg_match('/^[1-9][0-9]*$/', $legacyIdRaw) ? (int)$legacyIdRaw : null;
    if ($legacyId === null) {
        $errors[] = 'id:invalid';
    }

    $noticeType = procurement_import_notice_type($row['type'], $errors);
    $title = procurement_import_normalize_text($row['name']);
    if ($title === '') {
        $errors[] = 'name:blank_placeholder_used';
        $title = 'بدون عنوان (سابقه ' . ($legacyId ?: $stats['source_rows']) . ')';
    }
    list($registeredFa, $registeredEn) = procurement_import_date_pair(
        $db,
        $row['insert_date'],
        'insert_date',
        $errors
    );
    if (trim((string)$row['insert_date']) === '') {
        $errors[] = 'insert_date:missing';
    }
    list($documentsFa, $documentsEn) = procurement_import_date_pair(
        $db,
        $row['deadline_asnad'],
        'deadline_asnad',
        $errors
    );
    list($responseFa, $responseEn) = procurement_import_date_pair(
        $db,
        $row['deadline_pasokh'],
        'deadline_pasokh',
        $errors
    );
    if ($documentsEn !== null && $responseEn !== null && $documentsEn > $responseEn) {
        $errors[] = 'deadline_asnad:after_deadline_pasokh';
    }
    $createdAt = procurement_import_created_at($row['date_created']);
    if ($createdAt === null) {
        $errors[] = 'date_created:invalid_defaulted_to_now';
        $createdAt = date('Y-m-d H:i:s');
    }
    $submissionMethod = procurement_import_submission_method($row['nahve_sherkat'], $errors);
    $participationStatus = procurement_import_participation_status($row['vaze_sherkat'], $errors);
    $isExtended = procurement_import_extended($row['vaze_tamdid'], $errors);
    $alertDays = procurement_import_alert_days($row['alarm'], $errors);

    if ($errors) {
        $stats['needs_review']++;
        if (count($reviewSamples) < 25) {
            $reviewSamples[] = ['legacy_id' => $legacyId, 'errors' => $errors];
        }
    } else {
        $stats['ready']++;
    }
    if ($legacyId === null) {
        $stats['skipped']++;
        continue;
    }
    if (!$commit) {
        continue;
    }

    $db->beginTransaction();
    try {
        $existing = $db->prepare(
            'SELECT id FROM emcore_procurement_notices WHERE legacy_source_id = :legacy_source_id LIMIT 1 FOR UPDATE'
        );
        $existing->execute([':legacy_source_id' => $legacyId]);
        if ($existing->fetchColumn()) {
            $stats['already_imported']++;
            $db->commit();
            continue;
        }

        $stmt = $db->prepare(
            "INSERT INTO emcore_procurement_notices
                (legacy_source_id, legacy_import_batch_id, record_origin, notice_type,
                 source_name, supplier_name, title, quantity_text, reference_number,
                 amount_text, currency, contracting_authority, submission_method,
                 delivery_term, primary_guarantee, secondary_guarantee,
                 registered_on_fa, registered_on_en,
                 documents_deadline_fa, documents_deadline_en,
                 response_deadline_fa, response_deadline_en, alert_lead_days,
                 responsible_unit, category_name, subcategory_name, product_name,
                 drilling_area, participation_status, interest_reason, is_extended,
                 legacy_source_data, created_by_usr_uid, updated_by_usr_uid,
                 created_at, updated_at)
             VALUES
                (:legacy_source_id, :legacy_import_batch_id, 'legacy', :notice_type,
                 :source_name, :supplier_name, :title, :quantity_text, :reference_number,
                 :amount_text, :currency, :contracting_authority, :submission_method,
                 :delivery_term, :primary_guarantee, :secondary_guarantee,
                 :registered_on_fa, :registered_on_en,
                 :documents_deadline_fa, :documents_deadline_en,
                 :response_deadline_fa, :response_deadline_en, :alert_lead_days,
                 :responsible_unit, :category_name, :subcategory_name, :product_name,
                 :drilling_area, :participation_status, :interest_reason, :is_extended,
                 :legacy_source_data, :created_by_usr_uid, :updated_by_usr_uid,
                 :created_at, :updated_at)"
        );
        $stmt->execute([
            ':legacy_source_id' => $legacyId,
            ':legacy_import_batch_id' => $batchId,
            ':notice_type' => $noticeType,
            ':source_name' => procurement_import_normalize_text($row['akhz']) ?: null,
            ':supplier_name' => procurement_import_normalize_text($row['tamin_konnande']) ?: null,
            ':title' => mb_substr($title, 0, 1000, 'UTF-8'),
            ':quantity_text' => procurement_import_normalize_text($row['tonage']) ?: null,
            ':reference_number' => procurement_import_normalize_text($row['mozayede_shomare']) ?: null,
            ':amount_text' => procurement_import_normalize_text($row['mablagh']) ?: null,
            ':currency' => procurement_import_normalize_text($row['vahed']) ?: null,
            ':contracting_authority' => procurement_import_normalize_text($row['dastgahejrai']) ?: null,
            ':submission_method' => $submissionMethod,
            ':delivery_term' => procurement_import_normalize_text($row['tahvil_bar']) ?: null,
            ':primary_guarantee' => procurement_import_normalize_text($row['zemanat_nameh']) ?: null,
            ':secondary_guarantee' => procurement_import_normalize_text($row['zemanat_nameh_2']) ?: null,
            ':registered_on_fa' => $registeredFa,
            ':registered_on_en' => $registeredEn,
            ':documents_deadline_fa' => $documentsFa,
            ':documents_deadline_en' => $documentsEn,
            ':response_deadline_fa' => $responseFa,
            ':response_deadline_en' => $responseEn,
            ':alert_lead_days' => $alertDays,
            ':responsible_unit' => procurement_import_normalize_text($row['vahed_marbute']) ?: null,
            ':category_name' => procurement_import_normalize_text($row['category']) ?: null,
            ':subcategory_name' => procurement_import_normalize_text($row['subcategory']) ?: null,
            ':product_name' => procurement_import_normalize_text($row['products']) ?: null,
            ':drilling_area' => procurement_import_normalize_text($row['haffari_area']) ?: null,
            ':participation_status' => $participationStatus,
            ':interest_reason' => procurement_import_normalize_text($row['dalil_alaghe']) ?: null,
            ':is_extended' => $isExtended,
            ':legacy_source_data' => procurement_import_json($row),
            ':created_by_usr_uid' => $actorUsrUid,
            ':updated_by_usr_uid' => $actorUsrUid,
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
        ]);
        $procurementId = (int)$db->lastInsertId();

        $seenReferences = [];
        $fileCandidates = [
            ['notice_document', $row['file'], $row['file_name'], 'processmaker_app_document'],
            ['final_submission', $row['file_nahaee'], $row['file_name_nahaee'], 'processmaker_app_document'],
            ['notice_document', $row['file_field'], $row['file_field'], 'legacy_file_field'],
        ];
        foreach ($fileCandidates as $candidate) {
            list($role, $reference, $name, $referenceType) = $candidate;
            $reference = trim((string)$reference);
            if ($reference === '' || isset($seenReferences[$referenceType . ':' . $reference])) {
                continue;
            }
            $seenReferences[$referenceType . ':' . $reference] = true;
            if (procurement_import_add_legacy_file(
                $db,
                $procurementId,
                $role,
                $referenceType . ':' . $reference,
                $name,
                $actorUsrUid
            )) {
                $stats['legacy_file_references']++;
            }
        }

        $audit = $db->prepare(
            "INSERT INTO emcore_audit_log
                (request_id, actor_usr_uid, module_key, action, entity_type,
                 entity_id, after_data, metadata, created_at)
             VALUES
                (:request_id, :actor_usr_uid, 'procurement_notices', 'create',
                 'procurement_notice', :entity_id, :after_data, :metadata, NOW())"
        );
        $audit->execute([
            ':request_id' => $batchId,
            ':actor_usr_uid' => $actorUsrUid,
            ':entity_id' => (string)$procurementId,
            ':after_data' => procurement_import_json([
                'id' => $procurementId,
                'legacy_source_id' => $legacyId,
                'record_origin' => 'legacy',
                'notice_type' => $noticeType,
                'title' => $title,
            ]),
            ':metadata' => procurement_import_json([
                'source' => $sourceName,
                'batch_id' => $batchId,
                'review_flags' => $errors,
            ]),
        ]);
        $db->commit();
        $stats['imported']++;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $stats['skipped']++;
        if (count($reviewSamples) < 25) {
            $reviewSamples[] = [
                'legacy_id' => $legacyId,
                'errors' => ['database:' . $exception->getMessage()],
            ];
        }
    }
}

if ($commit) {
    $stmt = $db->prepare(
        "UPDATE emcore_procurement_import_batches
         SET source_row_count = :source_row_count,
             imported_count = :imported_count,
             skipped_count = :skipped_count,
             review_count = :review_count,
             summary_json = :summary_json,
             completed_at = NOW()
         WHERE batch_id = :batch_id"
    );
    $stmt->execute([
        ':source_row_count' => $stats['source_rows'],
        ':imported_count' => $stats['imported'],
        ':skipped_count' => $stats['skipped'] + $stats['already_imported'],
        ':review_count' => $stats['needs_review'],
        ':summary_json' => procurement_import_json($stats),
        ':batch_id' => $batchId,
    ]);
}

$result = [
    'mode' => $commit ? 'commit' : 'dry-run',
    'source' => $sourceName,
    'batch_id' => $batchId,
    'stats' => $stats,
    'review_samples' => $reviewSamples,
];
echo procurement_import_json($result) . PHP_EOL;
exit($stats['skipped'] > 0 ? 1 : 0);
