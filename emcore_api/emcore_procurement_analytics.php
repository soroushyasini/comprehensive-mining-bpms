<?php

require_once __DIR__ . '/_module_permissions.php';

const EMCORE_PROCUREMENT_ANALYTICS_MODULE = 'procurement_notices';

function emcore_procurement_analytics_query($db, $sql, $params = [])
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function emcore_procurement_analytics_date($db, $name)
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

function emcore_procurement_analytics_enum($name, $allowed)
{
    $value = emcore_string($name, false, 40);
    if ($value !== null && !in_array($value, $allowed, true)) {
        throw new EmcoreHttpException(422, 'مقدار فیلتر نامعتبر است', [$name => 'invalid_value']);
    }
    return $value;
}

function emcore_procurement_analytics_integer($name, $default, $minimum, $maximum)
{
    $raw = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
    if ($raw === '') {
        return $default;
    }
    if (!preg_match('/^[0-9]{1,9}$/', $raw)) {
        throw new EmcoreHttpException(422, 'عدد فیلتر نامعتبر است', [$name => 'integer_required']);
    }
    $value = (int)$raw;
    if ($value < $minimum || $value > $maximum) {
        throw new EmcoreHttpException(422, 'عدد فیلتر خارج از بازه مجاز است', [$name => 'out_of_range']);
    }
    return $value;
}

function emcore_procurement_analytics_lookup($db, $column)
{
    $allowed = ['source_name', 'contracting_authority', 'responsible_unit', 'category_name'];
    if (!in_array($column, $allowed, true)) {
        throw new RuntimeException('Unknown procurement analytics lookup.');
    }
    $notLegacyNan = $column === 'category_name' ? " AND LOWER(TRIM({$column})) <> 'nan'" : '';
    $rows = $db->query(
        "SELECT DISTINCT {$column} AS value
         FROM emcore_procurement_notices
         WHERE deleted_at IS NULL AND {$column} IS NOT NULL AND TRIM({$column}) <> ''
         {$notLegacyNan}
         ORDER BY {$column}
         LIMIT 201"
    )->fetchAll(PDO::FETCH_COLUMN);
    return [array_slice($rows, 0, 200), count($rows) > 200];
}

function emcore_procurement_analytics_filters($db)
{
    $dateFrom = emcore_procurement_analytics_date($db, 'date_from_fa');
    $dateTo = emcore_procurement_analytics_date($db, 'date_to_fa');
    if ($dateFrom !== null && $dateTo !== null && $dateFrom['en'] > $dateTo['en']) {
        throw new EmcoreHttpException(422, 'تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد', [
            'date_from_fa' => 'after_date_to',
        ]);
    }

    $noticeType = emcore_procurement_analytics_enum('notice_type', ['tender', 'auction', 'unknown']);
    $participationStatus = emcore_procurement_analytics_enum('participation_status', [
        'registered', 'interested', 'documents_submitted', 'won', 'lost', 'unknown',
    ]);
    $sourceName = emcore_string('source_name', false, 255);
    $authority = emcore_string('contracting_authority', false, 255);
    $unit = emcore_string('responsible_unit', false, 64);
    $category = emcore_string('category_name', false, 255);
    $minimumAuthorityTotal = emcore_procurement_analytics_integer(
        'minimum_authority_total',
        5,
        1,
        100000
    );
    $topN = emcore_procurement_analytics_integer('top_n', 12, 5, 30);

    $where = ['p.deleted_at IS NULL'];
    $params = [];
    if ($dateFrom !== null) {
        $where[] = 'p.registered_on_en >= :date_from_en';
        $params[':date_from_en'] = $dateFrom['en'];
    }
    if ($dateTo !== null) {
        $where[] = 'p.registered_on_en <= :date_to_en';
        $params[':date_to_en'] = $dateTo['en'];
    }
    if ($noticeType !== null) {
        $where[] = 'p.notice_type = :notice_type';
        $params[':notice_type'] = $noticeType;
    }
    if ($participationStatus !== null) {
        $where[] = 'p.participation_status = :participation_status';
        $params[':participation_status'] = $participationStatus;
    }
    foreach ([
        ['value' => $sourceName, 'column' => 'source_name', 'placeholder' => ':source_name'],
        ['value' => $authority, 'column' => 'contracting_authority', 'placeholder' => ':contracting_authority'],
        ['value' => $unit, 'column' => 'responsible_unit', 'placeholder' => ':responsible_unit'],
        ['value' => $category, 'column' => 'category_name', 'placeholder' => ':category_name'],
    ] as $exactFilter) {
        if ($exactFilter['value'] !== null) {
            $where[] = 'p.' . $exactFilter['column'] . ' = ' . $exactFilter['placeholder'];
            $params[$exactFilter['placeholder']] = $exactFilter['value'];
        }
    }

    return [
        'where_sql' => implode(' AND ', $where),
        'params' => $params,
        'minimum_authority_total' => $minimumAuthorityTotal,
        'top_n' => $topN,
        'public' => [
            'date_from_fa' => $dateFrom ? $dateFrom['fa'] : null,
            'date_to_fa' => $dateTo ? $dateTo['fa'] : null,
            'notice_type' => $noticeType,
            'participation_status' => $participationStatus,
            'source_name' => $sourceName,
            'contracting_authority' => $authority,
            'responsible_unit' => $unit,
            'category_name' => $category,
            'minimum_authority_total' => $minimumAuthorityTotal,
            'top_n' => $topN,
        ],
    ];
}

