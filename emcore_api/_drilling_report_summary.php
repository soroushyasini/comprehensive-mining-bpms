<?php

// All callers use the SQL alias r. Keep ties deterministic for legacy shifts.
function emcore_drilling_report_order($latest = false)
{
    $direction = $latest ? 'DESC' : 'ASC';
    return "r.report_date_en {$direction}, COALESCE(r.start_time, r.end_time, '00:00:00') {$direction},
        CASE WHEN r.shift = 'DAY' THEN 1 WHEN r.shift = 'NIGHT' THEN 2 ELSE 3 END {$direction}, r.id {$direction}";
}

function emcore_drilling_report_boundaries($db, $boreholeId)
{
    $firstOrder = emcore_drilling_report_order();
    $lastOrder = emcore_drilling_report_order(true);
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS report_count, MAX(CASE WHEN first_rank = 1 THEN report_date_fa END) AS first_report_date_fa,
                MAX(CASE WHEN last_rank = 1 THEN report_date_fa END) AS last_report_date_fa,
                MAX(CASE WHEN first_rank = 1 THEN drill_start_depth END) AS first_start_depth,
                MAX(CASE WHEN last_rank = 1 THEN drill_end_depth END) AS latest_end_depth
         FROM (
            SELECT r.report_date_fa, r.drill_start_depth, r.drill_end_depth,
                   ROW_NUMBER() OVER (ORDER BY {$firstOrder}) AS first_rank,
                   ROW_NUMBER() OVER (ORDER BY {$lastOrder}) AS last_rank
            FROM emcore_drilling_reports r WHERE r.borehole_id = ? AND r.deleted_at IS NULL
         ) AS ranked_reports"
    );
    $stmt->execute([$boreholeId]);
    $row = $stmt->fetch();
    $row['report_count'] = (int)$row['report_count'];
    foreach (['first_start_depth', 'latest_end_depth'] as $key) {
        $row[$key] = $row[$key] === null ? null : (float)$row[$key];
    }
    return $row;
}
