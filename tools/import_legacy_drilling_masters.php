<?php

// Imports the legacy emidco_db_projects and emidco_db_gamaneh INSERT exports.
// Usage:
//   php tools/import_legacy_drilling_masters.php projects.sql boreholes.sql
//   php tools/import_legacy_drilling_masters.php projects.sql boreholes.sql --commit \
//       --actor-usr-uid=00000000000000000000000000000001

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This importer is CLI-only.\n");
    exit(2);
}

function master_option($prefix)
{
    global $argv;
    foreach ($argv as $argument) {
        if (strpos($argument, $prefix . '=') === 0) {
            return substr($argument, strlen($prefix) + 1);
        }
    }
    return null;
}

function master_normalize($value)
{
    $value = str_replace(['ي', 'ك', "\xE2\x80\x8C"], ['ی', 'ک', ' '], trim((string)$value));
    return preg_replace('/\s+/u', ' ', $value);
}

function master_config()
{
    $localFile = dirname(__DIR__) . '/emcore_api/emcore_config.php';
    $local = is_file($localFile) ? require $localFile : [];
    if (!is_array($local)) {
        throw new RuntimeException('Invalid EMCORE local configuration.');
    }
    return [
        'dsn' => getenv('EMCORE_DB_DSN') ?: (isset($local['db_dsn']) ? $local['db_dsn'] : ''),
        'user' => getenv('EMCORE_DB_USER') ?: (isset($local['db_user']) ? $local['db_user'] : ''),
        'password' => getenv('EMCORE_DB_PASSWORD') !== false
            ? getenv('EMCORE_DB_PASSWORD')
            : (isset($local['db_password']) ? $local['db_password'] : ''),
    ];
}

function master_read_projects($path)
{
    $projects = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match("/VALUES\\s*\\(\\s*(\\d+)\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $line, $match)) {
            $projects[(int)$match[1]] = master_normalize(stripcslashes($match[2]));
        }
    }
    return $projects;
}

function master_read_boreholes($path)
{
    $boreholes = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match("/VALUES\\s*\\(\\s*(\\d+)\\s*,\\s*(\\d+)\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $line, $match)) {
            $boreholes[] = [
                'legacy_id' => (int)$match[1],
                'project_id' => (int)$match[2],
                'borehole_code' => master_normalize(stripcslashes($match[3])),
            ];
        }
    }
    return $boreholes;
}

$projectsPath = isset($argv[1]) ? $argv[1] : '';
$boreholesPath = isset($argv[2]) ? $argv[2] : '';
if (!is_readable($projectsPath) || !is_readable($boreholesPath)) {
    fwrite(STDERR, "Provide readable projects.sql and boreholes.sql paths.\n");
    exit(2);
}
$commit = in_array('--commit', $argv, true);
$actorUsrUid = (string)master_option('--actor-usr-uid');
if ($commit && !preg_match('/^[A-Za-z0-9]{32}$/', $actorUsrUid)) {
    fwrite(STDERR, "--actor-usr-uid=<32-character ProcessMaker USR_UID> is required with --commit.\n");
    exit(2);
}

$config = master_config();
if ($config['dsn'] === '' || $config['user'] === '') {
    throw new RuntimeException('EMCORE database configuration is missing.');
}
$db = new PDO($config['dsn'], $config['user'], $config['password'], [
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

$legacyProjects = master_read_projects($projectsPath);
$legacyBoreholes = master_read_boreholes($boreholesPath);
if (!$legacyProjects || !$legacyBoreholes) {
    throw new RuntimeException('Legacy master INSERT statements could not be parsed.');
}

$mineByName = [];
foreach ($db->query('SELECT id, mine_name, alias_name FROM emcore_mines WHERE deleted_at IS NULL')->fetchAll() as $mine) {
    $mineByName[master_normalize($mine['mine_name'])] = (int)$mine['id'];
    if (!empty($mine['alias_name'])) {
        $mineByName[master_normalize($mine['alias_name'])] = (int)$mine['id'];
    }
}

$projectMap = [];
$unmappedProjects = [];
foreach ($legacyProjects as $legacyId => $name) {
    if (isset($mineByName[$name])) {
        $projectMap[$legacyId] = $mineByName[$name];
    } else {
        $unmappedProjects[] = ['legacy_project_id' => $legacyId, 'name' => $name];
    }
}

$ready = [];
$unmappedBoreholes = [];
foreach ($legacyBoreholes as $borehole) {
    if (!isset($projectMap[$borehole['project_id']])) {
        $unmappedBoreholes[] = $borehole;
        continue;
    }
    $borehole['mine_id'] = $projectMap[$borehole['project_id']];
    $ready[] = $borehole;
}

$stats = [
    'legacy_projects' => count($legacyProjects),
    'legacy_boreholes' => count($legacyBoreholes),
    'mapped_projects' => count($projectMap),
    'ready_boreholes' => count($ready),
    'inserted' => 0,
    'existing' => 0,
    'unmapped_projects' => count($unmappedProjects),
    'unmapped_boreholes' => count($unmappedBoreholes),
];

if ($commit && !$unmappedProjects) {
    $batchId = bin2hex(random_bytes(16));
    $existing = $db->prepare(
        'SELECT id FROM emcore_boreholes WHERE mine_id = :mine_id AND borehole_code = :code LIMIT 1'
    );
    $insert = $db->prepare(
        "INSERT INTO emcore_boreholes (mine_id, borehole_code, status, notes)
         VALUES (:mine_id, :code, 'active', :notes)"
    );
    $audit = $db->prepare(
        "INSERT INTO emcore_audit_log
            (request_id, actor_usr_uid, module_key, action, entity_type, entity_id,
             after_data, metadata, created_at)
         VALUES
            (:request_id, :actor_usr_uid, 'drilling_daily_reports', 'create',
             'borehole', :entity_id, :after_data, :metadata, NOW())"
    );
    $db->beginTransaction();
    try {
        foreach ($ready as $borehole) {
            $existing->execute([':mine_id' => $borehole['mine_id'], ':code' => $borehole['borehole_code']]);
            if ($existing->fetchColumn()) {
                $stats['existing']++;
                continue;
            }
            $insert->execute([
                ':mine_id' => $borehole['mine_id'],
                ':code' => $borehole['borehole_code'],
                ':notes' => 'مهاجرت از emidco_db_gamaneh؛ شناسه قدیمی ' . $borehole['legacy_id'],
            ]);
            $newId = (int)$db->lastInsertId();
            $audit->execute([
                ':request_id' => $batchId,
                ':actor_usr_uid' => $actorUsrUid,
                ':entity_id' => (string)$newId,
                ':after_data' => json_encode([
                    'id' => $newId,
                    'mine_id' => $borehole['mine_id'],
                    'borehole_code' => $borehole['borehole_code'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':metadata' => json_encode([
                    'legacy_id' => $borehole['legacy_id'],
                    'source' => basename($boreholesPath),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $stats['inserted']++;
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

echo json_encode([
    'mode' => $commit ? 'commit' : 'dry-run',
    'stats' => $stats,
    'unmapped_projects' => $unmappedProjects,
    'unmapped_borehole_samples' => array_slice($unmappedBoreholes, 0, 20),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($unmappedProjects ? 1 : 0);
