<?php

require_once __DIR__ . '/_module_permissions.php';

$action = emcore_action(['list', 'get', 'create', 'update', 'delete']);
$capability = ['list' => 'read', 'get' => 'read', 'create' => 'create', 'update' => 'update', 'delete' => 'delete'][$action];
emcore_require_permission('companies', $capability);
$db = emcore_db();

if ($action === 'list') {
    $stmt = $db->query(
        "SELECT id, name_fa, legal_type, registration_number, national_id, phone, is_active
         FROM emcore_companies WHERE deleted_at IS NULL ORDER BY name_fa"
    );
    emcore_json(['success' => true, 'data' => $stmt->fetchAll(), 'csrf_token' => emcore_csrf_token(), 'permissions' => emcore_module_permissions('companies')]);
}
if ($action === 'get') {
    $id = emcore_positive_id('id');
    $stmt = $db->prepare('SELECT * FROM emcore_companies WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new EmcoreHttpException(404, 'شرکت یافت نشد');
    }
    emcore_json(['success' => true, 'data' => $row]);
}

emcore_require_csrf();

if ($action === 'delete') {
    $id = emcore_positive_id('id');
    $db->beginTransaction();
    try {
        $select = $db->prepare('SELECT * FROM emcore_companies WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        $select->execute([':id' => $id]);
        $before = $select->fetch();
        if (!$before) {
            throw new EmcoreHttpException(404, 'شرکت یافت نشد');
        }
        $db->prepare('UPDATE emcore_companies SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id')->execute([':id' => $id]);
        $afterStmt = $db->prepare('SELECT * FROM emcore_companies WHERE id = :id LIMIT 1');
        $afterStmt->execute([':id' => $id]);
        emcore_audit('companies', 'delete', 'company', $id, $before, $afterStmt->fetch());
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
    emcore_json(['success' => true]);
}

$nameFa = emcore_string('name_fa', true, 255);
$legalType = emcore_string('legal_type', false, 50);
$allowedLegalTypes = ['private_llc', 'private_joint_stock', 'public_joint_stock', 'cooperative', 'other'];
if ($legalType !== null && !in_array($legalType, $allowedLegalTypes, true)) {
    throw new EmcoreHttpException(422, 'نوع حقوقی نامعتبر است');
}
$values = [
    ':name_fa' => $nameFa,
    ':legal_type' => $legalType,
    ':registration_number' => emcore_string('reg_number', false, 50),
    ':national_id' => emcore_string('national_id', false, 50),
    ':phone' => emcore_string('phone', false, 50),
];

if ($action === 'create') {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO emcore_companies
                (name_fa, legal_type, registration_number, national_id, phone, is_active, created_at, updated_at)
             VALUES (:name_fa, :legal_type, :registration_number, :national_id, :phone, 1, NOW(), NOW())"
        );
        $stmt->execute($values);
        $id = (int)$db->lastInsertId();
        $afterStmt = $db->prepare('SELECT * FROM emcore_companies WHERE id = :id LIMIT 1');
        $afterStmt->execute([':id' => $id]);
        emcore_audit('companies', 'create', 'company', $id, null, $afterStmt->fetch());
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
    emcore_json(['success' => true, 'id' => $id], 201);
}

$id = emcore_positive_id('id');
$db->beginTransaction();
try {
    $select = $db->prepare('SELECT * FROM emcore_companies WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
    $select->execute([':id' => $id]);
    $before = $select->fetch();
    if (!$before) {
        throw new EmcoreHttpException(404, 'شرکت یافت نشد');
    }
    $stmt = $db->prepare(
        "UPDATE emcore_companies SET name_fa = :name_fa, legal_type = :legal_type,
            registration_number = :registration_number, national_id = :national_id,
            phone = :phone, updated_at = NOW()
         WHERE id = :id AND deleted_at IS NULL"
    );
    $values[':id'] = $id;
    $stmt->execute($values);
    $afterStmt = $db->prepare('SELECT * FROM emcore_companies WHERE id = :id LIMIT 1');
    $afterStmt->execute([':id' => $id]);
    emcore_audit('companies', 'update', 'company', $id, $before, $afterStmt->fetch());
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
emcore_json(['success' => true]);
