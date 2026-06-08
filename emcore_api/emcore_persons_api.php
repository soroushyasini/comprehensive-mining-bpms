<?php
header('Content-Type: application/json; charset=utf-8');

try {
    $db = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=wf_pishro;charset=utf8mb4',
        'root', 'zxc123ASD456'
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── LIST (with companies) ─────────────────────────────────────────────
    case 'list':
        $stmt = $db->query("
            SELECT
                p.id, p.first_name, p.last_name, p.national_id,
                p.phone_mobile, p.birth_date_fa, p.is_active,
                GROUP_CONCAT(
                    DISTINCT c.name_fa
                    ORDER BY c.name_fa
                    SEPARATOR ' · '
                ) AS companies
            FROM emcore_persons p
            LEFT JOIN emcore_company_persons cp
                ON cp.person_id = p.id AND cp.deleted_at IS NULL AND cp.is_current = 1
            LEFT JOIN emcore_companies c
                ON c.id = cp.company_id AND c.deleted_at IS NULL
            WHERE p.deleted_at IS NULL
            GROUP BY p.id
            ORDER BY p.last_name, p.first_name
        ");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
        break;

    // ── GET ONE (full data + roles) ───────────────────────────────────────
    case 'get':
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("
            SELECT * FROM emcore_persons
            WHERE id = :id AND deleted_at IS NULL LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $person = $stmt->fetch();

        if (!$person) {
            echo json_encode(['success' => false, 'error' => 'شخص یافت نشد']);
            break;
        }

        // Get company roles
        $roles = $db->prepare("
            SELECT cp.role_type, cp.role_title, cp.start_date_fa,
                   cp.end_date_fa, cp.is_current, c.name_fa AS company_name
            FROM emcore_company_persons cp
            JOIN emcore_companies c ON c.id = cp.company_id
            WHERE cp.person_id = :id AND cp.deleted_at IS NULL
            ORDER BY cp.is_current DESC, cp.start_date_fa DESC
        ");
        $roles->execute([':id' => $id]);
        $person['roles'] = $roles->fetchAll();

        echo json_encode(['success' => true, 'data' => $person], JSON_UNESCAPED_UNICODE);
        break;

    // ── CREATE ────────────────────────────────────────────────────────────
    case 'create':
        $stmt = $db->prepare("
            INSERT INTO emcore_persons
                (first_name, last_name, national_id, id_number, father_name,
                 birth_date_fa, birth_place, education_degree, education_field,
                 education_university, phone_mobile, phone_secondary,
                 address, notes, is_active, created_at, updated_at)
            VALUES
                (:first_name, :last_name, :national_id, :id_number, :father_name,
                 :birth_date_fa, :birth_place, :education_degree, :education_field,
                 :education_university, :phone_mobile, :phone_secondary,
                 :address, :notes, 1, NOW(), NOW())
        ");
        $stmt->execute([
            ':first_name'           => trim($_POST['first_name']           ?? ''),
            ':last_name'            => trim($_POST['last_name']            ?? ''),
            ':national_id'          => trim($_POST['national_id']          ?? '') ?: null,
            ':id_number'            => trim($_POST['id_number']            ?? '') ?: null,
            ':father_name'          => trim($_POST['father_name']          ?? '') ?: null,
            ':birth_date_fa'        => trim($_POST['birth_date_fa']        ?? '') ?: null,
            ':birth_place'          => trim($_POST['birth_place']          ?? '') ?: null,
            ':education_degree'     => trim($_POST['education_degree']     ?? '') ?: null,
            ':education_field'      => trim($_POST['education_field']      ?? '') ?: null,
            ':education_university' => trim($_POST['education_university'] ?? '') ?: null,
            ':phone_mobile'         => trim($_POST['phone_mobile']         ?? '') ?: null,
            ':phone_secondary'      => trim($_POST['phone_secondary']      ?? '') ?: null,
            ':address'              => trim($_POST['address']              ?? '') ?: null,
            ':notes'                => trim($_POST['notes']                ?? '') ?: null,
        ]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        break;

    // ── UPDATE ────────────────────────────────────────────────────────────
    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("
            UPDATE emcore_persons SET
                first_name           = :first_name,
                last_name            = :last_name,
                national_id          = :national_id,
                id_number            = :id_number,
                father_name          = :father_name,
                birth_date_fa        = :birth_date_fa,
                birth_place          = :birth_place,
                education_degree     = :education_degree,
                education_field      = :education_field,
                education_university = :education_university,
                phone_mobile         = :phone_mobile,
                phone_secondary      = :phone_secondary,
                address              = :address,
                notes                = :notes,
                updated_at           = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':first_name'           => trim($_POST['first_name']           ?? ''),
            ':last_name'            => trim($_POST['last_name']            ?? ''),
            ':national_id'          => trim($_POST['national_id']          ?? '') ?: null,
            ':id_number'            => trim($_POST['id_number']            ?? '') ?: null,
            ':father_name'          => trim($_POST['father_name']          ?? '') ?: null,
            ':birth_date_fa'        => trim($_POST['birth_date_fa']        ?? '') ?: null,
            ':birth_place'          => trim($_POST['birth_place']          ?? '') ?: null,
            ':education_degree'     => trim($_POST['education_degree']     ?? '') ?: null,
            ':education_field'      => trim($_POST['education_field']      ?? '') ?: null,
            ':education_university' => trim($_POST['education_university'] ?? '') ?: null,
            ':phone_mobile'         => trim($_POST['phone_mobile']         ?? '') ?: null,
            ':phone_secondary'      => trim($_POST['phone_secondary']      ?? '') ?: null,
            ':address'              => trim($_POST['address']              ?? '') ?: null,
            ':notes'                => trim($_POST['notes']                ?? '') ?: null,
            ':id'                   => $id,
        ]);
        echo json_encode(['success' => true]);
        break;

    // ── DELETE (soft) ─────────────────────────────────────────────────────
    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("
            UPDATE emcore_persons
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ")->execute([':id' => $id]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'action نامعتبر']);
}
