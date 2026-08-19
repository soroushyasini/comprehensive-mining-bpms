<?php

// Dry-run-first importer for legacy drilling reports. The allowlisted MySQL
// source table is authoritative; CSV remains supported for recovery/diagnosis.
// Usage:
//   php tools/import_legacy_drilling.php --source-table=prc_db_gozaresh_ruzane_copy2
//   php tools/import_legacy_drilling.php /path/to/report.csv
// Add --commit --actor-usr-uid=<USR_UID> --create-boreholes only after dry-run acceptance.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This importer is CLI-only.\n");
    exit(2);
}

function drilling_import_option($prefix)
{
    global $argv;
    foreach ($argv as $argument) {
        if (strpos($argument, $prefix . '=') === 0) {
            return substr($argument, strlen($prefix) + 1);
        }
    }
    return null;
}

function drilling_import_has_flag($flag)
{
    global $argv;
    return in_array($flag, $argv, true);
}

function drilling_import_open_csv($csvPath)
{
    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open legacy CSV.');
    }

    // Consume the UTF-8 BOM before detecting the export format.
    $prefix = fread($handle, 3);
    if ($prefix !== "\xEF\xBB\xBF") {
        rewind($handle);
    }
    $dataStart = ftell($handle);
    $firstLine = fgets($handle);
    $nested = $firstLine !== false && strpos($firstLine, '"id,""Projects""') === 0;
    fseek($handle, $dataStart);

    return [
        'handle' => $handle,
        'nested' => $nested,
        'pending_line' => null,
    ];
}

function drilling_import_read_csv_row(&$source)
{
    if (!$source['nested']) {
        return fgetcsv($source['handle']);
    }

    if ($source['pending_line'] !== null) {
        $record = $source['pending_line'];
        $source['pending_line'] = null;
    } else {
        $record = fgets($source['handle']);
    }
    if ($record === false) {
        return false;
    }

    // The supplied ProcessMaker/Navicat export wraps each original CSV record
    // in another quoted field, but preserves raw newlines inside text columns.
    // A new report is identified by its positive numeric legacy ID at column 1.
    while (($line = fgets($source['handle'])) !== false) {
        if (preg_match('/^"[0-9]+,""/', $line)) {
            $source['pending_line'] = $line;
            break;
        }
        $record .= $line;
    }

    $record = rtrim($record, "\r\n");
    if (strlen($record) < 2 || $record[0] !== '"' || substr($record, -1) !== '"') {
        return [$record];
    }

    $innerRecord = substr($record, 1, -1);
    return str_getcsv(str_replace('""', '"', $innerRecord));
}

function drilling_import_read_source_row(&$source)
{
    if ($source['type'] === 'table') {
        $row = $source['statement']->fetch(PDO::FETCH_ASSOC);
        return $row === false ? false : array_values($row);
    }
    return drilling_import_read_csv_row($source['csv']);
}

function drilling_import_repair_mojibake($value)
{
    $value = (string)$value;
    if ($value === '' || !preg_match('/[ÂÃØÙÚÛâ]/u', $value) || !function_exists('iconv')) {
        return $value;
    }

    // The export contains UTF-8 bytes that were decoded as Windows-1252 and
    // then encoded as UTF-8 again. Converting the visible mojibake characters
    // back to Windows-1252 bytes restores the original valid UTF-8 Persian.
    $repaired = @iconv('UTF-8', 'Windows-1252', $value);
    return $repaired !== false && preg_match('//u', $repaired) ? $repaired : $value;
}

