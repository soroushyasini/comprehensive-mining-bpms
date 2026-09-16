<?php

require_once __DIR__ . '/_module_permissions.php';

const EMCORE_DRILLING_MODULE = 'drilling_daily_reports';

function emcore_drilling_analytics_filter_id($name)
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

function emcore_drilling_analytics_date($db, $name)
{
    $value = emcore_string($name, false, 10);
    if ($value === null) {
        return null;
    }
    if (!preg_match('/^1[34][0-9]{2}\/(0[1-9]|1[0-2])\/([0-2][0-9]|3[01])$/', $value)) {
        throw new EmcoreHttpException(422, 'تاریخ فیلتر نامعتبر است', [$name => 'invalid_jalali_date']);
    }
    $stmt = $db->prepare('SELECT shamsi_slash_to_gregorian_date(:date_fa)');
    $stmt->execute([':date_fa' => $value]);
    $gregorian = $stmt->fetchColumn();
    if (!$gregorian) {
        throw new EmcoreHttpException(422, 'تاریخ شمسی معتبر نیست', [$name => 'invalid_jalali_date']);
    }
    return ['fa' => $value, 'en' => $gregorian];
}

function emcore_drilling_analytics_query($db, $sql, $params)
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function emcore_drilling_attendance_period_part($name, $pattern, $error)
{
    $value = emcore_string($name, true, 4);
    if (!preg_match($pattern, $value)) {
        throw new EmcoreHttpException(422, $error, [$name => 'invalid_period']);
    }
    return $value;
}

function emcore_drilling_attendance_empty_shift()
{
    return [
        'present' => false,
        'report_count' => 0,
        'drilled_meters' => 0.0,
        'actual_worked_hours' => 0.0,
        'missing_worked_hours' => 0,
    ];
}

function emcore_drilling_attendance_empty_total()
{
    return [
        'day_presence' => 0,
        'night_presence' => 0,
        'day_drilled_meters' => 0.0,
        'night_drilled_meters' => 0.0,
        'day_actual_worked_hours' => 0.0,
        'night_actual_worked_hours' => 0.0,
        'day_missing_worked_hours' => 0,
        'night_missing_worked_hours' => 0,
    ];
}

