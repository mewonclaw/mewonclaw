<?php
// ============================================
// MEW ON CLAW - Load Single Creation
// File: api/load.php?id=123
// ============================================

require_once 'config.php';

header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing ID']);
    exit;
}

$userEmail = $_SESSION['user_email'] ?? 'anonymous';

try {
    $stmt = db()->prepare("
        SELECT id, title, prompt, html_output, mode, created_at
        FROM creations
        WHERE id = ? AND user_email = ?
    ");
    $stmt->execute([$id, $userEmail]);
    $creation = $stmt->fetch();
    
    if ($creation) {
        echo json_encode(['success' => true, 'creation' => $creation]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
