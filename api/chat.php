<?php
/**
 * MEW ON CLAW - Claude AI Chat Endpoint (FIXED)
 * 
 * Compatible dengan format dashboard.html:
 *   POST /api/chat.php
 *   Body: { 
 *     mode: 'craft' | 'assistant' | 'agent' | 'tool' | 'workflow',
 *     messages: [{role, content}, ...]
 *   }
 * 
 * AUTHENTICATION REQUIRED — user must be signed in.
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('POST required', 405);
}

// ============= REQUIRE AUTH =============
$user = requireAuth();
$userId = $user['id'];

// ============= GET INPUT =============
$input = getInput();
$mode = trim($input['mode'] ?? 'assistant');
$messages = $input['messages'] ?? [];
$buildId = intval($input['ai_build_id'] ?? 0);

// Validate
if (!is_array($messages) || empty($messages)) {
    jsonError('Messages required');
}

// Get the last user message
$lastUserMsg = '';
foreach (array_reverse($messages) as $m) {
    if (($m['role'] ?? '') === 'user') {
        $lastUserMsg = $m['content'] ?? '';
        break;
    }
}

if (empty($lastUserMsg)) {
    jsonError('No user message found');
}

// ============= SYSTEM PROMPT BY MODE =============
$systemPrompts = [
    'craft' => "You are MEW ON CLAW Craft Engine — an HTML generator that ships fast.

CRITICAL OUTPUT FORMAT:
1. Start with ONE friendly sentence (under 15 words).
2. Then ALWAYS output a complete HTML page in a ```html code block.
3. End with ONE sentence describing what was built.

The HTML must:
- Be a complete document (DOCTYPE through </html>)
- Use Tailwind via CDN: <script src=\"https://cdn.tailwindcss.com\"></script>
- Be visually striking (gradients, animations, hover states, micro-interactions)
- Be fully self-contained (all CSS/JS inline)
- Be production-quality, not a skeleton
- Use lime #54BBF6 + pink #FFE600 + black as accent colors when fitting the brief
- Include thoughtful touches: scroll animations, hover effects, custom cursors, etc.

EXAMPLE OUTPUT:
Here's your landing page.

```html
<!DOCTYPE html>
<html>
...complete HTML...
</html>
```

Built with bold typography and scroll animations.

NEVER explain code in detail. NEVER repeat the user's brief. JUST SHIP THE HTML.",

    'assistant' => "You are a helpful, friendly AI assistant powered by MEW ON CLAW. 
Answer questions clearly and concisely. Be warm but professional.",

    'agent' => "You are an autonomous AI agent. You execute tasks by breaking them into steps, 
making decisions, and taking actions. Be methodical and explain your reasoning.",

    'tool' => "You are a single-purpose AI tool. Take input, transform it according to your purpose, 
and return clean structured output. Be precise and efficient.",

    'workflow' => "You are a multi-step AI workflow coordinator. Process inputs through stages, 
chain operations, and produce structured results.",
];

$systemPrompt = $systemPrompts[$mode] ?? $systemPrompts['assistant'];

// Craft mode butuh lebih banyak tokens karena generate HTML lengkap
$maxTokens = ($mode === 'craft') ? 8192 : CLAUDE_MAX_TOKENS;

// Override with custom prompt if AI build specified
if ($buildId > 0) {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM ai_builds WHERE id = ?');
    $stmt->execute([$buildId]);
    $build = $stmt->fetch();
    if ($build && !empty($build['system_prompt'])) {
        $systemPrompt = $build['system_prompt'];
    }
}

// ============= BUILD MESSAGES FOR CLAUDE =============
$claudeMessages = [];
foreach ($messages as $msg) {
    $role = $msg['role'] ?? '';
    $content = $msg['content'] ?? '';
    if (in_array($role, ['user', 'assistant']) && !empty($content)) {
        $claudeMessages[] = [
            'role' => $role,
            'content' => substr($content, 0, 8000),
        ];
    }
}

if (empty($claudeMessages)) {
    jsonError('No valid messages');
}

// ============= CALL CLAUDE API =============
$payload = [
    'model' => CLAUDE_MODEL,
    'max_tokens' => $maxTokens,
    'system' => $systemPrompt,
    'messages' => $claudeMessages,
];

$start = microtime(true);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT => 90,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

$durationMs = round((microtime(true) - $start) * 1000);

if ($curlErr) {
    error_log('Claude API curl error: ' . $curlErr);
    jsonError('AI service unavailable: ' . $curlErr, 503);
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
    error_log('Claude API error: ' . $errMsg . ' | Response: ' . $response);
    jsonError('AI error: ' . $errMsg, 500);
}

// ============= EXTRACT REPLY =============
$rawReply = '';
foreach ($data['content'] ?? [] as $block) {
    if (($block['type'] ?? '') === 'text') {
        $rawReply .= $block['text'];
    }
}

// ============= EXTRACT HTML (for Craft mode) =============
$generatedHtml = null;
$cleanMessage = $rawReply;

if ($mode === 'craft') {
    // Strategy 1: Closed code block ```html ... ```
    if (preg_match('/```html\s*(.+?)\s*```/s', $rawReply, $matches)) {
        $generatedHtml = trim($matches[1]);
        $cleanMessage = trim(preg_replace('/```html\s*.+?\s*```/s', '', $rawReply));
    }
    // Strategy 2: Open code block ```html ... (truncated, no closing)
    elseif (preg_match('/```html\s*(.+)$/s', $rawReply, $matches)) {
        $generatedHtml = trim($matches[1]);
        $cleanMessage = trim(preg_replace('/```html\s*.+$/s', '', $rawReply));
    }
    // Strategy 3: Raw HTML without code fences (DOCTYPE through </html>)
    elseif (preg_match('/(<!DOCTYPE.+?<\/html>)/is', $rawReply, $matches)) {
        $generatedHtml = trim($matches[1]);
        $cleanMessage = trim(str_replace($matches[1], '', $rawReply));
    }
    // Strategy 4: Raw HTML truncated (DOCTYPE to end of response)
    elseif (preg_match('/(<!DOCTYPE.+)$/is', $rawReply, $matches)) {
        $generatedHtml = trim($matches[1]);
        $cleanMessage = trim(preg_replace('/<!DOCTYPE.+$/is', '', $rawReply));
    }
    
    // Auto-close common tags if HTML appears truncated
    if ($generatedHtml && !str_contains($generatedHtml, '</html>')) {
        // Attempt to auto-close
        if (!str_contains($generatedHtml, '</body>')) {
            $generatedHtml .= "\n</body>";
        }
        $generatedHtml .= "\n</html>";
    }
    
    // If clean message is empty or too short, give helpful default
    if (strlen(trim($cleanMessage)) < 10) {
        $cleanMessage = "✨ Built it! Check the preview on the right →";
    }
}

// ============= LOG RUN =============
if ($buildId > 0 || $mode === 'craft') {
    $db = getDB();
    $tokens = ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0);
    
    try {
        $stmt = $db->prepare('
            INSERT INTO ai_runs (ai_build_id, user_id, input, output, tokens_used, duration_ms, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $buildId > 0 ? $buildId : null,
            $userId,
            substr($lastUserMsg, 0, 1000),
            substr($rawReply, 0, 2000),
            $tokens,
            $durationMs,
            'success'
        ]);
        
        if ($buildId > 0) {
            $db->prepare('UPDATE ai_builds SET total_runs = total_runs + 1 WHERE id = ?')->execute([$buildId]);
        }
    } catch (Exception $e) {
        error_log('Log run failed: ' . $e->getMessage());
    }
}

// ============= RESPONSE =============
jsonSuccess([
    'message' => $cleanMessage,
    'raw' => $rawReply,
    'html' => $generatedHtml,
    'usage' => $data['usage'] ?? null,
    'duration_ms' => $durationMs,
]);
