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

// ── Router ────────────────────────────────────────────────────────────────
switch ($action) {

    // ── LIST ──────────────────────────────────────────────────────────────
    case 'list':
        $stmt = $db->query("
            SELECT id, name_fa, legal_type, registration_number,
                   national_id, phone, is_active
            FROM emcore_companies
            WHERE deleted_at IS NULL
            ORDER BY name_fa
        ");
        echo json_encode([
            'success' => true,
            'data'    => $stmt->fetchAll()
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ── GET ONE (for edit pre-fill) ───────────────────────────────────────
    case 'get':
        $id   = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("
            SELECT * FROM emcore_companies
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

    // ── CREATE ────────────────────────────────────────────────────────────
    case 'create':
        $stmt = $db->prepare("
            INSERT INTO emcore_companies
                (name_fa, legal_type, registration_number, national_id, phone, is_active, created_at, updated_at)
            VALUES
                (:name_fa, :legal_type, :reg_number, :national_id, :phone, 1, NOW(), NOW())
        ");
        $stmt->execute([
            ':name_fa'    => trim($_POST['name_fa']    ?? ''),
            ':legal_type' => trim($_POST['legal_type'] ?? ''),
            ':reg_number' => trim($_POST['reg_number'] ?? ''),
            ':national_id'=> trim($_POST['national_id']?? ''),
            ':phone'      => trim($_POST['phone']      ?? ''),
        ]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        break;

    // ── UPDATE ────────────────────────────────────────────────────────────
    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("
            UPDATE emcore_companies SET
                name_fa             = :name_fa,
                legal_type          = :legal_type,
                registration_number = :reg_number,
                national_id         = :national_id,
                phone               = :phone,
                updated_at          = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':name_fa'    => trim($_POST['name_fa']    ?? ''),
            ':legal_type' => trim($_POST['legal_type'] ?? ''),
            ':reg_number' => trim($_POST['reg_number'] ?? ''),
            ':national_id'=> trim($_POST['national_id']?? ''),
            ':phone'      => trim($_POST['phone']      ?? ''),
            ':id'         => $id,
        ]);
        echo json_encode(['success' => true]);
        break;

    // ── DELETE (soft) ─────────────────────────────────────────────────────
    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("
            UPDATE emcore_companies
            SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ")->execute([':id' => $id]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'action نامعتبر']);
}
