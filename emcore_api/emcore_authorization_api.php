<?php

require_once __DIR__ . '/_audit.php';

$action = emcore_action(['list', 'save']);

if ($action === 'list') {
    $currentUser = emcore_require_permission('authorization', 'read');
    $db = emcore_db();
    $users = $db->query(
        "SELECT USR_UID, USR_USERNAME, USR_FIRSTNAME, USR_LASTNAME, USR_EMAIL,
                USR_POSITION, USR_ROLE
         FROM USERS WHERE USR_STATUS = 'ACTIVE'
         ORDER BY USR_LASTNAME, USR_FIRSTNAME, USR_USERNAME"
    )->fetchAll();
    $modules = $db->query(
        "SELECT module_key, name_fa, name_en FROM emcore_modules
         WHERE is_active = 1 ORDER BY sort_order, module_key"
    )->fetchAll();
    $permissions = $db->query(
        "SELECT p.usr_uid, p.module_key, p.can_create, p.can_read, p.can_update, p.can_delete
         FROM emcore_user_permissions p
         JOIN emcore_modules m ON m.module_key = p.module_key AND m.is_active = 1
         JOIN USERS u ON u.USR_UID = p.usr_uid AND u.USR_STATUS = 'ACTIVE'"
    )->fetchAll();
    emcore_json(['success' => true, 'data' => [
        'users' => $users,
        'modules' => $modules,
        'permissions' => $permissions,
        'current_user_uid' => $currentUser['USR_UID'],
        'csrf_token' => emcore_csrf_token(),
    ]]);
}

$actor = emcore_require_permission('authorization', 'update');
emcore_require_csrf();
$usrUid = isset($_POST['usr_uid']) ? trim((string)$_POST['usr_uid']) : '';
$moduleKey = isset($_POST['module_key']) ? trim((string)$_POST['module_key']) : '';
if (!preg_match('/^[A-Za-z0-9]{32}$/', $usrUid)) {
    throw new EmcoreHttpException(422, 'شناسه کاربر نامعتبر است');
}
if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $moduleKey)) {
    throw new EmcoreHttpException(422, 'شناسه ماژول نامعتبر است');
}
$permissions = [
    'can_create' => emcore_post_bool('can_create'),
    'can_read' => emcore_post_bool('can_read'),
    'can_update' => emcore_post_bool('can_update'),
    'can_delete' => emcore_post_bool('can_delete'),
];

$db = emcore_db();
$db->beginTransaction();
try {
    $userStmt = $db->prepare(
        "SELECT USR_UID FROM USERS
         WHERE USR_UID = :usr_uid AND USR_STATUS = 'ACTIVE' LIMIT 1 FOR UPDATE"
    );
    $userStmt->execute([':usr_uid' => $usrUid]);
    if (!$userStmt->fetch()) {
        throw new EmcoreHttpException(422, 'کاربر فعال یافت نشد');
    }
    $moduleStmt = $db->prepare(
        'SELECT module_key FROM emcore_modules WHERE module_key = :module_key AND is_active = 1 LIMIT 1'
    );
    $moduleStmt->execute([':module_key' => $moduleKey]);
    if (!$moduleStmt->fetch()) {
        throw new EmcoreHttpException(422, 'ماژول فعال یافت نشد');
    }

    $beforeStmt = $db->prepare(
        "SELECT can_create, can_read, can_update, can_delete, granted_by, updated_at
         FROM emcore_user_permissions
         WHERE usr_uid = :usr_uid AND module_key = :module_key LIMIT 1 FOR UPDATE"
    );
    $beforeStmt->execute([':usr_uid' => $usrUid, ':module_key' => $moduleKey]);
    $before = $beforeStmt->fetch() ?: null;

    if ($moduleKey === 'authorization' && $permissions['can_update'] === 0 &&
        $before && (int)$before['can_update'] === 1) {
        $activeAdmins = $db->query(
            "SELECT p.usr_uid
             FROM emcore_user_permissions p
             JOIN USERS u ON u.USR_UID = p.usr_uid AND u.USR_STATUS = 'ACTIVE'
             WHERE p.module_key = 'authorization' AND p.can_update = 1
             FOR UPDATE"
        )->fetchAll();
        if (count($activeAdmins) <= 1) {
            throw new EmcoreHttpException(409, 'حذف دسترسی آخرین مدیر مجاز نیست');
        }
    }

    $after = $permissions;
    if (array_sum($permissions) === 0) {
        $delete = $db->prepare(
            'DELETE FROM emcore_user_permissions WHERE usr_uid = :usr_uid AND module_key = :module_key'
        );
        $delete->execute([':usr_uid' => $usrUid, ':module_key' => $moduleKey]);
        $after = null;
    } else {
        $save = $db->prepare(
            "INSERT INTO emcore_user_permissions
                (usr_uid, module_key, can_create, can_read, can_update, can_delete, granted_by)
             VALUES
                (:usr_uid, :module_key, :can_create, :can_read, :can_update, :can_delete, :granted_by)
             ON DUPLICATE KEY UPDATE
                can_create = VALUES(can_create), can_read = VALUES(can_read),
                can_update = VALUES(can_update), can_delete = VALUES(can_delete),
                granted_by = VALUES(granted_by), updated_at = CURRENT_TIMESTAMP"
        );
        $save->execute([
            ':usr_uid' => $usrUid,
            ':module_key' => $moduleKey,
            ':can_create' => $permissions['can_create'],
            ':can_read' => $permissions['can_read'],
            ':can_update' => $permissions['can_update'],
            ':can_delete' => $permissions['can_delete'],
            ':granted_by' => $actor['USR_UID'],
        ]);
        $after['granted_by'] = $actor['USR_UID'];
    }

    emcore_audit(
        'authorization',
        'permission_update',
        'user_permission',
        $usrUid . ':' . $moduleKey,
        $before,
        $after,
        ['target_usr_uid' => $usrUid, 'target_module_key' => $moduleKey]
    );
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}

emcore_json(['success' => true]);
