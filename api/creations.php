<?php
/**
 * MEW ON CLAW - Personal Creations (Chat History) CRUD
 * 
 * Each "creation" = one conversation thread with AI
 * Auto-saved as user builds, can be reopened/renamed/deleted
 * 
 * GET    /api/creations.php             → list user's creations (auth)
 * GET    /api/creations.php?id=123      → get one creation with full messages (auth)
 * POST   /api/creations.php             → create new creation (auth)
 * PUT    /api/creations.php?id=123      → update creation (title, messages, html) (auth)
 * DELETE /api/creations.php?id=123      → delete creation (auth)
 */

require_once 'config.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$user = requireAuth();
$id = intval($_GET['id'] ?? 0);

// Ensure table exists (auto-create on first use)
$db->exec("
    CREATE TABLE IF NOT EXISTS user_creations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(200) DEFAULT 'Untitled creation',
        mode VARCHAR(20) DEFAULT 'craft',
        messages LONGTEXT,
        html_output LONGTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// =====================================
// GET — list creations OR get single
// =====================================
if ($method === 'GET') {
    if ($id > 0) {
        // GET single creation with full data
        $stmt = $db->prepare('
            SELECT id, title, mode, messages, html_output, created_at, updated_at
            FROM user_creations
            WHERE id = ? AND user_id = ?
        ');
        $stmt->execute([$id, $user['id']]);
        $creation = $stmt->fetch();
        
        if (!$creation) jsonError('Not found', 404);
        
        // Decode messages JSON
        $creation['messages'] = json_decode($creation['messages'], true) ?: [];
        
        jsonSuccess(['creation' => $creation]);
    }
    
    // LIST all user's creations (without messages for performance)
    $stmt = $db->prepare('
        SELECT id, title, mode, 
               LEFT(html_output, 100) AS html_preview,
               LENGTH(html_output) > 0 AS has_html,
               created_at, updated_at
        FROM user_creations
        WHERE user_id = ?
        ORDER BY updated_at DESC
        LIMIT 50
    ');
    $stmt->execute([$user['id']]);
    $creations = $stmt->fetchAll();
    
    jsonSuccess(['creations' => $creations]);
}

// =====================================
// POST — create new creation (returns ID)
// =====================================
if ($method === 'POST') {
    $input = getInput();
    
    $title = trim($input['title'] ?? '') ?: 'Untitled creation';
    $mode = $input['mode'] ?? 'craft';
    $messages = isset($input['messages']) ? json_encode($input['messages']) : '[]';
    $html = $input['html'] ?? '';
    
    if (!in_array($mode, ['craft', 'assistant', 'agent', 'tool', 'workflow'])) {
        $mode = 'craft';
    }
    
    // Truncate title if too long
    if (mb_strlen($title) > 200) {
        $title = mb_substr($title, 0, 197) . '...';
    }
    
    $stmt = $db->prepare('
        INSERT INTO user_creations (user_id, title, mode, messages, html_output)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$user['id'], $title, $mode, $messages, $html]);
    
    $newId = $db->lastInsertId();
    
    jsonSuccess([
        'creation' => [
            'id' => $newId,
            'title' => $title,
            'mode' => $mode
        ]
    ], 201);
}

// =====================================
// PUT — update creation (auto-save)
// =====================================
if ($method === 'PUT') {
    if (!$id) jsonError('ID required');
    
    // Verify ownership
    $stmt = $db->prepare('SELECT id FROM user_creations WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    if (!$stmt->fetch()) jsonError('Not found or not yours', 404);
    
    $input = getInput();
    $fields = [];
    $values = [];
    
    if (isset($input['title'])) {
        $title = trim($input['title']) ?: 'Untitled creation';
        if (mb_strlen($title) > 200) $title = mb_substr($title, 0, 197) . '...';
        $fields[] = 'title = ?';
        $values[] = $title;
    }
    
    if (isset($input['messages'])) {
        $fields[] = 'messages = ?';
        $values[] = json_encode($input['messages']);
    }
    
    if (isset($input['html'])) {
        $fields[] = 'html_output = ?';
        $values[] = $input['html'];
    }
    
    if (isset($input['mode']) && in_array($input['mode'], ['craft', 'assistant', 'agent', 'tool', 'workflow'])) {
        $fields[] = 'mode = ?';
        $values[] = $input['mode'];
    }
    
    if (empty($fields)) jsonError('Nothing to update');
    
    $values[] = $id;
    $sql = 'UPDATE user_creations SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $db->prepare($sql)->execute($values);
    
    jsonSuccess(['message' => 'Updated', 'id' => $id]);
}

// =====================================
// DELETE
// =====================================
if ($method === 'DELETE') {
    if (!$id) jsonError('ID required');
    
    $stmt = $db->prepare('DELETE FROM user_creations WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    
    if ($stmt->rowCount() === 0) jsonError('Not found or not yours', 404);
    
    jsonSuccess(['message' => 'Deleted']);
}

jsonError('Method not allowed', 405);