function emcore_drilling_attendance_matrix($db)
{
    $mineId = emcore_drilling_analytics_filter_id('mine_id');
    $boreholeId = emcore_drilling_analytics_filter_id('borehole_id');
    $year = emcore_drilling_attendance_period_part(
        'year_fa',
        '/^1[34][0-9]{2}$/',
        'سال شمسی نامعتبر است'
    );
    $month = emcore_drilling_attendance_period_part(
        'month_fa',
        '/^(0[1-9]|1[0-2])$/',
        'ماه شمسی نامعتبر است'
    );

    $where = ['r.deleted_at IS NULL', 'r.report_date_fa LIKE :attendance_period'];
    $params = [':attendance_period' => $year . '/' . $month . '/%'];
    if ($mineId !== null) {
        $where[] = 'b.mine_id = :mine_id';
        $params[':mine_id'] = $mineId;
    }
    if ($boreholeId !== null) {
        $where[] = 'r.borehole_id = :borehole_id';
        $params[':borehole_id'] = $boreholeId;
    }
    $whereSql = implode(' AND ', $where);

    $siteSql = "SELECT b.mine_id, m.mine_name, COUNT(*) AS report_count,
            SUM(CASE WHEN COALESCE(ch.crew_entries, 0) = 0 THEN 1 ELSE 0 END) AS reports_without_crew,
            SUM(CASE WHEN r.shift NOT IN ('DAY', 'NIGHT') THEN 1 ELSE 0 END) AS reports_with_invalid_shift
        FROM emcore_drilling_reports r
        JOIN emcore_boreholes b ON b.id = r.borehole_id
        JOIN emcore_mines m ON m.id = b.mine_id
        LEFT JOIN (
            SELECT report_id, COUNT(*) AS crew_entries
            FROM emcore_drilling_report_crew
            GROUP BY report_id
        ) ch ON ch.report_id = r.id
        WHERE {$whereSql}
        GROUP BY b.mine_id, m.mine_name
        ORDER BY m.mine_name, b.mine_id";
    $siteRows = emcore_drilling_analytics_query($db, $siteSql, $params)->fetchAll();

    $personKeySql = "CASE WHEN c.person_id IS NOT NULL THEN CONCAT('p:', c.person_id)
        ELSE CONCAT('t:', LOWER(REGEXP_REPLACE(TRIM(c.worker_name_snapshot), '[[:space:]]+', ' '))) END";
    $crewSql = "SELECT r.id AS report_id, r.report_date_fa, r.report_date_en, r.shift,
            r.drill_amount, b.mine_id, m.mine_name,
            {$personKeySql} AS person_key,
            MAX(CASE WHEN c.person_id IS NOT NULL
                THEN COALESCE(NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), ''), TRIM(c.worker_name_snapshot))
                ELSE TRIM(c.worker_name_snapshot) END) AS person_name,
            GROUP_CONCAT(DISTINCT c.role_key ORDER BY c.role_key SEPARATOR ',') AS role_keys,
            MAX(c.worked_hours) AS actual_worked_hours,
            CASE WHEN MAX(c.worked_hours) IS NULL THEN 1 ELSE 0 END AS missing_worked_hours,
            GREATEST(COUNT(*) - 1, 0) AS duplicate_assignments
        FROM emcore_drilling_report_crew c
        JOIN emcore_drilling_reports r ON r.id = c.report_id
        JOIN emcore_boreholes b ON b.id = r.borehole_id
        JOIN emcore_mines m ON m.id = b.mine_id
        LEFT JOIN emcore_persons p ON p.id = c.person_id
        WHERE {$whereSql}
        GROUP BY r.id, r.report_date_fa, r.report_date_en, r.shift, r.drill_amount,
            b.mine_id, m.mine_name, {$personKeySql}
        ORDER BY m.mine_name, b.mine_id, r.report_date_en, r.shift, person_name, person_key";
    $crewRows = emcore_drilling_analytics_query($db, $crewSql, $params)->fetchAll();

    $sites = [];
    foreach ($siteRows as $siteRow) {
        $siteKey = (string)$siteRow['mine_id'];
        $sites[$siteKey] = [
            'mine_id' => (int)$siteRow['mine_id'],
            'mine_name' => $siteRow['mine_name'],
            'report_count' => (int)$siteRow['report_count'],
            'people' => [],
            'dates' => [],
            'quality' => [
                'reports_without_crew' => (int)$siteRow['reports_without_crew'],
                'reports_with_invalid_shift' => (int)$siteRow['reports_with_invalid_shift'],
                'duplicate_assignments' => 0,
                'missing_worked_hours' => 0,
                'hours_over_12' => 0,
            ],
        ];
    }

    foreach ($crewRows as $row) {
        $siteKey = (string)$row['mine_id'];
        if (!isset($sites[$siteKey])) {
            continue;
        }
        $personKey = (string)$row['person_key'];
        $dateKey = (string)$row['report_date_fa'];
        if ($row['shift'] === 'DAY') {
            $shiftKey = 'day';
        } elseif ($row['shift'] === 'NIGHT') {
            $shiftKey = 'night';
        } else {
            continue;
        }
        if (!isset($sites[$siteKey]['people'][$personKey])) {
            $sites[$siteKey]['people'][$personKey] = [
                'person_key' => $personKey,
                'person_name' => $row['person_name'],
                'role_keys' => [],
                'total' => emcore_drilling_attendance_empty_total(),
            ];
        }
        foreach (explode(',', (string)$row['role_keys']) as $roleKey) {
            if ($roleKey !== '') {
                $sites[$siteKey]['people'][$personKey]['role_keys'][$roleKey] = true;
            }
        }
        if (!isset($sites[$siteKey]['dates'][$dateKey])) {
            $sites[$siteKey]['dates'][$dateKey] = [
                'report_date_fa' => $dateKey,
                'report_date_en' => $row['report_date_en'],
                'cells' => [],
            ];
        }
        if (!isset($sites[$siteKey]['dates'][$dateKey]['cells'][$personKey])) {
            $sites[$siteKey]['dates'][$dateKey]['cells'][$personKey] = [
                'person_key' => $personKey,
                'day' => emcore_drilling_attendance_empty_shift(),
                'night' => emcore_drilling_attendance_empty_shift(),
            ];
        }

        $cell =& $sites[$siteKey]['dates'][$dateKey]['cells'][$personKey][$shiftKey];
        $total =& $sites[$siteKey]['people'][$personKey]['total'];
        if (!$cell['present']) {
            $cell['present'] = true;
            $total[$shiftKey . '_presence'] += 1;
        }
        $drilledMeters = (float)$row['drill_amount'];
        $cell['report_count'] += 1;
        $cell['drilled_meters'] += $drilledMeters;
        $total[$shiftKey . '_drilled_meters'] += $drilledMeters;
        if ($row['actual_worked_hours'] !== null) {
            $workedHours = (float)$row['actual_worked_hours'];
            $cell['actual_worked_hours'] += $workedHours;
            $total[$shiftKey . '_actual_worked_hours'] += $workedHours;
        }
        $missingWorkedHours = (int)$row['missing_worked_hours'];
        $cell['missing_worked_hours'] += $missingWorkedHours;
        $total[$shiftKey . '_missing_worked_hours'] += $missingWorkedHours;
        $sites[$siteKey]['quality']['missing_worked_hours'] += $missingWorkedHours;
        $sites[$siteKey]['quality']['duplicate_assignments'] += (int)$row['duplicate_assignments'];
        unset($cell, $total);
    }

    foreach ($sites as &$site) {
        uasort($site['people'], function ($left, $right) {
            $nameCompare = strnatcasecmp((string)$left['person_name'], (string)$right['person_name']);
            return $nameCompare !== 0 ? $nameCompare : strcmp($left['person_key'], $right['person_key']);
        });
        foreach ($site['people'] as &$person) {
            $roles = array_keys($person['role_keys']);
            sort($roles, SORT_STRING);
            $person['role_keys'] = $roles;
        }
        unset($person);
        ksort($site['dates'], SORT_STRING);
        foreach ($site['dates'] as &$date) {
            foreach ($date['cells'] as $cell) {
                foreach (['day', 'night'] as $shiftKey) {
                    if ($cell[$shiftKey]['actual_worked_hours'] > 12.0) {
                        $site['quality']['hours_over_12'] += 1;
                    }
                }
            }
            $date['cells'] = array_values($date['cells']);
        }
        unset($date);
        $site['people'] = array_values($site['people']);
        $site['dates'] = array_values($site['dates']);
    }
    unset($site);

    $siteList = array_values($sites);
    $quality = [
        'reports_without_crew' => 0,
        'reports_with_invalid_shift' => 0,
        'duplicate_assignments' => 0,
        'missing_worked_hours' => 0,
        'hours_over_12' => 0,
    ];
    foreach ($siteList as $site) {
        foreach ($quality as $key => $unused) {
            $quality[$key] += $site['quality'][$key];
        }
    }

    emcore_json([
        'success' => true,
        'data' => ['sites' => $siteList],
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'filters' => [
                'mine_id' => $mineId,
                'borehole_id' => $boreholeId,
                'year_fa' => $year,
                'month_fa' => $month,
            ],
            'quality' => $quality,
            'semantics' => [
                'presence' => 'one_presence_per_person_mine_date_shift',
                'attended_shift_production' => 'sum_reported_drill_amount_for_reports_assigned_to_person_once_per_report',
                'crew_hours' => 'sum_actual_non_null_only',
                'missing_crew_hours' => 'reported_separately_not_imputed',
                'temporary_person_identity' => 'normalized_worker_name_snapshot',
            ],
        ],
    ]);
}

