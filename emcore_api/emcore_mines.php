<?php

require_once __DIR__ . '/_module_permissions.php';

function emcore_mine_decimal($name)
{
    $raw = isset($_POST[$name]) ? trim((string)$_POST[$name]) : '';
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $raw)) {
        throw new EmcoreHttpException(422, 'مقدار عددی نامعتبر است', [$name => 'decimal_15_2_required']);
    }
    return $raw;
}

function emcore_mine_jalali_date($name)
{
    $date = emcore_string($name, false, 10);
    if ($date !== null && !preg_match('/^1[34][0-9]{2}\/(0[1-9]|1[0-2])\/([0-2][0-9]|3[01])$/', $date)) {
        throw new EmcoreHttpException(422, 'تاریخ باید با قالب YYYY/MM/DD باشد', [$name => 'invalid_jalali_date']);
    }
    return $date;
}

$action = emcore_action(['list', 'get', 'companies', 'create', 'update', 'delete']);
$capability = in_array($action, ['list', 'get', 'companies'], true) ? 'read' : $action;
emcore_require_permission('mines', $capability);
$db = emcore_db();

if ($action === 'list') {
    $stmt = $db->query(
        "SELECT m.id, m.mine_name, m.mineral_type, m.status, m.license_number,
                m.cadastre_code, m.alias_name, m.annual_extraction_tons, m.company_id,
                c.name_fa AS company_name
         FROM emcore_mines m
         JOIN emcore_companies c ON c.id = m.company_id AND c.deleted_at IS NULL
         WHERE m.deleted_at IS NULL
         ORDER BY m.mine_name"
    );
    emcore_json(['success' => true, 'data' => $stmt->fetchAll(), 'csrf_token' => emcore_csrf_token(), 'permissions' => emcore_module_permissions('mines')]);
}

if ($action === 'get') {
    $id = emcore_positive_id('id');
    $stmt = $db->prepare('SELECT * FROM emcore_mines WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new EmcoreHttpException(404, 'معدن یافت نشد');
    }
    emcore_json(['success' => true, 'data' => $row]);
}

if ($action === 'companies') {
    $stmt = $db->query(
        'SELECT id, name_fa FROM emcore_companies WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name_fa'
    );
    emcore_json(['success' => true, 'data' => $stmt->fetchAll(), 'csrf_token' => emcore_csrf_token(), 'permissions' => emcore_module_permissions('mines')]);
}

emcore_require_csrf();

