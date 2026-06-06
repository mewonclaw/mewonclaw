<?php
/**
 * MEW ON CLAW - Configuration File
 * 
 * EDIT BAGIAN INI dengan kredensial dari Hostinger:
 * - DB_HOST/DB_NAME/DB_USER/DB_PASS dari hPanel → MySQL Databases
 * - CLAUDE_API_KEY dari console.anthropic.com
 * - SMTP_* dari Hostinger Email setup
 */

// ============= DATABASE =============
// PILIHAN A (paling gampang): pakai ulang database lama yang udah ada tabelnya
//   -> DB_NAME & DB_USER biarkan seperti di bawah, cukup isi DB_PASS aja.
// PILIHAN B (database baru): hPanel → Databases → MySQL → buat DB baru (format u1234567_xxxx),
//   lalu ganti DB_NAME & DB_USER di bawah, set DB_PASS, dan IMPORT tabelnya (minta aku schema.sql).
define('DB_HOST', 'localhost');                       // Hostinger = localhost (JANGAN diubah)
define('DB_NAME', 'u463942577_CLAWPIX');              // ← database existing (ganti kalau bikin baru)
define('DB_USER', 'u463942577_CLAWPIX');              // ← username MySQL (ganti kalau bikin baru)
define('DB_PASS', 'GANTI_PASSWORD_DATABASE');         // ← ISI password MySQL kamu
define('DB_CHARSET', 'utf8mb4');

// ============= CLAUDE API =============
// Ambil API key di: https://console.anthropic.com/settings/keys  (mulai dengan sk-ant-...)
define('CLAUDE_API_KEY', 'GANTI_DENGAN_API_KEY');     // ← tempel API key di sini
define('CLAUDE_MODEL', 'claude-sonnet-4-6');          // model chatbot (current)
define('CLAUDE_MAX_TOKENS', 1024);

// ============= SMTP EMAIL (untuk kirim kode OTP login) =============
// LANGKAH: hPanel → Emails → buat email "noreply@mewonclaw.xyz", lalu isi password-nya di SMTP_PASS.
define('SMTP_HOST', 'smtp.hostinger.com');            // SMTP Hostinger (JANGAN diubah)
define('SMTP_PORT', 465);                             // SSL port
define('SMTP_USER', 'noreply@mewonclaw.xyz');         // email pengirim
define('SMTP_PASS', 'GANTI_PASSWORD_EMAIL');          // ← password email noreply@mewonclaw.xyz
define('SMTP_FROM_NAME', 'MEW ON CLAW');
define('SMTP_FROM_EMAIL', 'noreply@mewonclaw.xyz');

// ============= SITE =============
define('SITE_URL', 'https://mewonclaw.xyz');          // domain kamu (sudah benar)
define('SITE_NAME', 'MEW ON CLAW');

// Session token expire (7 days = 604800 detik)
define('SESSION_LIFETIME', 604800);

// Magic link expire (15 menit = 900 detik)
define('MAGIC_LINK_LIFETIME', 900);

// ============= CORS HEADERS =============
header('Access-Control-Allow-Origin: ' . SITE_URL);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============= DB CONNECTION =============
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log('DB Error: ' . $e->getMessage());
            jsonError('Database connection failed', 500);
        }
    }
    return $pdo;
}

// ============= HELPER FUNCTIONS =============
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function jsonError($message, $code = 400) {
    jsonResponse(['error' => $message], $code);
}

function jsonSuccess($data = []) {
    jsonResponse(array_merge(['success' => true], $data));
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

function getInput() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function getCurrentUser() {
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    
    if (empty($token)) return null;
    
    $db = getDB();
    $stmt = $db->prepare('
        SELECT u.* FROM users u
        JOIN sessions s ON s.user_id = u.id
        WHERE s.session_token = ? AND s.expires_at > NOW()
    ');
    $stmt->execute([$token]);
    return $stmt->fetch();
}

function requireAuth() {
    $user = getCurrentUser();
    if (!$user) jsonError('Unauthorized', 401);
    return $user;
}