$action = emcore_action(['dashboard', 'attendance_lookups', 'attendance_matrix']);
emcore_require_permission(EMCORE_DRILLING_MODULE, 'read');
$db = emcore_db();

if ($action === 'attendance_lookups') {
    $periods = $db->query(
        "SELECT LEFT(report_date_fa, 4) AS year_fa, SUBSTRING(report_date_fa, 6, 2) AS month_fa,
                COUNT(*) AS report_count
         FROM emcore_drilling_reports
         WHERE deleted_at IS NULL
           AND report_date_fa REGEXP '^1[34][0-9]{2}/(0[1-9]|1[0-2])/[0-3][0-9]$'
         GROUP BY LEFT(report_date_fa, 4), SUBSTRING(report_date_fa, 6, 2)
         ORDER BY year_fa DESC, month_fa DESC"
    )->fetchAll();
    emcore_json([
        'success' => true,
        'data' => ['periods' => $periods],
        'meta' => ['generated_at' => date(DATE_ATOM)],
    ]);
}

if ($action === 'attendance_matrix') {
    emcore_drilling_attendance_matrix($db);
}

$where = ['r.deleted_at IS NULL'];
$params = [];
$mineId = emcore_drilling_analytics_filter_id('mine_id');
$boreholeId = emcore_drilling_analytics_filter_id('borehole_id');
$rigId = emcore_drilling_analytics_filter_id('rig_id');
$shift = emcore_string('shift', false, 10);
$dateFrom = emcore_drilling_analytics_date($db, 'date_from_fa');
$dateTo = emcore_drilling_analytics_date($db, 'date_to_fa');