function drilling_import_repair_columns($values, &$wasRepaired)
{
    $wasRepaired = false;
    if (count($values) === 45) {
        return $values;
    }

    // Columns 35 and 36 are the two checklist groups. In 66 source rows their
    // comma-separated selections were exported without the inner CSV quoting.
    // The final eight columns remain stable and start with insert_date, so use
    // that anchor and retain every checklist fragment rather than dropping it.
    $insertDateIndex = count($values) - 8;
    if ($insertDateIndex < 35
        || !isset($values[$insertDateIndex])
        || !preg_match('/^\d{1,2}\/\d{1,2}\/\d{4} \d{2}:\d{2}:\d{2}$/', trim((string)$values[$insertDateIndex]))) {
        return $values;
    }

    $checklistFragments = array_slice($values, 35, $insertDateIndex - 35);
    if (!$checklistFragments) {
        return $values;
    }
    $repairedValues = array_merge(
        array_slice($values, 0, 35),
        [implode(',', $checklistFragments), ''],
        array_slice($values, $insertDateIndex)
    );
    if (count($repairedValues) === 45) {
        $wasRepaired = true;
        return $repairedValues;
    }
    return $values;
}

function drilling_import_normalize_text($value)
{
    $value = drilling_import_repair_mojibake($value);
    $value = str_replace(['ي', 'ك', "\xE2\x80\x8C"], ['ی', 'ک', ' '], trim($value));
    return preg_replace('/\s+/u', ' ', $value);
}

function drilling_import_json($value)
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode import JSON.');
    }
    return $json;
}

function drilling_import_decimal($row, $field, &$errors, $default = '0')
{
    $value = trim((string)$row[$field]);
    if ($value === '') {
        return $default;
    }
    if (!preg_match('/^-?\d{1,14}(?:\.\d{1,3})?$/', $value)) {
        $errors[] = $field . ':invalid_decimal';
        return $default;
    }
    return $value;
}

function drilling_import_optional_int($row, $field, &$errors)
{
    $value = trim((string)$row[$field]);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^-?\d{1,10}$/', $value)) {
        $errors[] = $field . ':invalid_integer';
        return null;
    }
    return (int)$value;
}

function drilling_import_split_people($value)
{
    $people = [];
    foreach (preg_split('/[,،\r\n]+/u', (string)$value) as $name) {
        $name = drilling_import_normalize_text($name);
        if ($name !== '') {
            $people[] = $name;
        }
    }
    return $people;
}

function drilling_import_checklist_numbers($row)
{
    $checked = [];
    foreach (['check_1_6_checkgroup', 'check_7_14_checkgroup'] as $field) {
        foreach (preg_split('/[,\r\n]+/u', (string)$row[$field]) as $part) {
            $part = drilling_import_normalize_text($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/گریس.*اسپیند|اسپیند.*گریس/u', $part)) {
                $checked[12] = true;
                continue;
            }
            if (preg_match('/گریس.*پمپ گل|پمپ گل.*گریس/u', $part)) {
                $checked[13] = true;
                continue;
            }
            if (preg_match('/یاتاقان.*وایرلاین/u', $part)) {
                $checked[14] = true;
                continue;
            }
            if (preg_match('/^(\d{1,2})(?:\s|\-|$)/u', $part, $match)) {
                $number = (int)$match[1];
                if ($number >= 1 && $number <= 14) {
                    $checked[$number] = true;
                }
            }
        }
    }
    return $checked;
}

function drilling_import_upsert_stage($db, $batchId, $legacyId, $row, $mineName, $boreholeCode, $status, $messages, $reportId = null)
{
    $stmt = $db->prepare(
        "INSERT INTO emcore_drilling_legacy_import_rows
            (batch_id, source_legacy_id, source_data, normalized_mine_name,
             normalized_borehole_code, import_status, validation_messages, report_id)
         VALUES
            (:batch_id, :source_legacy_id, :source_data, :normalized_mine_name,
             :normalized_borehole_code, :import_status, :validation_messages, :report_id)
         ON DUPLICATE KEY UPDATE
            batch_id = VALUES(batch_id), source_data = VALUES(source_data),
            normalized_mine_name = VALUES(normalized_mine_name),
            normalized_borehole_code = VALUES(normalized_borehole_code),
            import_status = VALUES(import_status),
            validation_messages = VALUES(validation_messages),
            report_id = COALESCE(VALUES(report_id), report_id), updated_at = NOW()"
    );
    $stmt->execute([
        ':batch_id' => $batchId,
        ':source_legacy_id' => $legacyId,
        ':source_data' => drilling_import_json($row),
        ':normalized_mine_name' => $mineName === '' ? null : $mineName,
        ':normalized_borehole_code' => $boreholeCode === '' ? null : $boreholeCode,
        ':import_status' => $status,
        ':validation_messages' => $messages ? drilling_import_json($messages) : null,
        ':report_id' => $reportId,
    ]);
}