function emcore_procurement_analytics_ints($row, $fields)
{
    foreach ($fields as $field) {
        $row[$field] = isset($row[$field]) ? (int)$row[$field] : 0;
    }
    return $row;
}

function emcore_procurement_analytics_rows($rows, $fields)
{
    foreach ($rows as &$row) {
        $row = emcore_procurement_analytics_ints($row, $fields);
    }
    unset($row);
    return $rows;
}

function emcore_procurement_analytics_hierarchy($rows)
{
    $categories = [];
    foreach ($rows as $row) {
        $categoryLabel = (string)$row['category_label'];
        $subcategoryLabel = (string)$row['subcategory_label'];
        $productLabel = (string)$row['product_label'];
        $count = (int)$row['notice_count'];
        if (!isset($categories[$categoryLabel])) {
            $categories[$categoryLabel] = [
                'label' => $categoryLabel,
                'count' => 0,
                'subcategories' => [],
            ];
        }
        if (!isset($categories[$categoryLabel]['subcategories'][$subcategoryLabel])) {
            $categories[$categoryLabel]['subcategories'][$subcategoryLabel] = [
                'label' => $subcategoryLabel,
                'count' => 0,
                'products' => [],
            ];
        }
        $categories[$categoryLabel]['count'] += $count;
        $categories[$categoryLabel]['subcategories'][$subcategoryLabel]['count'] += $count;
        $categories[$categoryLabel]['subcategories'][$subcategoryLabel]['products'][] = [
            'label' => $productLabel,
            'count' => $count,
        ];
    }

    foreach ($categories as &$category) {
        foreach ($category['subcategories'] as &$subcategory) {
            usort($subcategory['products'], function ($left, $right) {
                return $right['count'] <=> $left['count'] ?: strcmp($left['label'], $right['label']);
            });
        }
        unset($subcategory);
        $category['subcategories'] = array_values($category['subcategories']);
        usort($category['subcategories'], function ($left, $right) {
            return $right['count'] <=> $left['count'] ?: strcmp($left['label'], $right['label']);
        });
    }
    unset($category);
    $categories = array_values($categories);
    usort($categories, function ($left, $right) {
        return $right['count'] <=> $left['count'] ?: strcmp($left['label'], $right['label']);
    });
    return $categories;
}

$action = emcore_action(['lookups', 'dashboard']);
emcore_require_permission(EMCORE_PROCUREMENT_ANALYTICS_MODULE, 'read');
$db = emcore_db();

