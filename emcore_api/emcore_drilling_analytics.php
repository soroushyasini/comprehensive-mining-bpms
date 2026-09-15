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

$action = emcore_action(['dashboard']);
emcore_require_permission(EMCORE_DRILLING_MODULE, 'read');
$db = emcore_db();

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