if ($shift !== null && !in_array($shift, ['DAY', 'NIGHT'], true)) {
    throw new EmcoreHttpException(422, 'شیفت نامعتبر است', ['shift' => 'invalid_choice']);
}
if ($dateFrom !== null && $dateTo !== null && $dateFrom['en'] > $dateTo['en']) {
    throw new EmcoreHttpException(422, 'تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد');
}
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
if ($shift !== null) {
    $where[] = 'r.shift = :shift';
    $params[':shift'] = $shift;
}
if ($dateFrom !== null) {
    $where[] = 'r.report_date_en >= :date_from_en';
    $params[':date_from_en'] = $dateFrom['en'];
}
if ($dateTo !== null) {
    $where[] = 'r.report_date_en <= :date_to_en';
    $params[':date_to_en'] = $dateTo['en'];
}
$whereSql = implode(' AND ', $where);
$crewAggregate = "LEFT JOIN (
    SELECT c.report_id,
           COUNT(*) AS crew_entries,
           COUNT(c.worked_hours) AS known_worked_hours,
           SUM(c.worked_hours) AS actual_worked_hours,
           SUM(CASE WHEN c.worked_hours IS NULL THEN 1 ELSE 0 END) AS missing_worked_hours
    FROM emcore_drilling_report_crew c
    GROUP BY c.report_id
) ch ON ch.report_id = r.id";
$baseJoins = "FROM emcore_drilling_reports r
    JOIN emcore_boreholes b ON b.id = r.borehole_id
    JOIN emcore_mines m ON m.id = b.mine_id
    JOIN emcore_drilling_rigs g ON g.id = r.rig_id
    {$crewAggregate}";

$summarySql = "SELECT COUNT(*) AS report_count,
        COUNT(DISTINCT r.report_date_en) AS active_days,
        COUNT(DISTINCT r.borehole_id) AS borehole_count,
        COUNT(DISTINCT r.rig_id) AS rig_count,
        COALESCE(SUM(r.drill_amount), 0) AS drilled_meters,
        COALESCE(SUM(r.water_amount), 0) AS water_amount,
        COALESCE(SUM(r.diesel_amount), 0) AS diesel_amount,
        COALESCE(SUM(r.rig_hours), 0) AS rig_hours,
        COALESCE(SUM(r.stop_duration_hours), 0) AS stop_hours,
        COALESCE(SUM(ch.actual_worked_hours), 0) AS actual_worked_hours,
        COALESCE(SUM(ch.crew_entries), 0) AS crew_entries,
        COALESCE(SUM(ch.known_worked_hours), 0) AS known_worked_hours,
        COALESCE(SUM(ch.missing_worked_hours), 0) AS missing_worked_hours,
        SUM(CASE WHEN COALESCE(ch.crew_entries, 0) = 0 THEN 1 ELSE 0 END) AS reports_without_crew,
        SUM(CASE WHEN ABS((r.drill_end_depth - r.drill_start_depth) - r.drill_amount) > 0.01 THEN 1 ELSE 0 END) AS interval_mismatch_reports,
        ROUND(SUM(r.diesel_amount) / NULLIF(SUM(r.drill_amount), 0), 3) AS diesel_per_meter,
        ROUND(SUM(r.water_amount) / NULLIF(SUM(r.drill_amount), 0), 3) AS water_per_meter,
        ROUND(SUM(r.drill_amount) / NULLIF(SUM(r.rig_hours), 0), 3) AS meters_per_rig_hour,
        ROUND(SUM(ch.known_worked_hours) * 100 / NULLIF(SUM(ch.crew_entries), 0), 1) AS worked_hours_coverage_percent
    {$baseJoins}
    WHERE {$whereSql}";