if ($action === 'lookups') {
    $lookups = [];
    $truncated = [];
    foreach (['source_name', 'contracting_authority', 'responsible_unit', 'category_name'] as $column) {
        list($lookups[$column], $truncated[$column]) = emcore_procurement_analytics_lookup($db, $column);
    }
    $dateBounds = $db->query(
        "SELECT MIN(registered_on_fa) AS minimum_fa,
                MAX(registered_on_fa) AS maximum_fa,
                MIN(registered_on_en) AS minimum_en,
                MAX(registered_on_en) AS maximum_en
         FROM emcore_procurement_notices
         WHERE deleted_at IS NULL AND registered_on_en IS NOT NULL"
    )->fetch();
    $lookups['notice_types'] = ['tender', 'auction', 'unknown'];
    $lookups['participation_statuses'] = [
        'registered', 'interested', 'documents_submitted', 'won', 'lost', 'unknown',
    ];
    $lookups['date_bounds'] = $dateBounds;
    emcore_json([
        'success' => true,
        'data' => $lookups,
        'meta' => [
            'generated_at' => date(DATE_ATOM),
            'lookup_limit' => 200,
            'truncated' => $truncated,
        ],
    ]);
}

$filter = emcore_procurement_analytics_filters($db);
$whereSql = $filter['where_sql'];
$params = $filter['params'];

$summarySql = "SELECT COUNT(*) AS total,
        COALESCE(SUM(p.notice_type = 'tender'), 0) AS tender_count,
        COALESCE(SUM(p.notice_type = 'auction'), 0) AS auction_count,
        COALESCE(SUM(p.notice_type = 'unknown'), 0) AS unknown_type_count,
        COALESCE(SUM(p.participation_status = 'won'), 0) AS won_count,
        COALESCE(SUM(p.participation_status = 'unknown'), 0) AS unknown_status_count,
        COALESCE(SUM(p.response_deadline_en IS NOT NULL
            AND DATEDIFF(p.response_deadline_en, CURDATE()) > p.alert_lead_days), 0) AS open_count,
        COALESCE(SUM(p.response_deadline_en IS NOT NULL
            AND DATEDIFF(p.response_deadline_en, CURDATE()) BETWEEN 0 AND p.alert_lead_days), 0) AS urgent_count,
        COALESCE(SUM(p.response_deadline_en < CURDATE()), 0) AS expired_count,
        COALESCE(SUM(p.response_deadline_en IS NULL), 0) AS no_deadline_count,
        COALESCE(SUM(p.registered_on_en IS NULL), 0) AS missing_registration_date_count,
        COALESCE(SUM(p.documents_deadline_en IS NOT NULL AND p.response_deadline_en IS NOT NULL
            AND p.documents_deadline_en > p.response_deadline_en), 0) AS reversed_deadline_count,
        COALESCE(SUM(p.category_name IS NULL OR TRIM(p.category_name) = ''
            OR LOWER(TRIM(p.category_name)) = 'nan'), 0) AS missing_category_count,
        COALESCE(SUM(EXISTS(
            SELECT 1 FROM emcore_procurement_files f
            WHERE f.procurement_id = p.id AND f.deleted_at IS NULL
        )), 0) AS file_coverage_count,
        COUNT(DISTINCT NULLIF(TRIM(p.contracting_authority), '')) AS authority_count,
        COUNT(DISTINCT NULLIF(TRIM(p.responsible_unit), '')) AS responsible_unit_count
    FROM emcore_procurement_notices p
    WHERE {$whereSql}";
$summary = emcore_procurement_analytics_query($db, $summarySql, $params)->fetch();
$summary = emcore_procurement_analytics_ints($summary, [
    'total', 'tender_count', 'auction_count', 'unknown_type_count', 'won_count',
    'unknown_status_count', 'open_count', 'urgent_count', 'expired_count',
    'no_deadline_count', 'missing_registration_date_count', 'reversed_deadline_count',
    'missing_category_count', 'file_coverage_count', 'authority_count', 'responsible_unit_count',
]);

