<?php

require_once __DIR__ . '/_bootstrap.php';

function emcore_audit_request_id()
{
    static $requestId;
    if ($requestId === null) {
        $requestId = bin2hex(random_bytes(16));
    }
    return $requestId;
}

function emcore_audit_json($value)
{
    if ($value === null) {
        return null;
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode EMCORE audit data.');
    }
    return $json;
}

function emcore_audit($moduleKey, $action, $entityType, $entityId, $before, $after, $metadata = null)
{
    $allowedActions = ['create', 'update', 'delete', 'permission_update'];
    if (!in_array($action, $allowedActions, true)) {
        throw new RuntimeException('Unknown EMCORE audit action.');
    }

    $actor = emcore_current_user();
    $stmt = emcore_db()->prepare(
        "INSERT INTO emcore_audit_log
            (request_id, actor_usr_uid, module_key, action, entity_type, entity_id,
             before_data, after_data, metadata, ip_address, user_agent, created_at)
         VALUES
            (:request_id, :actor_usr_uid, :module_key, :action, :entity_type, :entity_id,
             :before_data, :after_data, :metadata, :ip_address, :user_agent, NOW())"
    );
    $stmt->execute([
        ':request_id' => emcore_audit_request_id(),
        ':actor_usr_uid' => $actor['USR_UID'],
        ':module_key' => $moduleKey,
        ':action' => $action,
        ':entity_type' => $entityType,
        ':entity_id' => (string)$entityId,
        ':before_data' => emcore_audit_json($before),
        ':after_data' => emcore_audit_json($after),
        ':metadata' => emcore_audit_json($metadata),
        ':ip_address' => isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null,
        ':user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255, 'UTF-8') : null,
    ]);
}