$summary = emcore_drilling_analytics_query($db, $summarySql, $params)->fetch();

$dailySql = "SELECT r.report_date_fa, r.report_date_en,
        SUM(CASE WHEN r.shift = 'DAY' THEN r.drill_amount ELSE 0 END) AS day_drilled,
        SUM(CASE WHEN r.shift = 'NIGHT' THEN r.drill_amount ELSE 0 END) AS night_drilled,
        SUM(r.drill_amount) AS drilled_meters,
        SUM(r.diesel_amount) AS diesel_amount,
        SUM(r.water_amount) AS water_amount,
        COALESCE(SUM(ch.actual_worked_hours), 0) AS actual_worked_hours,
        COALESCE(SUM(ch.missing_worked_hours), 0) AS missing_worked_hours
    {$baseJoins}
    WHERE {$whereSql}
    GROUP BY r.report_date_fa, r.report_date_en
    ORDER BY r.report_date_en";
$daily = emcore_drilling_analytics_query($db, $dailySql, $params)->fetchAll();

$boreholeSql = "SELECT b.id AS borehole_id, b.borehole_code, b.mine_id, m.mine_name,
        COUNT(*) AS report_count,
        SUM(r.drill_amount) AS drilled_meters,
        SUBSTRING_INDEX(GROUP_CONCAT(CAST(r.drill_end_depth AS CHAR) ORDER BY r.report_date_en DESC, r.id DESC), ',', 1) AS latest_end_depth,
        SUM(r.water_amount) AS water_amount,
        SUM(r.diesel_amount) AS diesel_amount,
        ROUND(SUM(r.water_amount) / NULLIF(SUM(r.drill_amount), 0), 3) AS water_per_meter,
        ROUND(SUM(r.diesel_amount) / NULLIF(SUM(r.drill_amount), 0), 3) AS diesel_per_meter,
        COALESCE(SUM(ch.actual_worked_hours), 0) AS actual_worked_hours,
        COALESCE(SUM(ch.missing_worked_hours), 0) AS missing_worked_hours
    {$baseJoins}
    WHERE {$whereSql}
    GROUP BY b.id, b.borehole_code, b.mine_id, m.mine_name
    ORDER BY drilled_meters DESC, b.borehole_code";
$boreholes = emcore_drilling_analytics_query($db, $boreholeSql, $params)->fetchAll();

$rigSql = "SELECT g.id AS rig_id, g.serial_number, g.display_name,
        COUNT(*) AS report_count, SUM(r.drill_amount) AS drilled_meters,
        SUM(r.rig_hours) AS rig_hours,
        ROUND(SUM(r.drill_amount) / NULLIF(SUM(r.rig_hours), 0), 3) AS meters_per_rig_hour,
        COALESCE(SUM(ch.actual_worked_hours), 0) AS actual_worked_hours,
        COALESCE(SUM(ch.missing_worked_hours), 0) AS missing_worked_hours
    {$baseJoins}
    WHERE {$whereSql}
    GROUP BY g.id, g.serial_number, g.display_name
    ORDER BY drilled_meters DESC, g.serial_number";
$rigs = emcore_drilling_analytics_query($db, $rigSql, $params)->fetchAll();

