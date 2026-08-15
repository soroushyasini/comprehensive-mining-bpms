<?php

require_once __DIR__ . '/_module_permissions.php';

$action = emcore_action(['list', 'get', 'create', 'update', 'delete']);
$capability = ['list' => 'read', 'get' => 'read', 'create' => 'create', 'update' => 'update', 'delete' => 'delete'][$action];
emcore_require_permission('persons', $capability);
$db = emcore_db();

if ($action === 'list') {
    $stmt = $db->query(
        "SELECT p.id, p.first_name, p.last_name, p.national_id, p.phone_mobile,
                p.birth_date_fa, p.is_active,
                GROUP_CONCAT(DISTINCT c.name_fa ORDER BY c.name_fa SEPARATOR ' · ') AS companies
         FROM emcore_persons p
         LEFT JOIN emcore_company_persons cp
            ON cp.person_id = p.id AND cp.deleted_at IS NULL AND cp.is_current = 1
         LEFT JOIN emcore_companies c ON c.id = cp.company_id AND c.deleted_at IS NULL
         WHERE p.deleted_at IS NULL GROUP BY p.id ORDER BY p.last_name, p.first_name"
    );
    emcore_json(['success' => true, 'data' => $stmt->fetchAll(), 'csrf_token' => emcore_csrf_token(), 'permissions' => emcore_module_permissions('persons')]);
}
if ($action === 'get') {
    $id = emcore_positive_id('id');
    $stmt = $db->prepare('SELECT * FROM emcore_persons WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $id]);
    $person = $stmt->fetch();
    if (!$person) {
        throw new EmcoreHttpException(404, 'شخص یافت نشد');
    }
    $roles = $db->prepare(
        "SELECT cp.role_type, cp.role_title, cp.start_date_fa, cp.end_date_fa,
                cp.is_current, c.name_fa AS company_name
         FROM emcore_company_persons cp
         JOIN emcore_companies c ON c.id = cp.company_id AND c.deleted_at IS NULL
         WHERE cp.person_id = :id AND cp.deleted_at IS NULL
         ORDER BY cp.is_current DESC, cp.start_date_fa DESC"
    );
    $roles->execute([':id' => $id]);
    $person['roles'] = $roles->fetchAll();
    emcore_json(['success' => true, 'data' => $person]);
}

emcore_require_csrf();

if ($action === 'delete') {
    $id = emcore_positive_id('id');
    $db->beginTransaction();
    try {
        $select = $db->prepare('SELECT * FROM emcore_persons WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        $select->execute([':id' => $id]);
        $before = $select->fetch();
        if (!$before) {
            throw new EmcoreHttpException(404, 'شخص یافت نشد');
        }
        $db->prepare('UPDATE emcore_persons SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id')->execute([':id' => $id]);
        $afterStmt = $db->prepare('SELECT * FROM emcore_persons WHERE id = :id LIMIT 1');
        $afterStmt->execute([':id' => $id]);
        emcore_audit('persons', 'delete', 'person', $id, $before, $afterStmt->fetch());
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
    emcore_json(['success' => true]);
}

$fields = [
    'first_name' => emcore_string('first_name', true, 100),
    'last_name' => emcore_string('last_name', true, 100),
    'national_id' => emcore_string('national_id', false, 20),
    'id_number' => emcore_string('id_number', false, 30),
    'father_name' => emcore_string('father_name', false, 100),
    'birth_date_fa' => emcore_string('birth_date_fa', false, 10),
    'birth_place' => emcore_string('birth_place', false, 100),
    'education_degree' => emcore_string('education_degree', false, 100),
    'education_field' => emcore_string('education_field', false, 150),
    'education_university' => emcore_string('education_university', false, 150),
    'phone_mobile' => emcore_string('phone_mobile', false, 30),
    'phone_secondary' => emcore_string('phone_secondary', false, 30),
    'address' => emcore_string('address', false, 1000),
    'notes' => emcore_string('notes', false, 5000),
];
if ($fields['birth_date_fa'] !== null &&
    !preg_match('/^1[34][0-9]{2}\/(0[1-9]|1[0-2])\/([0-2][0-9]|3[01])$/', $fields['birth_date_fa'])) {
    throw new EmcoreHttpException(422, 'تاریخ تولد باید با قالب YYYY/MM/DD باشد');
}
$params = array_combine(
    array_map(function ($key) { return ':' . $key; }, array_keys($fields)),
    array_values($fields)
);

if ($action === 'create') {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO emcore_persons
                (first_name, last_name, national_id, id_number, father_name, birth_date_fa,
                 birth_place, education_degree, education_field, education_university,
                 phone_mobile, phone_secondary, address, notes, is_active, created_at, updated_at)
             VALUES
                (:first_name, :last_name, :national_id, :id_number, :father_name, :birth_date_fa,
                 :birth_place, :education_degree, :education_field, :education_university,
                 :phone_mobile, :phone_secondary, :address, :notes, 1, NOW(), NOW())"
        );
        $stmt->execute($params);
        $id = (int)$db->lastInsertId();
        $afterStmt = $db->prepare('SELECT * FROM emcore_persons WHERE id = :id LIMIT 1');
        $afterStmt->execute([':id' => $id]);
        emcore_audit('persons', 'create', 'person', $id, null, $afterStmt->fetch());
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
    $select = $db->prepare('SELECT * FROM emcore_persons WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
    $select->execute([':id' => $id]);
    $before = $select->fetch();
    if (!$before) {
        throw new EmcoreHttpException(404, 'شخص یافت نشد');
    }
    $assignments = [];
    foreach (array_keys($fields) as $field) {
        $assignments[] = $field . ' = :' . $field;
    }
    $stmt = $db->prepare(
        'UPDATE emcore_persons SET ' . implode(', ', $assignments) .
        ', updated_at = NOW() WHERE id = :id AND deleted_at IS NULL'
    );
    $params[':id'] = $id;
    $stmt->execute($params);
    $afterStmt = $db->prepare('SELECT * FROM emcore_persons WHERE id = :id LIMIT 1');
    $afterStmt->execute([':id' => $id]);
    emcore_audit('persons', 'update', 'person', $id, $before, $afterStmt->fetch());
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
emcore_json(['success' => true]);
