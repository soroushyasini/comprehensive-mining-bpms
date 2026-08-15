<?php

require_once __DIR__ . '/_module_permissions.php';

$action = emcore_action(['list', 'get', 'mines', 'create', 'update', 'delete']);
$capability = in_array($action, ['list', 'get', 'mines'], true) ? 'read' : $action;
emcore_require_permission('mine_technical_managers', $capability);
$db = emcore_db();

if ($action === 'list') {
    $stmt = $db->query(
        "SELECT t.id, t.mine_id, t.person_id, t.full_name, t.phone, t.contact_method,
                t.contract_date_fa, t.contract_validity_fa, t.contract_validity_en,
                t.contract_amount, t.payment_schedule, t.is_current, t.notes, m.mine_name
         FROM emcore_mine_technical_managers t
         JOIN emcore_mines m ON m.id = t.mine_id AND m.deleted_at IS NULL
         WHERE t.deleted_at IS NULL ORDER BY t.full_name"
    );
    emcore_json(['success' => true, 'data' => $stmt->fetchAll(), 'csrf_token' => emcore_csrf_token(), 'permissions' => emcore_module_permissions('mine_technical_managers')]);
}
if ($action === 'get') {
    $id = emcore_positive_id('id');
    $stmt = $db->prepare('SELECT * FROM emcore_mine_technical_managers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) throw new EmcoreHttpException(404, 'مسئول فنی یافت نشد');
    emcore_json(['success' => true, 'data' => $row]);
}
if ($action === 'mines') {
    $stmt = $db->query('SELECT id, mine_name FROM emcore_mines WHERE deleted_at IS NULL ORDER BY mine_name');
    emcore_json(['success' => true, 'data' => $stmt->fetchAll()]);
}

emcore_require_csrf();

if ($action === 'delete') {
    $id = emcore_positive_id('id');
    $db->beginTransaction();
    try {
        $select = $db->prepare('SELECT * FROM emcore_mine_technical_managers WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        $select->execute([':id' => $id]);
        $before = $select->fetch();
        if (!$before) throw new EmcoreHttpException(404, 'مسئول فنی یافت نشد');
        $db->prepare('UPDATE emcore_mine_technical_managers SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id')->execute([':id' => $id]);
        $afterStmt = $db->prepare('SELECT * FROM emcore_mine_technical_managers WHERE id = :id LIMIT 1');
        $afterStmt->execute([':id' => $id]);
        emcore_audit('mine_technical_managers', 'delete', 'mine_technical_manager', $id, $before, $afterStmt->fetch());
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
    emcore_json(['success' => true]);
}

$mineId = emcore_positive_id('mine_id');
$rawPersonId = isset($_POST['person_id']) ? trim((string)$_POST['person_id']) : '';
$personId = null;
if ($rawPersonId !== '') {
    if (!ctype_digit($rawPersonId) || (int)$rawPersonId < 1) {
        throw new EmcoreHttpException(422, 'شناسه شخص نامعتبر است');
    }
    $personId = (int)$rawPersonId;
}
$fullName = emcore_string('full_name', $personId === null, 255);
$contractDateFa = emcore_string('contract_date_fa', false, 10);
$contractValidityFa = emcore_string('contract_validity_fa', false, 10);
$fields = [
    ':mine_id' => $mineId,
    ':person_id' => $personId,
    ':full_name' => $fullName,
    ':phone' => emcore_string('phone', false, 20),
    ':contact_method' => emcore_string('contact_method', false, 50),
    ':contract_date_fa' => $contractDateFa,
    ':contract_validity_fa' => $contractValidityFa,
    ':contract_validity_fa_derived' => $contractValidityFa,
    ':contract_amount' => emcore_string('contract_amount', false, 255),
    ':payment_schedule' => emcore_string('payment_schedule', false, 100),
    ':is_current' => emcore_post_bool('is_current'),
    ':notes' => emcore_string('notes', false, 5000),
];
foreach (['contract_date_fa' => $contractDateFa, 'contract_validity_fa' => $contractValidityFa] as $field => $date) {
    if ($date !== null && !preg_match('/^1[34][0-9]{2}\/(0[1-9]|1[0-2])\/([0-2][0-9]|3[01])$/', $date)) {
        throw new EmcoreHttpException(422, 'تاریخ باید با قالب YYYY/MM/DD باشد', [$field => 'invalid_jalali_date']);
    }
}
$mine = $db->prepare('SELECT id FROM emcore_mines WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$mine->execute([':id' => $mineId]);
if (!$mine->fetch()) throw new EmcoreHttpException(422, 'معدن فعال یافت نشد');
if ($personId !== null) {
    $person = $db->prepare('SELECT id FROM emcore_persons WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $person->execute([':id' => $personId]);
    if (!$person->fetch()) throw new EmcoreHttpException(422, 'شخص فعال یافت نشد');
}

if ($action === 'create') {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO emcore_mine_technical_managers
                (mine_id, person_id, full_name, phone, contact_method, contract_date_fa,
                 contract_validity_fa, contract_validity_en, contract_amount,
                 payment_schedule, is_current, notes, created_at, updated_at)
             VALUES
                (:mine_id, :person_id, :full_name, :phone, :contact_method, :contract_date_fa,
                 :contract_validity_fa, shamsi_slash_to_gregorian_date(:contract_validity_fa_derived),
                 :contract_amount, :payment_schedule, :is_current, :notes, NOW(), NOW())"
        );
        $stmt->execute($fields);
        $id = (int)$db->lastInsertId();
        $afterStmt = $db->prepare('SELECT * FROM emcore_mine_technical_managers WHERE id = :id LIMIT 1');
        $afterStmt->execute([':id' => $id]);
        emcore_audit('mine_technical_managers', 'create', 'mine_technical_manager', $id, null, $afterStmt->fetch());
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
    $select = $db->prepare('SELECT * FROM emcore_mine_technical_managers WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
    $select->execute([':id' => $id]);
    $before = $select->fetch();
    if (!$before) throw new EmcoreHttpException(404, 'مسئول فنی یافت نشد');
    $stmt = $db->prepare(
        "UPDATE emcore_mine_technical_managers SET
            mine_id = :mine_id, person_id = :person_id, full_name = :full_name,
            phone = :phone, contact_method = :contact_method, contract_date_fa = :contract_date_fa,
            contract_validity_fa = :contract_validity_fa,
            contract_validity_en = shamsi_slash_to_gregorian_date(:contract_validity_fa_derived),
            contract_amount = :contract_amount, payment_schedule = :payment_schedule,
            is_current = :is_current, notes = :notes, updated_at = NOW()
         WHERE id = :id AND deleted_at IS NULL"
    );
    $fields[':id'] = $id;
    $stmt->execute($fields);
    $afterStmt = $db->prepare('SELECT * FROM emcore_mine_technical_managers WHERE id = :id LIMIT 1');
    $afterStmt->execute([':id' => $id]);
    emcore_audit('mine_technical_managers', 'update', 'mine_technical_manager', $id, $before, $afterStmt->fetch());
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
emcore_json(['success' => true]);
