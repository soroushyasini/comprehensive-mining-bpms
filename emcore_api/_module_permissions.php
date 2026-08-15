<?php

require_once __DIR__ . '/_audit.php';

function emcore_module_permissions($moduleKey)
{
    $user = emcore_current_user();
    $stmt = emcore_db()->prepare(
        "SELECT can_create, can_read, can_update, can_delete
         FROM emcore_user_permissions
         WHERE usr_uid = :usr_uid AND module_key = :module_key LIMIT 1"
    );
    $stmt->execute([':usr_uid' => $user['USR_UID'], ':module_key' => $moduleKey]);
    $row = $stmt->fetch();
    return [
        'can_create' => $row ? (int)$row['can_create'] : 0,
        'can_read' => $row ? (int)$row['can_read'] : 0,
        'can_update' => $row ? (int)$row['can_update'] : 0,
        'can_delete' => $row ? (int)$row['can_delete'] : 0,
    ];
}
