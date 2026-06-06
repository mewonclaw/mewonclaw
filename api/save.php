<?php
// ============================================
// MEW ON CLAW - Save Creation
// File: api/save.php
// ============================================

require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['title']) || !isset($input['html'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$title = substr(trim($input['title']), 0, 200);
$prompt = substr($input['prompt'] ?? '', 0, 2000);
$html = $input['html'];
$mode = $input['mode'] ?? 'craft';
$userEmail = $_SESSION['user_email'] ?? 'anonymous';

try {
    $stmt = db()->prepare("
        INSERT INTO creations (user_email, title, prompt, html_output, mode, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userEmail, $title, $prompt, $html, $mode]);
    
    $id = db()->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'id' => $id,
        'message' => 'Creation saved'
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