$monthlySql = "SELECT LEFT(p.registered_on_fa, 7) AS period_fa,
        MIN(p.registered_on_en) AS sort_date,
        COUNT(*) AS total,
        COALESCE(SUM(p.notice_type = 'tender'), 0) AS tender_count,
        COALESCE(SUM(p.notice_type = 'auction'), 0) AS auction_count,
        COALESCE(SUM(p.notice_type = 'unknown'), 0) AS unknown_count
    FROM emcore_procurement_notices p
    WHERE {$whereSql}
      AND p.registered_on_fa REGEXP '^1[34][0-9]{2}/(0[1-9]|1[0-2])/[0-3][0-9]$'
    GROUP BY LEFT(p.registered_on_fa, 7)
    ORDER BY sort_date DESC
    LIMIT 24";
$monthly = emcore_procurement_analytics_query($db, $monthlySql, $params)->fetchAll();
$monthly = array_reverse(emcore_procurement_analytics_rows(
    $monthly,
    ['total', 'tender_count', 'auction_count', 'unknown_count']
));

$authorityParams = $params;
$authorityParams[':minimum_authority_total'] = $filter['minimum_authority_total'];
$authorityLimit = $filter['top_n'] + 1;
$authoritySql = "SELECT COALESCE(NULLIF(TRIM(p.contracting_authority), ''), 'نامشخص') AS label,
        COUNT(*) AS total,
        COALESCE(SUM(p.notice_type = 'tender'), 0) AS tender_count,
        COALESCE(SUM(p.notice_type = 'auction'), 0) AS auction_count,
        COALESCE(SUM(p.notice_type = 'unknown'), 0) AS unknown_count
    FROM emcore_procurement_notices p
    WHERE {$whereSql}
    GROUP BY COALESCE(NULLIF(TRIM(p.contracting_authority), ''), 'نامشخص')
    HAVING COUNT(*) >= :minimum_authority_total
    ORDER BY total DESC, label
    LIMIT {$authorityLimit}";
$authorities = emcore_procurement_analytics_query($db, $authoritySql, $authorityParams)->fetchAll();
$authoritiesTruncated = count($authorities) > $filter['top_n'];
$authorities = array_slice($authorities, 0, $filter['top_n']);
$authorities = emcore_procurement_analytics_rows(
    $authorities,
    ['total', 'tender_count', 'auction_count', 'unknown_count']
);

$unitSql = "SELECT COALESCE(NULLIF(TRIM(p.responsible_unit), ''), 'نامشخص') AS label,
        COUNT(*) AS notice_count
    FROM emcore_procurement_notices p
    WHERE {$whereSql}
    GROUP BY COALESCE(NULLIF(TRIM(p.responsible_unit), ''), 'نامشخص')
    ORDER BY notice_count DESC, label
    LIMIT 101";
$responsibleUnits = emcore_procurement_analytics_query($db, $unitSql, $params)->fetchAll();
$unitsTruncated = count($responsibleUnits) > 100;
$responsibleUnits = emcore_procurement_analytics_rows(
    array_slice($responsibleUnits, 0, 100),
    ['notice_count']
);

$statusSql = "SELECT p.participation_status AS status, COUNT(*) AS notice_count
    FROM emcore_procurement_notices p
    WHERE {$whereSql}
    GROUP BY p.participation_status
    ORDER BY notice_count DESC, status";
$statuses = emcore_procurement_analytics_rows(
    emcore_procurement_analytics_query($db, $statusSql, $params)->fetchAll(),
    ['notice_count']
);

$deadlineSql = "SELECT CASE
        WHEN p.response_deadline_en IS NULL THEN 'no_deadline'
        WHEN p.response_deadline_en < CURDATE() THEN 'expired'
        WHEN DATEDIFF(p.response_deadline_en, CURDATE()) <= p.alert_lead_days THEN 'urgent'
        ELSE 'open'
    END AS deadline_state, COUNT(*) AS notice_count
    FROM emcore_procurement_notices p
    WHERE {$whereSql}
    GROUP BY deadline_state
    ORDER BY FIELD(deadline_state, 'open', 'urgent', 'expired', 'no_deadline')";