if ($action === 'delete') {
    $id = emcore_positive_id('id');
    $db->beginTransaction();
    try {
        $select = $db->prepare('SELECT * FROM emcore_mines WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
        $select->execute([':id' => $id]);
        $before = $select->fetch();
        if (!$before) {
            throw new EmcoreHttpException(404, 'معدن یافت نشد');
        }
        $activeManager = $db->prepare(
            'SELECT id FROM emcore_mine_technical_managers WHERE mine_id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $activeManager->execute([':id' => $id]);
        if ($activeManager->fetch()) {
            throw new EmcoreHttpException(409, 'این معدن دارای مسئول فنی ثبت‌شده است و قابل حذف نیست');
        }
        $db->prepare('UPDATE emcore_mines SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $id]);
        $afterStmt = $db->prepare('SELECT * FROM emcore_mines WHERE id = :id LIMIT 1');
        $afterStmt->execute([':id' => $id]);
        emcore_audit('mines', 'delete', 'mine', $id, $before, $afterStmt->fetch());
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    emcore_json(['success' => true]);
}

$companyId = emcore_positive_id('company_id');
$mineName = emcore_string('mine_name', true, 255);
$mineralType = emcore_string('mineral_type', true, 100);
$status = emcore_string('status', true, 100);
$allowedStatuses = ['دارای پروانه بهره برداری', 'در حال تمدید', 'تعیین تکلیف', 'در حال دریافت گواهی کشف', 'منقضی'];
if (!in_array($status, $allowedStatuses, true)) {
    throw new EmcoreHttpException(422, 'وضعیت معدن نامعتبر است');
}
$licenseDateFa = emcore_mine_jalali_date('license_date_fa');
$licenseValidityFa = emcore_mine_jalali_date('license_validity_fa');
$guaranteeLetterDateFa = emcore_mine_jalali_date('guarantee_letter_date_fa');

$company = $db->prepare(
    'SELECT id FROM emcore_companies WHERE id = :id AND deleted_at IS NULL AND is_active = 1 LIMIT 1'
);
$company->execute([':id' => $companyId]);
if (!$company->fetch()) {
    throw new EmcoreHttpException(422, 'شرکت فعال یافت نشد');
}

$values = [
    ':company_id' => $companyId,
    ':mine_name' => $mineName,
    ':mineral_type' => $mineralType,
    ':status' => $status,
    ':license_number' => emcore_string('license_number', false, 50),
    ':license_date_fa' => $licenseDateFa,
    ':license_validity_fa' => $licenseValidityFa,
    ':license_validity_fa_derived' => $licenseValidityFa,
    ':proven_reserve_tons' => emcore_mine_decimal('proven_reserve_tons'),
    ':probable_reserve_tons' => emcore_mine_decimal('probable_reserve_tons'),
    ':annual_extraction_tons' => emcore_mine_decimal('annual_extraction_tons'),
    ':cutoff_grade' => emcore_string('cutoff_grade', false, 20),
    ':average_grade' => emcore_string('average_grade', false, 20),
    ':cadastre_code' => emcore_string('cadastre_code', false, 50),
    ':guarantee_letter_date_fa' => $guaranteeLetterDateFa,
    ':ore_subtype' => emcore_string('ore_subtype', false, 100),
    ':alias_name' => emcore_string('alias_name', false, 255),
    ':notes' => emcore_string('notes', false, 5000),
];

if ($action === 'create') {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO emcore_mines
                (company_id, mine_name, mineral_type, status, license_number,
                 license_date_fa, license_validity_fa, license_validity_en,
                 proven_reserve_tons, probable_reserve_tons, annual_extraction_tons,
                 cutoff_grade, average_grade, cadastre_code, guarantee_letter_date_fa,
                 ore_subtype, alias_name, notes, created_at, updated_at)
             VALUES
                (:company_id, :mine_name, :mineral_type, :status, :license_number,
                 :license_date_fa, :license_validity_fa,
                 shamsi_slash_to_gregorian_date(:license_validity_fa_derived),
                 :proven_reserve_tons, :probable_reserve_tons, :annual_extraction_tons,
                 :cutoff_grade, :average_grade, :cadastre_code, :guarantee_letter_date_fa,
                 :ore_subtype, :alias_name, :notes, NOW(), NOW())"
        );
        $stmt->execute($values);
        $id = (int)$db->lastInsertId();
        $afterStmt = $db->prepare('SELECT * FROM emcore_mines WHERE id = :id LIMIT 1');
        $afterStmt->execute([':id' => $id]);
        emcore_audit('mines', 'create', 'mine', $id, null, $afterStmt->fetch());
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
    emcore_json(['success' => true, 'id' => $id], 201);
}

$id = emcore_positive_id('id');
$db->beginTransaction();
try {
    $select = $db->prepare('SELECT * FROM emcore_mines WHERE id = :id AND deleted_at IS NULL LIMIT 1 FOR UPDATE');
    $select->execute([':id' => $id]);
    $before = $select->fetch();
    if (!$before) {
        throw new EmcoreHttpException(404, 'معدن یافت نشد');
    }
    $stmt = $db->prepare(
        "UPDATE emcore_mines SET
            company_id = :company_id, mine_name = :mine_name, mineral_type = :mineral_type,
            status = :status, license_number = :license_number,
            license_date_fa = :license_date_fa, license_validity_fa = :license_validity_fa,
            license_validity_en = shamsi_slash_to_gregorian_date(:license_validity_fa_derived),
            proven_reserve_tons = :proven_reserve_tons,
            probable_reserve_tons = :probable_reserve_tons,
            annual_extraction_tons = :annual_extraction_tons,
            cutoff_grade = :cutoff_grade, average_grade = :average_grade,
            cadastre_code = :cadastre_code,
            guarantee_letter_date_fa = :guarantee_letter_date_fa,
            ore_subtype = :ore_subtype, alias_name = :alias_name, notes = :notes,
            updated_at = NOW()
         WHERE id = :id AND deleted_at IS NULL"
    );
    $values[':id'] = $id;
    $stmt->execute($values);
    $afterStmt = $db->prepare('SELECT * FROM emcore_mines WHERE id = :id LIMIT 1');
    $afterStmt->execute([':id' => $id]);
    emcore_audit('mines', 'update', 'mine', $id, $before, $afterStmt->fetch());
    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $exception;
}
emcore_json(['success' => true]);
