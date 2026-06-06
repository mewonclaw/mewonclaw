<?php
/**
 * MEW ON CLAW - AI Builds CRUD
 * 
 * GET  /api/builds.php                    → list public builds
 * GET  /api/builds.php?slug=moodboardgpt  → get one build
 * POST /api/builds.php (auth)             → create new
 * PUT  /api/builds.php?id=1 (auth)        → update own
 * DELETE /api/builds.php?id=1 (auth)      → delete own
 */

require_once 'config.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// =====================================
// GET (LIST or SINGLE)
// =====================================
if ($method === 'GET') {
    $slug = $_GET['slug'] ?? '';
    $type = $_GET['type'] ?? '';
    $limit = min(50, intval($_GET['limit'] ?? 20));
    
    // Single build by slug
    if ($slug) {
        $stmt = $db->prepare('
            SELECT b.*, u.username, u.display_name 
            FROM ai_builds b 
            JOIN users u ON u.id = b.user_id 
            WHERE b.slug = ? AND b.is_public = 1
        ');
        $stmt->execute([$slug]);
        $build = $stmt->fetch();
        
        if (!$build) jsonError('Not found', 404);
        jsonSuccess(['build' => $build]);
    }
    
    // List builds (with optional type filter)
    $sql = '
        SELECT b.*, u.username, u.display_name 
        FROM ai_builds b 
        JOIN users u ON u.id = b.user_id 
        WHERE b.is_public = 1
    ';
    $params = [];
    
    if (in_array($type, ['assistant', 'agent', 'tool', 'workflow'])) {
        $sql .= ' AND b.type = ?';
        $params[] = $type;
    }
    
    $sql .= ' ORDER BY b.is_featured DESC, b.total_runs DESC LIMIT ' . $limit;
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $builds = $stmt->fetchAll();
    
    jsonSuccess(['builds' => $builds]);
}

// =====================================
// POST (CREATE)
// =====================================
if ($method === 'POST') {
    $user = requireAuth();
    $input = getInput();
    
    $name = trim($input['name'] ?? '');
    $type = $input['type'] ?? 'assistant';
    $description = trim($input['description'] ?? '');
    $systemPrompt = $input['system_prompt'] ?? '';
    
    if (empty($name)) jsonError('Name required');
    if (!in_array($type, ['assistant', 'agent', 'tool', 'workflow'])) {
        jsonError('Invalid type');
    }
    
    // Generate slug
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
    $slug = trim($slug, '-');
    
    // Check unique slug
    $count = 0;
    $finalSlug = $slug;
    while (true) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM ai_builds WHERE slug = ?');
        $stmt->execute([$finalSlug]);
        if ($stmt->fetchColumn() == 0) break;
        $count++;
        $finalSlug = $slug . '-' . $count;
    }
    
    $stmt = $db->prepare('
        INSERT INTO ai_builds (user_id, slug, name, type, description, system_prompt)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $user['id'], $finalSlug, $name, $type, $description, $systemPrompt
    ]);
    
    $buildId = $db->lastInsertId();
    
    $stmt = $db->prepare('SELECT * FROM ai_builds WHERE id = ?');
    $stmt->execute([$buildId]);
    
    jsonSuccess(['build' => $stmt->fetch()], 201);
}

// =====================================
// PUT (UPDATE)
// =====================================
if ($method === 'PUT') {
    $user = requireAuth();
    $input = getInput();
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) jsonError('ID required');
    
    // Check ownership
    $stmt = $db->prepare('SELECT * FROM ai_builds WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    $build = $stmt->fetch();
    
    if (!$build) jsonError('Not found or not yours', 404);
    
    $fields = [];
    $values = [];
    
    if (isset($input['name'])) { $fields[] = 'name = ?'; $values[] = $input['name']; }
    if (isset($input['description'])) { $fields[] = 'description = ?'; $values[] = $input['description']; }
    if (isset($input['system_prompt'])) { $fields[] = 'system_prompt = ?'; $values[] = $input['system_prompt']; }
    if (isset($input['is_public'])) { $fields[] = 'is_public = ?'; $values[] = $input['is_public'] ? 1 : 0; }
    
    if (empty($fields)) jsonError('Nothing to update');
    
    $values[] = $id;
    $sql = 'UPDATE ai_builds SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $db->prepare($sql)->execute($values);
    
    jsonSuccess(['message' => 'Updated']);
}

// =====================================
// DELETE
// =====================================
if ($method === 'DELETE') {
    $user = requireAuth();
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) jsonError('ID required');
    
    $stmt = $db->prepare('DELETE FROM ai_builds WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['id']]);
    
    if ($stmt->rowCount() === 0) jsonError('Not found or not yours', 404);
    
    jsonSuccess(['message' => 'Deleted']);
}

jsonError('Method not allowed', 405);