$deadlineStates = emcore_procurement_analytics_rows(
    emcore_procurement_analytics_query($db, $deadlineSql, $params)->fetchAll(),
    ['notice_count']
);

$categorySql = "SELECT CASE
        WHEN p.category_name IS NULL OR TRIM(p.category_name) = ''
            OR LOWER(TRIM(p.category_name)) = 'nan' THEN 'نامشخص'
        ELSE TRIM(p.category_name)
    END AS label,
        COUNT(*) AS notice_count
    FROM emcore_procurement_notices p
    WHERE {$whereSql}
    GROUP BY label
    ORDER BY notice_count DESC, label
    LIMIT 101";
$categories = emcore_procurement_analytics_query($db, $categorySql, $params)->fetchAll();
$categoriesTruncated = count($categories) > 100;
$categories = emcore_procurement_analytics_rows(array_slice($categories, 0, 100), ['notice_count']);

$hierarchySql = "SELECT
        CASE WHEN p.category_name IS NULL OR TRIM(p.category_name) = ''
            OR LOWER(TRIM(p.category_name)) = 'nan' THEN 'نامشخص'
            ELSE TRIM(p.category_name) END AS category_label,
        CASE WHEN p.subcategory_name IS NULL OR TRIM(p.subcategory_name) = ''
            OR LOWER(TRIM(p.subcategory_name)) = 'nan' THEN 'نامشخص'
            ELSE TRIM(p.subcategory_name) END AS subcategory_label,
        CASE WHEN p.product_name IS NULL OR TRIM(p.product_name) = ''
            OR LOWER(TRIM(p.product_name)) = 'nan' THEN 'نامشخص'
            ELSE TRIM(p.product_name) END AS product_label,
        COUNT(*) AS notice_count
    FROM emcore_procurement_notices p
    WHERE {$whereSql}
    GROUP BY category_label, subcategory_label, product_label
    ORDER BY category_label, subcategory_label, notice_count DESC, product_label
    LIMIT 1001";
$hierarchyRows = emcore_procurement_analytics_query($db, $hierarchySql, $params)->fetchAll();
$hierarchyTruncated = count($hierarchyRows) > 1000;
$hierarchy = emcore_procurement_analytics_hierarchy(array_slice($hierarchyRows, 0, 1000));

emcore_json([
    'success' => true,
    'data' => [
        'summary' => $summary,
        'monthly' => $monthly,
        'authorities' => $authorities,
        'responsible_units' => $responsibleUnits,
        'participation_statuses' => $statuses,
        'deadline_states' => $deadlineStates,
        'categories' => $categories,
        'category_hierarchy' => $hierarchy,
    ],
    'meta' => [
        'generated_at' => date(DATE_ATOM),
        'filters' => $filter['public'],
        'quality' => [
            'unknown_type_count' => $summary['unknown_type_count'],
            'unknown_status_count' => $summary['unknown_status_count'],
            'missing_registration_date_count' => $summary['missing_registration_date_count'],
            'reversed_deadline_count' => $summary['reversed_deadline_count'],
            'missing_category_count' => $summary['missing_category_count'],
        ],
        'limits' => [
            'monthly_periods' => 24,
            'authority_top_n' => $filter['top_n'],
            'authorities_truncated' => $authoritiesTruncated,
            'units_truncated' => $unitsTruncated,
            'categories_truncated' => $categoriesTruncated,
            'hierarchy_combinations' => 1000,
            'hierarchy_truncated' => $hierarchyTruncated,
        ],
        'semantics' => [
            'scope' => 'all_non_deleted_procurement_notices_matching_every_active_filter',
            'date_filter' => 'inclusive_registered_on_en_range_from_jalali_inputs',
            'monthly' => 'last_24_jalali_months_with_data_in_scope',
            'deadline_state' => 'calculated_at_request_time_from_response_deadline_and_alert_lead_days',
            'file_coverage' => 'notice_has_at_least_one_active_managed_file_or_legacy_reference',
            'unknowns' => 'reported_explicitly_and_never_imputed',
        ],
    ],
]);
