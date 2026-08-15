<?php

require_once __DIR__ . '/_bootstrap.php';

$action = emcore_action(['list', 'get']);
emcore_require_permission('audit_log', 'read');
$db = emcore_db();

function emcore_decode_audit_row($row)
{
    foreach (['before_data', 'after_data', 'metadata'] as $field) {
        $row[$field] = $row[$field] === null ? null : json_decode($row[$field], true);
    }
    return $row;
}

if ($action === 'get') {
    $id = emcore_positive_id('id');
    $stmt = $db->prepare(
        "SELECT a.*, u.USR_USERNAME, u.USR_FIRSTNAME, u.USR_LASTNAME
         FROM emcore_audit_log a
         LEFT JOIN USERS u ON u.USR_UID = a.actor_usr_uid
         WHERE a.id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new EmcoreHttpException(404, 'رکورد تاریخچه یافت نشد');
    }
    emcore_json(['success' => true, 'data' => emcore_decode_audit_row($row)]);
}

$limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
$limit = max(1, min($limit, 200));
$beforeId = isset($_POST['before_id']) && ctype_digit((string)$_POST['before_id'])
    ? (int)$_POST['before_id'] : null;
$moduleKey = emcore_string('module_key', false, 64);
$actorUsrUid = emcore_string('actor_usr_uid', false, 32);
$entityType = emcore_string('entity_type', false, 64);

$where = [];
$params = [];
if ($beforeId !== null && $beforeId > 0) {
    $where[] = 'a.id < :before_id';
    $params[':before_id'] = $beforeId;
}
if ($moduleKey !== null) {
    $where[] = 'a.module_key = :module_key';
    $params[':module_key'] = $moduleKey;
}
if ($actorUsrUid !== null) {
    if (!preg_match('/^[A-Za-z0-9]{32}$/', $actorUsrUid)) {
        throw new EmcoreHttpException(422, 'شناسه کاربر نامعتبر است');
    }
    $where[] = 'a.actor_usr_uid = :actor_usr_uid';
    $params[':actor_usr_uid'] = $actorUsrUid;
}
if ($entityType !== null) {
    $where[] = 'a.entity_type = :entity_type';
    $params[':entity_type'] = $entityType;
}

$sql = "SELECT a.id, a.request_id, a.actor_usr_uid, a.module_key, a.action,
               a.entity_type, a.entity_id, a.ip_address, a.created_at,
               u.USR_USERNAME, u.USR_FIRSTNAME, u.USR_LASTNAME
        FROM emcore_audit_log a
        LEFT JOIN USERS u ON u.USR_UID = a.actor_usr_uid";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY a.id DESC LIMIT ' . $limit;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

emcore_json([
    'success' => true,
    'data' => $rows,
    'next_before_id' => $rows ? (int)end($rows)['id'] : null,
]);