$sourceTable = (string)drilling_import_option('--source-table');
$csvPath = isset($argv[1]) && strpos($argv[1], '--') !== 0 ? $argv[1] : '';
if ($sourceTable !== '' && $sourceTable !== 'prc_db_gozaresh_ruzane_copy2') {
    fwrite(STDERR, "Only --source-table=prc_db_gozaresh_ruzane_copy2 is allowed.\n");
    exit(2);
}
if ($sourceTable !== '' && $csvPath !== '') {
    fwrite(STDERR, "Choose either a CSV path or --source-table, not both.\n");
    exit(2);
}
if ($sourceTable === '' && ($csvPath === '' || !is_file($csvPath) || !is_readable($csvPath))) {
    fwrite(STDERR, "Provide a readable legacy CSV path or --source-table=prc_db_gozaresh_ruzane_copy2.\n");
    exit(2);
}
$sourceType = $sourceTable !== '' ? 'table' : 'csv';
$sourceName = $sourceTable !== '' ? $sourceTable : basename($csvPath);

$commit = drilling_import_has_flag('--commit');
$createBoreholes = drilling_import_has_flag('--create-boreholes');
$actorUsrUid = (string)drilling_import_option('--actor-usr-uid');
if ($commit && !preg_match('/^[A-Za-z0-9]{32}$/', $actorUsrUid)) {
    fwrite(STDERR, "--actor-usr-uid=<32-character ProcessMaker USR_UID> is required with --commit.\n");
    exit(2);
}

$localFile = dirname(__DIR__) . '/emcore_api/emcore_config.php';
$local = is_file($localFile) ? require $localFile : [];
if (!is_array($local)) {
    throw new RuntimeException('Invalid EMCORE local configuration.');
}
$dsn = getenv('EMCORE_DB_DSN') ?: (isset($local['db_dsn']) ? $local['db_dsn'] : '');
$dbUser = getenv('EMCORE_DB_USER') ?: (isset($local['db_user']) ? $local['db_user'] : '');
$dbPassword = getenv('EMCORE_DB_PASSWORD') !== false
    ? getenv('EMCORE_DB_PASSWORD')
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
           AND p.module_key = 'drilling_daily_reports' AND p.can_create = 1
         LIMIT 1"
    );
    $actor->execute([':usr_uid' => $actorUsrUid]);
    if (!$actor->fetch()) {
        throw new RuntimeException('The import actor is not active or lacks drilling create permission.');
    }
}

$mines = [];
foreach ($db->query('SELECT id, mine_name, alias_name FROM emcore_mines WHERE deleted_at IS NULL')->fetchAll() as $mine) {
    $mines[drilling_import_normalize_text($mine['mine_name'])] = (int)$mine['id'];
    if (!empty($mine['alias_name'])) {
        $mines[drilling_import_normalize_text($mine['alias_name'])] = (int)$mine['id'];
    }
}
$legacyProjectNames = [
    '1' => 'راه چمن',
    '2' => 'تپه سیاه',
    '3' => 'میامی',
    '4' => 'زبرکوه',
    '5' => 'تنگل',
    '6' => 'کلاته برق',
];
$rigs = [];
foreach ($db->query('SELECT id, serial_number FROM emcore_drilling_rigs WHERE deleted_at IS NULL')->fetchAll() as $rig) {
    $rigs[drilling_import_normalize_text($rig['serial_number'])] = (int)$rig['id'];
}
$boreholes = [];
foreach ($db->query('SELECT id, mine_id, borehole_code FROM emcore_boreholes WHERE deleted_at IS NULL')->fetchAll() as $borehole) {
    $boreholes[$borehole['mine_id'] . ':' . drilling_import_normalize_text($borehole['borehole_code'])] = (int)$borehole['id'];
}

