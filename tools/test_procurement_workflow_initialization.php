<?php
// Exercise the real initializer only against the dedicated, loopback fixture.
if (PHP_SAPI !== 'cli' || getenv('EMCORE_DB_DSN') !== 'mysql:host=127.0.0.1;port=33379;dbname=emcore_procurement_fixture;charset=utf8mb4') {
    throw new RuntimeException('Disposable procurement fixture required.');
}
$db = new PDO(getenv('EMCORE_DB_DSN'), getenv('EMCORE_DB_USER'), getenv('EMCORE_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$checks = 0;
function verify($ok, $label) { global $checks; ++$checks; if (!$ok) throw new RuntimeException($label); }
function initialize($commit = false, $override = []) {
    $args = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir')];
    foreach (['pdo', 'mysqlnd', 'pdo_mysql', 'mbstring', 'fileinfo'] as $name) {
        foreach ([$name . '.so', 'php_' . $name . '.dll', $name . '.dll'] as $file) {
            $path = rtrim(ini_get('extension_dir'), '/\\') . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) { $args[] = '-d'; $args[] = 'extension=' . $path; break; }
        }
    }
    $args[] = __DIR__ . '/configure_procurement_workflow.php';
    if ($commit) $args[] = '--commit';
    $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), array_merge(getenv(), $override));
    if (!is_resource($process)) throw new RuntimeException('Cannot start initializer.');
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), json_decode($out, true), $error];
}
$op = str_repeat('1', 32); $mgr = str_repeat('2', 32);
$workflows = $db->query('SELECT * FROM emcore_procurement_workflows WHERE app_uid IS NOT NULL')->fetchAll();
$assignments = $db->query('SELECT id,owner_usr_uid,manager_usr_uid,updated_at FROM emcore_procurement_notices')->fetchAll();
$ownId = null; $removedWorkflows = false;
try {
    // Migration replay must preserve both local NULL links and real historical links.
    $before = $db->query('SELECT procurement_id,app_uid,workflow_stage FROM emcore_procurement_workflows ORDER BY procurement_id')->fetchAll();
    $migration = file_get_contents(__DIR__ . '/../database/migrations/017_emcore_procurement_panel_workflow.sql');
    $db->exec($migration); $db->exec($migration);
    verify($before === $db->query('SELECT procurement_id,app_uid,workflow_stage FROM emcore_procurement_workflows ORDER BY procurement_id')->fetchAll(), '017 replay preserves links/stages');
    $trackingBefore=$db->query('SELECT id,follow_up_stage,participation_status FROM emcore_procurement_notices ORDER BY id')->fetchAll();
    $trackingMigration=file_get_contents(__DIR__.'/../database/migrations/018_emcore_procurement_follow_up_stages.sql');
    $db->exec($trackingMigration);$db->exec($trackingMigration);
    verify($trackingBefore===$db->query('SELECT id,follow_up_stage,participation_status FROM emcore_procurement_notices ORDER BY id')->fetchAll(),'018 replay preserves stages and outcomes');
    if ($workflows) {
        [$code, , $error] = initialize();
        verify($code === 1 && strpos($error, 'Historical native case links') !== false, 'initializer refuses existing native cases');
    }
    foreach ($workflows as $row) $db->prepare('DELETE FROM emcore_procurement_workflows WHERE procurement_id=?')->execute([$row['procurement_id']]);
    $removedWorkflows = true;
    [$code, , $error] = initialize();
    verify($code === 1 && strpos($error, 'assignments differ') !== false, 'initializer refuses unrelated owners');
    $db->prepare('UPDATE emcore_procurement_notices SET owner_usr_uid=?,manager_usr_uid=?,updated_at=updated_at')->execute([$op, $mgr]);
    $db->prepare("INSERT INTO emcore_procurement_notices (notice_type,title,created_by_usr_uid,updated_by_usr_uid,updated_at) VALUES ('tender','fixture initializer',?,?,'2020-01-02 03:04:05')")->execute([str_repeat('9', 32), str_repeat('9', 32)]);
    $ownId = $db->lastInsertId();
    $select = $db->prepare('SELECT * FROM emcore_procurement_notices WHERE id=?');
    $select->execute([$ownId]); $original = $select->fetch();
    $events = (int)$db->query('SELECT COUNT(*) FROM emcore_procurement_events')->fetchColumn();
    [$code, $dry] = initialize();
    verify($code === 0 && $dry['mode'] === 'dry-run' && $dry['assigned_count'] === 1, 'dry-run validates users without TASK table');
    $select->execute([$ownId]); verify($select->fetch() === $original, 'dry-run rolls ownership back');
    [$code, $result] = initialize(true);
    verify($code === 0 && $result['mode'] === 'commit' && $result['assigned_count'] === 1, 'commit assigns the unowned record');
    $select->execute([$ownId]); $after = $select->fetch();
    verify($after['owner_usr_uid'] === $op && $after['manager_usr_uid'] === $mgr, 'correct owner and manager');
    foreach (['created_by_usr_uid', 'updated_by_usr_uid', 'updated_at', 'lock_version', 'workflow_history_only', 'participation_status'] as $field) verify($after[$field] === $original[$field], 'preserves ' . $field);
    verify((int)$db->query('SELECT COUNT(*) FROM emcore_procurement_events')->fetchColumn() === $events, 'no synthetic past events');
    [$code, $again] = initialize(true);
    verify($code === 0 && $again['assigned_count'] === 0, 'repeat initialization is idempotent');
    [$code] = initialize(false, ['EMCORE_PROCUREMENT_WORKFLOW_MANAGER' => $op]);
    verify($code === 1, 'same operator and manager rejected');
    [$code] = initialize(false, ['EMCORE_PROCUREMENT_WORKFLOW_MANAGER' => str_repeat('8', 32)]);
    verify($code === 1, 'unknown participant rejected');
} finally {
    if ($ownId) $db->prepare('DELETE FROM emcore_procurement_notices WHERE id=?')->execute([$ownId]);
    foreach ($assignments as $row) $db->prepare('UPDATE emcore_procurement_notices SET owner_usr_uid=?,manager_usr_uid=?,updated_at=? WHERE id=?')->execute([$row['owner_usr_uid'], $row['manager_usr_uid'], $row['updated_at'], $row['id']]);
    foreach ($removedWorkflows ? $workflows : [] as $row) {
        $columns = implode(',', array_keys($row)); $values = implode(',', array_fill(0, count($row), '?'));
        $db->prepare("INSERT INTO emcore_procurement_workflows ($columns) VALUES ($values)")->execute(array_values($row));
    }
}
echo "Procurement workflow initialization: {$checks} assertions passed.\n";
