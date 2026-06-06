<?php
/**
 * MEW ON CLAW - Public Chatbot Endpoint
 * Used for: FAQ page chatbot (no login required)
 * 
 * POST /api/chat-public.php
 * Body: { 
 *   messages: [{role, content}, ...]
 * }
 * 
 * Rate-limited to prevent abuse
 */

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('POST required', 405);
}

// ============= RATE LIMITING (per IP) =============
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitFile = sys_get_temp_dir() . '/mewc_rate_' . md5($ip);
$rateLimit = [
    'window' => 3600,    // 1 hour window
    'maxRequests' => 30,  // max 30 requests per hour per IP
];

$now = time();
$reqs = [];
if (file_exists($rateLimitFile)) {
    $reqs = json_decode(file_get_contents($rateLimitFile), true) ?: [];
    // Filter out expired
    $reqs = array_filter($reqs, fn($t) => $t > $now - $rateLimit['window']);
}

if (count($reqs) >= $rateLimit['maxRequests']) {
    jsonError('Rate limit exceeded. Please wait a bit before trying again.', 429);
}

$reqs[] = $now;
file_put_contents($rateLimitFile, json_encode($reqs));

// ============= INPUT =============
$input = getInput();
$messages = $input['messages'] ?? [];

if (!is_array($messages) || empty($messages)) {
    jsonError('Messages required');
}

// Validate last message exists
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

if (strlen($lastUserMsg) > 500) {
    jsonError('Message too long (max 500 chars)');
}

// ============= SYSTEM PROMPT — MEW ON CLAW BOT =============
$systemPrompt = "You are the official AI assistant for MEW ON CLAW — a community-driven platform for building AI tools on Solana.

# About MEW ON CLAW
MEW ON CLAW is a serious project to build a community around AI tools that anyone can create, share, and use freely. Our mission is making AI building accessible to everyone — no coding required.

# Key Facts (use these accurately):
- **Free forever**: All AI building features are 100% free, no credit card, no usage caps
- **\$MOC Token**: Drops June 8, 2026 at 5 PM UTC on pump.fun (Solana)
  - NOT required to use the platform — token is for community ownership & governance only
  - Building AI stays free with or without \$MOC
- **Domain**: mewonclaw.xyz
- **Network**: Solana (for token)
- **Tech stack**: PHP backend, MySQL, Tailwind frontend, Claude/GPT-4/Llama for AI
- **Email**: noreply@mewonclaw.xyz, security@mewonclaw.xyz

# What users can build:
- **Assistants**: Conversational AI (chat support, writing help)
- **Agents**: Autonomous AIs that can scrape, search, take actions
- **Tools**: Single-purpose utilities (input → output)
- **Workflows**: Multi-step pipelines

# Sign-in methods:
- Email + 6-digit OTP code (no password)
- Wallet connect: MetaMask, Phantom, Coinbase Wallet, Trust, Rainbow

# Privacy commitments:
- We don't train on user data
- We don't sell data
- Users own their AIs and can export/delete anytime

# Community:
- Telegram for discussions
- X/Twitter for updates
- GitHub for open-source code (progressively)

# Your personality:
- Friendly, direct, honest
- Use crab emoji 🦀 occasionally (we love crabs)
- If you don't know something, say so — direct user to support@mewonclaw.xyz or Telegram
- Never make up facts about pricing, dates, or features
- Be enthusiastic but professional
- Write concisely — 2-4 short paragraphs max for most answers
- Use **bold** for key terms (markdown style)
- Use bullet points when listing items

# Response style:
- Always respond in the same language as the user (English, Indonesian, etc.)
- Keep answers under 200 words unless they ask for detail
- For technical/troubleshooting questions, give actionable steps
- For 'is X free?' type questions, confirm with confidence — yes, free forever
- For pricing/comparison questions, mention competitors cost \$20-200/mo, we cost \$0

# Things you should NOT do:
- Don't generate code (refer to dashboard for AI building)
- Don't make financial advice about \$MOC (mention DYOR)
- Don't promise features that don't exist
- Don't break character (you're MEW ON CLAW Bot, not Claude)";

// ============= BUILD MESSAGES =============
$claudeMessages = [];
foreach ($messages as $msg) {
    $role = $msg['role'] ?? '';
    $content = $msg['content'] ?? '';
    if (in_array($role, ['user', 'assistant']) && !empty($content)) {
        $claudeMessages[] = [
            'role' => $role,
            'content' => substr($content, 0, 1500),
        ];
    }
}

// Limit conversation length to prevent abuse
if (count($claudeMessages) > 20) {
    $claudeMessages = array_slice($claudeMessages, -20);
}

// ============= CALL CLAUDE =============
$payload = [
    'model' => CLAUDE_MODEL,
    'max_tokens' => 600,
    'system' => $systemPrompt,
    'messages' => $claudeMessages,
];

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
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log('Public chatbot curl error: ' . $curlErr);
    jsonError('AI service unavailable', 503);
}

$data = json_decode($response, true);

if ($httpCode !== 200) {
    $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
    error_log('Public chatbot error: ' . $errMsg);
    jsonError('AI error: ' . $errMsg, 500);
}

$reply = '';
foreach ($data['content'] ?? [] as $block) {
    if (($block['type'] ?? '') === 'text') {
        $reply .= $block['text'];
    }
}

jsonSuccess([
    'message' => $reply,
    'remaining' => $rateLimit['maxRequests'] - count($reqs),
]);