$expectedHeaders = [
    'id', 'Projects', 'form_serial_number_str', 'Gamane_name', 'dastgah_name',
    'shift', 'form_date', 'dastgah_saat', 'start_our_str', 'end_our_str',
    'drill_start_flt', 'drill_end_flt', 'drill_amount', 'corebox_start_int',
    'corebox_end_int', 'corebox_amount', 'water_flt', 'gaso_flt', 'oil_flt',
    'supermix_flt', 'bentonite_flt', 'sarparast', 'negahban', 'zaminshenas',
    'driver', 'sar_haffar', 'haffar', 'kargar', 'komak_haffar', 'aux_kargar',
    'aux_komak_haffar', 'list_vorudi', 'list_khruji', 'text_checkbox_tozihat',
    'text_sharh_haffari', 'check_1_6_checkgroup', 'check_7_14_checkgroup',
    'insert_date', 'soda_flt', 'cement_flt', 'is_stopped', 'stop_causes',
    'stop_time', 'pack_lv', 'iradat',
];
if ($sourceType === 'table') {
    $columnSql = '`' . implode('`,`', $expectedHeaders) . '`';
    $statement = $db->query(
        'SELECT ' . $columnSql . ' FROM `prc_db_gozaresh_ruzane_copy2` ORDER BY `id`'
    );
    $source = ['type' => 'table', 'statement' => $statement];
    $headers = $expectedHeaders;
} else {
    $csvSource = drilling_import_open_csv($csvPath);
    $headers = drilling_import_read_csv_row($csvSource);
    if (!$headers) {
        throw new RuntimeException('Legacy CSV is empty.');
    }
    if ($headers !== $expectedHeaders) {
        if (count($headers) !== count($expectedHeaders)) {
            throw new RuntimeException(
                'Legacy CSV header mismatch: expected ' . count($expectedHeaders)
                . ' columns, parsed ' . count($headers) . '.'
            );
        }
        foreach ($expectedHeaders as $index => $expectedHeader) {
            if ($headers[$index] !== $expectedHeader) {
                throw new RuntimeException(
                    'Legacy CSV header mismatch at column ' . ($index + 1)
                    . ': expected ' . $expectedHeader . ', parsed ' . $headers[$index] . '.'
                );
            }
        }
    }
    $source = ['type' => 'csv', 'csv' => $csvSource];
}

$batchId = bin2hex(random_bytes(16));
$stats = [
    'source_rows' => 0,
    'ready' => 0,
    'imported' => 0,
    'already_imported' => 0,
    'needs_review' => 0,
    'boreholes_created' => 0,
    'csv_rows_repaired' => 0,
    'parse_errors' => 0,
];
$reviewSamples = [];
$roleColumns = [
    'sarparast' => 'supervisor',
    'negahban' => 'guard',
    'zaminshenas' => 'geologist',
    'driver' => 'driver',
    'sar_haffar' => 'head_driller',
    'haffar' => 'driller',
    'kargar' => 'worker',
    'komak_haffar' => 'assistant_driller',
    'aux_kargar' => 'additional_worker',
    'aux_komak_haffar' => 'additional_assistant',
];
$checklistKeyByNumber = [
    1 => 'site_safe', 2 => 'rods_supported', 3 => 'electrical_checked',
    4 => 'hydraulic_checked', 5 => 'spindle_oil_checked',
    6 => 'hydraulic_oil_checked', 7 => 'engine_oil_checked',
    8 => 'engine_water_checked', 9 => 'fuel_checked',
    10 => 'rig_level_checked', 11 => 'wireline_checked',
    12 => 'spindle_greased', 13 => 'mud_pump_greased',
    14 => 'wireline_bearing_greased',
];