$roleSql = "SELECT c.role_key, COUNT(*) AS crew_entries,
        COUNT(c.worked_hours) AS known_worked_hours,
        SUM(c.worked_hours) AS actual_worked_hours,
        SUM(CASE WHEN c.worked_hours IS NULL THEN 1 ELSE 0 END) AS missing_worked_hours
    FROM emcore_drilling_report_crew c
    JOIN emcore_drilling_reports r ON r.id = c.report_id
    JOIN emcore_boreholes b ON b.id = r.borehole_id
    WHERE {$whereSql}
    GROUP BY c.role_key
    ORDER BY actual_worked_hours DESC, c.role_key";
$roles = emcore_drilling_analytics_query($db, $roleSql, $params)->fetchAll();

$peopleSql = "SELECT
        CASE WHEN c.person_id IS NOT NULL THEN CONCAT('p:', c.person_id)
             ELSE CONCAT('t:', c.worker_name_snapshot) END AS person_key,
        MAX(CASE WHEN c.person_id IS NOT NULL
                 THEN COALESCE(NULLIF(TRIM(CONCAT_WS(' ', p.first_name, p.last_name)), ''), c.worker_name_snapshot)
                 ELSE c.worker_name_snapshot END) AS person_name,
        GROUP_CONCAT(DISTINCT c.role_key ORDER BY c.role_key SEPARATOR ',') AS role_keys,
        COUNT(*) AS crew_entries,
        COUNT(c.worked_hours) AS known_worked_hours,
        SUM(c.worked_hours) AS actual_worked_hours,
        SUM(CASE WHEN c.worked_hours IS NULL THEN 1 ELSE 0 END) AS missing_worked_hours
    FROM emcore_drilling_report_crew c
    JOIN emcore_drilling_reports r ON r.id = c.report_id
    JOIN emcore_boreholes b ON b.id = r.borehole_id
    LEFT JOIN emcore_persons p ON p.id = c.person_id
    WHERE {$whereSql}
    GROUP BY CASE WHEN c.person_id IS NOT NULL THEN CONCAT('p:', c.person_id)
                  ELSE CONCAT('t:', c.worker_name_snapshot) END
    ORDER BY actual_worked_hours DESC, person_name";
$people = emcore_drilling_analytics_query($db, $peopleSql, $params)->fetchAll();

$downtimeSql = "SELECT COALESCE(NULLIF(TRIM(r.stop_causes), ''), 'ثبت‌نشده') AS stop_cause,
        COUNT(*) AS report_count,
        COALESCE(SUM(r.stop_duration_hours), 0) AS stop_hours,
        SUM(CASE WHEN r.operation_state = 'no_drilling' THEN 1 ELSE 0 END) AS no_drilling_reports
    FROM emcore_drilling_reports r
    JOIN emcore_boreholes b ON b.id = r.borehole_id
    WHERE {$whereSql} AND r.operation_state <> 'drilling'
    GROUP BY COALESCE(NULLIF(TRIM(r.stop_causes), ''), 'ثبت‌نشده')
    ORDER BY stop_hours DESC, report_count DESC
    LIMIT 12";
$downtime = emcore_drilling_analytics_query($db, $downtimeSql, $params)->fetchAll();

emcore_json([
    'success' => true,
    'data' => [
        'summary' => $summary,
        'daily' => $daily,
        'boreholes' => $boreholes,
        'rigs' => $rigs,
        'roles' => $roles,
        'people' => $people,
        'downtime' => $downtime,
    ],
    'meta' => [
        'generated_at' => date(DATE_ATOM),
        'filters' => [
            'mine_id' => $mineId,
            'borehole_id' => $boreholeId,
            'rig_id' => $rigId,
            'shift' => $shift,
            'date_from_fa' => $dateFrom ? $dateFrom['fa'] : null,
            'date_to_fa' => $dateTo ? $dateTo['fa'] : null,
        ],
        'semantics' => [
            'production' => 'sum_reported_drill_amount',
            'final_depth' => 'latest_report_end_depth_in_filter',
            'crew_hours' => 'sum_actual_non_null_only',
            'missing_crew_hours' => 'reported_separately_not_imputed',
        ],
    ],
]);
