<?php
header('Content-Type: application/json; charset=utf-8');

// ── DB ────────────────────────────────────────────────────────────────────
try {
    $db = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=wf_pishro;charset=utf8mb4',
        'root',
        'zxc123ASD456'
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {

    // ── LIST (with mine name via JOIN) ────────────────────────────────────
    case 'list':
        $stmt = $db->query("
            SELECT
                t.id,
                t.mine_id,
                t.person_id,
                t.full_name,
                t.phone,
                t.contact_method,
                t.contract_date_fa,
                t.contract_validity_fa,
                t.contract_validity_en,
                t.contract_amount,
                t.payment_schedule,
                t.is_current,
                t.notes,
                m.mine_name
            FROM emcore_mine_technical_managers t
            LEFT JOIN emcore_mines m ON m.id = t.mine_id AND m.deleted_at IS NULL
            WHERE t.deleted_at IS NULL
            ORDER BY t.full_name
        ");
        echo json_encode([
            'success' => true,
            'data'    => $stmt->fetchAll()
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ── GET ONE ───────────────────────────────────────────────────────────
    case 'get':
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("
            SELECT * FROM emcore_mine_technical_managers
            WHERE id = :id AND deleted_at IS NULL LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'error' => 'یافت نشد']);
        }
        break;

    // ── MINES dropdown ────────────────────────────────────────────────────
    case 'mines':
        $stmt = $db->query("
            SELECT id, mine_name FROM emcore_mines
            WHERE deleted_at IS NULL
            ORDER BY mine_name
        ");
        echo json_encode([
            'success' => true,
            'data'    => $stmt->fetchAll()
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ── CREATE ────────────────────────────────────────────────────────────
    case 'create':
        $stmt = $db->prepare("
            INSERT INTO emcore_mine_technical_managers (
                mine_id, person_id, full_name, phone, contact_method,
                contract_date_fa, contract_validity_fa, contract_validity_en,
                contract_amount, payment_schedule, is_current, notes,
                created_at, updated_at
            ) VALUES (
                :mine_id, :person_id, :full_name, :phone, :contact_method,
                :contract_date_fa, :contract_validity_fa, :contract_validity_en,
                :contract_amount, :payment_schedule, :is_current, :notes,
                NOW(), NOW()
            )
        ");
        $stmt->execute([
            ':mine_id'              => (int)($_POST['mine_id'] ?? 0),
            ':person_id'            => ($_POST['person_id'] !== '' && $_POST['person_id'] !== null) ? (int)$_POST['person_id'] : null,
            ':full_name'            => trim($_POST['full_name']            ?? ''),
            ':phone'                => trim($_POST['phone']                ?? ''),
            ':contact_method'       => trim($_POST['contact_method']       ?? ''),
            ':contract_date_fa'     => trim($_POST['contract_date_fa']     ?? ''),
            ':contract_validity_fa' => trim($_POST['contract_validity_fa'] ?? ''),
            ':contract_validity_en' => trim($_POST['contract_validity_en'] ?? ''),
            ':contract_amount'      => trim($_POST['contract_amount']      ?? ''),
            ':payment_schedule'     => trim($_POST['payment_schedule']     ?? ''),
            ':is_current'           => isset($_POST['is_current']) && $_POST['is_current'] ? 1 : 0,
            ':notes'                => trim($_POST['notes']                ?? ''),
        ]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        break;

    // ── UPDATE ────────────────────────────────────────────────────────────
    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("
            UPDATE emcore_mine_technical_managers SET
                mine_id              = :mine_id,
                person_id            = :person_id,
                full_name            = :full_name,
                phone                = :phone,
                contact_method       = :contact_method,
                contract_date_fa     = :contract_date_fa,
                contract_validity_fa = :contract_validity_fa,
                contract_validity_en = :contract_validity_en,
                contract_amount      = :contract_amount,
                payment_schedule     = :payment_schedule,
                is_current           = :is_current,
                notes                = :notes,
                updated_at           = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':mine_id'              => (int)($_POST['mine_id'] ?? 0),
            ':person_id'            => ($_POST['person_id'] !== '' && $_POST['person_id'] !== null) ? (int)$_POST['person_id'] : null,
            ':full_name'            => trim($_POST['full_name']            ?? ''),
            ':phone'                => trim($_POST['phone']                ?? ''),
            ':contact_method'       => trim($_POST['contact_method']       ?? ''),
            ':contract_date_fa'     => trim($_POST['contract_date_fa']     ?? ''),
            ':contract_validity_fa' => trim($_POST['contract_validity_fa'] ?? ''),
            ':contract_validity_en' => trim($_POST['contract_validity_en'] ?? ''),
            ':contract_amount'      => trim($_POST['contract_amount']      ?? ''),
            ':payment_schedule'     => trim($_POST['payment_schedule']     ?? ''),
            ':is_current'           => isset($_POST['is_current']) && $_POST['is_current'] ? 1 : 0,
            ':notes'                => trim($_POST['notes']                ?? ''),
            ':id'                   => $id,
        ]);
        echo json_encode(['success' => true]);
        break;

    // ── DELETE (soft) ─────────────────────────────────────────────────────
    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("
            UPDATE emcore_mine_technical_managers
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ")->execute([':id' => $id]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'action نامعتبر']);
}