while (($values = drilling_import_read_source_row($source)) !== false) {
    if (count($values) === 1 && trim((string)$values[0]) === '') {
        continue;
    }
    $stats['source_rows']++;
    $parsedColumnCount = count($values);
    $columnsRepaired = false;
    $values = drilling_import_repair_columns($values, $columnsRepaired);
    if ($columnsRepaired) {
        $stats['csv_rows_repaired']++;
    }
    if (count($values) !== count($headers)) {
        $stats['parse_errors']++;
        if (count($reviewSamples) < 20) {
            $reviewSamples[] = [
                'row' => $stats['source_rows'],
                'errors' => ['column_count_mismatch:' . $parsedColumnCount],
            ];
        }
        continue;
    }
    $row = array_combine($headers, $values);
    foreach ($row as $column => $value) {
        $row[$column] = drilling_import_repair_mojibake($value);
    }
    $errors = [];
    $legacyId = preg_match('/^[1-9][0-9]*$/', trim((string)$row['id'])) ? (int)$row['id'] : null;
    if ($legacyId === null) {
        $errors[] = 'id:invalid';
    }
    $projectValue = drilling_import_normalize_text($row['Projects']);
    $mineName = isset($legacyProjectNames[$projectValue])
        ? $legacyProjectNames[$projectValue]
        : $projectValue;
    $mineId = isset($mines[$mineName]) ? $mines[$mineName] : null;
    if ($mineId === null) {
        $errors[] = 'mine:not_mapped';
    }
    $boreholeCode = drilling_import_normalize_text($row['Gamane_name']);
    if ($boreholeCode === '') {
        $errors[] = 'borehole:missing';
    }
    $rigSerial = drilling_import_normalize_text($row['dastgah_name']);
    $rigId = isset($rigs[$rigSerial]) ? $rigs[$rigSerial] : null;
    if ($rigId === null) {
        $errors[] = $rigSerial === '' ? 'rig:missing' : 'rig:not_mapped';
    }
    $shift = strtoupper(trim((string)$row['shift']));
    if (!in_array($shift, ['DAY', 'NIGHT'], true)) {
        $errors[] = 'shift:invalid';
    }
    $reportDateFa = str_replace('-', '/', trim((string)$row['form_date']));
    if (!preg_match('/^1[34][0-9]{2}\/(0[1-9]|1[0-2])\/([0-2][0-9]|3[01])$/', $reportDateFa)) {
        $errors[] = 'report_date:invalid';
    }

    $boreholeId = null;
    $boreholeMapKey = null;
    $createBorehole = false;
    if ($mineId !== null && $boreholeCode !== '') {
        $boreholeMapKey = $mineId . ':' . $boreholeCode;
        if (isset($boreholes[$boreholeMapKey])) {
            $boreholeId = $boreholes[$boreholeMapKey];
        } elseif ($createBoreholes) {
            // Defer the real insert until the report transaction. Dry runs use
            // a sentinel only after the row has passed every validation rule.
            $boreholeId = -1;
            $createBorehole = true;
        } else {
            $errors[] = 'borehole:not_mapped';
        }
    }

    $drillStart = drilling_import_decimal($row, 'drill_start_flt', $errors);
    $drillEnd = drilling_import_decimal($row, 'drill_end_flt', $errors);
    $drillAmount = drilling_import_decimal($row, 'drill_amount', $errors);
    $coreboxStart = drilling_import_optional_int($row, 'corebox_start_int', $errors);
    $coreboxEnd = drilling_import_optional_int($row, 'corebox_end_int', $errors);
    $waterAmount = drilling_import_decimal($row, 'water_flt', $errors);
    $dieselAmount = drilling_import_decimal($row, 'gaso_flt', $errors);
    $oilAmount = drilling_import_decimal($row, 'oil_flt', $errors);
    $supermixAmount = drilling_import_decimal($row, 'supermix_flt', $errors);
    $bentoniteAmount = drilling_import_decimal($row, 'bentonite_flt', $errors);
    $sodaAmount = drilling_import_decimal($row, 'soda_flt', $errors);
    $cementAmount = drilling_import_decimal($row, 'cement_flt', $errors);
    $legacyInsertedAt = null;
    $insertDate = DateTime::createFromFormat('j/n/Y H:i:s', trim((string)$row['insert_date']));
    if ($insertDate) {
        $legacyInsertedAt = $insertDate->format('Y-m-d H:i:s');
    } else {
        $errors[] = 'insert_date:invalid';
    }

    if ($errors) {
        $stats['needs_review']++;
        if (count($reviewSamples) < 20) {
            $reviewSamples[] = ['legacy_id' => $legacyId, 'errors' => $errors];
        }
        if ($commit && $legacyId !== null) {
            drilling_import_upsert_stage(
                $db, $batchId, $legacyId, $row, $mineName, $boreholeCode, 'needs_review', $errors
            );
        }
        continue;
    }
    if (!$commit && $createBorehole) {
        $boreholes[$boreholeMapKey] = -1;
        $stats['boreholes_created']++;
    }
    $stats['ready']++;
    if (!$commit) {
        continue;
    }

    $existing = $db->prepare('SELECT id FROM emcore_drilling_reports WHERE legacy_id = :legacy_id LIMIT 1');
    $existing->execute([':legacy_id' => $legacyId]);
    $existingReportId = $existing->fetchColumn();
    if ($existingReportId) {
        drilling_import_upsert_stage(
            $db, $batchId, $legacyId, $row, $mineName, $boreholeCode,
            'imported', ['already_imported'], (int)$existingReportId
        );
        $stats['already_imported']++;
        continue;
    }

    $stopTime = trim((string)$row['stop_time']);
    $isStopped = strtolower(trim((string)$row['is_stopped'])) === 'true';
    if ($stopTime === '99') {
        $operationState = 'no_drilling';
        $stopDuration = null;
    } elseif ($isStopped) {
        $operationState = 'partially_stopped';
        $stopDuration = preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $stopTime) && (float)$stopTime <= 12
            ? $stopTime : null;
    } else {
        $operationState = 'drilling';
        $stopDuration = null;
    }

    $boreholeWasCreated = false;
    $db->beginTransaction();
    try {
        if ($createBorehole) {
            $create = $db->prepare(
                "INSERT INTO emcore_boreholes (mine_id, borehole_code, status, notes)
                 VALUES (:mine_id, :borehole_code, 'active', 'ایجادشده هنگام مهاجرت گزارش‌های قدیمی')"
            );
            $create->execute([':mine_id' => $mineId, ':borehole_code' => $boreholeCode]);
            $boreholeId = (int)$db->lastInsertId();
            $boreholeWasCreated = true;

            $boreholeAudit = $db->prepare(
                "INSERT INTO emcore_audit_log
                    (request_id, actor_usr_uid, module_key, action, entity_type, entity_id,
                     after_data, metadata, created_at)
                 VALUES
                    (:request_id, :actor_usr_uid, 'drilling_daily_reports', 'create',
                     'borehole', :entity_id, :after_data, :metadata, NOW())"
            );
            $boreholeAudit->execute([
                ':request_id' => $batchId,
                ':actor_usr_uid' => $actorUsrUid,
                ':entity_id' => (string)$boreholeId,
                ':after_data' => drilling_import_json([
                    'id' => $boreholeId,
                    'mine_id' => $mineId,
                    'borehole_code' => $boreholeCode,
                ]),
                ':metadata' => drilling_import_json([
                    'source' => $sourceName,
                    'batch_id' => $batchId,
                    'legacy_id' => $legacyId,
                ]),
            ]);
        }

        $convert = $db->prepare('SELECT shamsi_slash_to_gregorian_date(:date_fa)');
        $convert->execute([':date_fa' => $reportDateFa]);
        $reportDateEn = $convert->fetchColumn();
        if (!$reportDateEn) {
            throw new RuntimeException('Unable to convert legacy Jalali date for ID ' . $legacyId);
        }

        $stmt = $db->prepare(
            "INSERT INTO emcore_drilling_reports
                (legacy_id, legacy_form_serial, borehole_id, rig_id, report_date_fa,
                 report_date_en, shift, start_time, end_time, rig_hours,
                 drill_start_depth, drill_end_depth, drill_amount, corebox_start,
                 corebox_end, water_amount, diesel_amount, oil_amount, supermix_amount,
                 bentonite_amount, soda_amount, cement_amount, lv_pack, operation_state,
                 stop_causes, stop_duration_hours, incoming_equipment, outgoing_equipment,
                 checklist_notes, operation_description, issues_suggestions,
                 legacy_inserted_at, legacy_source_data, created_by_usr_uid,
                 created_at, updated_at)
             VALUES
                (:legacy_id, :legacy_form_serial, :borehole_id, :rig_id, :report_date_fa,
                 :report_date_en, :shift, :start_time, :end_time, :rig_hours,
                 :drill_start_depth, :drill_end_depth, :drill_amount, :corebox_start,
                 :corebox_end, :water_amount, :diesel_amount, :oil_amount, :supermix_amount,
                 :bentonite_amount, :soda_amount, :cement_amount, :lv_pack, :operation_state,
                 :stop_causes, :stop_duration_hours, :incoming_equipment, :outgoing_equipment,
                 :checklist_notes, :operation_description, :issues_suggestions,
                 :legacy_inserted_at, :legacy_source_data, :created_by_usr_uid,
                 :created_at, :updated_at)"
        );
        $stmt->execute([
            ':legacy_id' => $legacyId,
            ':legacy_form_serial' => trim((string)$row['form_serial_number_str']) ?: null,
            ':borehole_id' => $boreholeId,
            ':rig_id' => $rigId,
            ':report_date_fa' => $reportDateFa,
            ':report_date_en' => $reportDateEn,
            ':shift' => $shift,
            ':start_time' => trim((string)$row['start_our_str']) ?: null,
            ':end_time' => trim((string)$row['end_our_str']) ?: null,
            ':rig_hours' => trim((string)$row['dastgah_saat']) ?: null,
            ':drill_start_depth' => $drillStart,
            ':drill_end_depth' => $drillEnd,
            ':drill_amount' => $drillAmount,
            ':corebox_start' => $coreboxStart,
            ':corebox_end' => $coreboxEnd,
            ':water_amount' => $waterAmount,
            ':diesel_amount' => $dieselAmount,
            ':oil_amount' => $oilAmount,
            ':supermix_amount' => $supermixAmount,
            ':bentonite_amount' => $bentoniteAmount,
            ':soda_amount' => $sodaAmount,
            ':cement_amount' => $cementAmount,
            ':lv_pack' => trim((string)$row['pack_lv']) ?: null,
            ':operation_state' => $operationState,
            ':stop_causes' => trim((string)$row['stop_causes']) ?: null,
            ':stop_duration_hours' => $stopDuration,
            ':incoming_equipment' => trim((string)$row['list_vorudi']) ?: null,
            ':outgoing_equipment' => trim((string)$row['list_khruji']) ?: null,
            ':checklist_notes' => trim((string)$row['text_checkbox_tozihat']) ?: null,
            ':operation_description' => trim((string)$row['text_sharh_haffari']) ?: null,
            ':issues_suggestions' => trim((string)$row['iradat']) ?: null,
            ':legacy_inserted_at' => $legacyInsertedAt,
            ':legacy_source_data' => drilling_import_json($row),
            ':created_by_usr_uid' => $actorUsrUid,
            ':created_at' => $legacyInsertedAt,
            ':updated_at' => $legacyInsertedAt,
        ]);
        $reportId = (int)$db->lastInsertId();
        $reportNumber = 'DR-L-' . str_pad((string)$legacyId, 8, '0', STR_PAD_LEFT);
        $db->prepare('UPDATE emcore_drilling_reports SET report_number = :number WHERE id = :id')
            ->execute([':number' => $reportNumber, ':id' => $reportId]);

        $crewInsert = $db->prepare(
            "INSERT INTO emcore_drilling_report_crew
                (report_id, role_key, person_id, worker_name_snapshot, worker_type, sort_order)
             VALUES (:report_id, :role_key, NULL, :worker_name_snapshot, 'temporary', :sort_order)"
        );
        $sortOrder = 0;
        foreach ($roleColumns as $column => $roleKey) {
            foreach (drilling_import_split_people($row[$column]) as $workerName) {
                $sortOrder++;
                $crewInsert->execute([
                    ':report_id' => $reportId,
                    ':role_key' => $roleKey,
                    ':worker_name_snapshot' => $workerName,
                    ':sort_order' => $sortOrder,
                ]);
            }
        }

        $checkedNumbers = drilling_import_checklist_numbers($row);
        $checkInsert = $db->prepare(
            'INSERT INTO emcore_drilling_report_checklist (report_id, item_key, is_checked)
             VALUES (:report_id, :item_key, :is_checked)'
        );
        foreach ($checklistKeyByNumber as $number => $itemKey) {
            $checkInsert->execute([
                ':report_id' => $reportId,
                ':item_key' => $itemKey,
                ':is_checked' => isset($checkedNumbers[$number]) ? 1 : 0,
            ]);
        }

        drilling_import_upsert_stage(
            $db, $batchId, $legacyId, $row, $mineName, $boreholeCode,
            'imported', [], $reportId
        );
        $audit = $db->prepare(
            "INSERT INTO emcore_audit_log
                (request_id, actor_usr_uid, module_key, action, entity_type, entity_id,
                 after_data, metadata, created_at)
             VALUES
                (:request_id, :actor_usr_uid, 'drilling_daily_reports', 'create',
                 'drilling_report', :entity_id, :after_data, :metadata, NOW())"
        );
        $audit->execute([
            ':request_id' => $batchId,
            ':actor_usr_uid' => $actorUsrUid,
            ':entity_id' => (string)$reportId,
            ':after_data' => drilling_import_json([
                'id' => $reportId,
                'legacy_id' => $legacyId,
                'report_number' => $reportNumber,
            ]),
            ':metadata' => drilling_import_json(['source' => $sourceName, 'batch_id' => $batchId]),
        ]);
        $db->commit();
        if ($boreholeWasCreated) {
            $boreholes[$boreholeMapKey] = $boreholeId;
            $stats['boreholes_created']++;
        }
        $stats['imported']++;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $stats['needs_review']++;
        $message = ['database:' . $exception->getMessage()];
        drilling_import_upsert_stage(
            $db, $batchId, $legacyId, $row, $mineName, $boreholeCode, 'needs_review', $message
        );
        if (count($reviewSamples) < 20) {
            $reviewSamples[] = ['legacy_id' => $legacyId, 'errors' => $message];
        }
    }
}
if ($sourceType === 'csv') {
    fclose($source['csv']['handle']);
}

$result = [
    'mode' => $commit ? 'commit' : 'dry-run',
    'source_type' => $sourceType,
    'source' => $sourceName,
    'batch_id' => $batchId,
    'create_boreholes' => $createBoreholes,
    'stats' => $stats,
    'review_samples' => $reviewSamples,
];
echo drilling_import_json($result) . PHP_EOL;
exit($stats['parse_errors'] > 0 ? 1 : 0);
