<?php
// ============================================
// MEW ON CLAW - List Creations
// File: api/list.php
// ============================================

require_once 'config.php';

header('Content-Type: application/json');

$userEmail = $_SESSION['user_email'] ?? 'anonymous';

try {
    $stmt = db()->prepare("
        SELECT id, title, prompt, mode, created_at
        FROM creations
        WHERE user_email = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$userEmail]);
    $creations = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'creations' => $creations
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'creations' => []]);
}
