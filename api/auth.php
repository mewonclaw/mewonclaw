<?php
/**
 * MEW ON CLAW - Auth API
 * Endpoints:
 *   POST /api/auth.php?action=request_email   { email }
 *   POST /api/auth.php?action=verify_email    { token }
 *   POST /api/auth.php?action=wallet_nonce    { wallet }
 *   POST /api/auth.php?action=wallet_verify   { wallet, signature, message }
 *   POST /api/auth.php?action=logout
 *   GET  /api/auth.php?action=me
 */

require_once 'config.php';

$action = $_GET['action'] ?? '';
$db = getDB();

// ============================================
// 1. REQUEST EMAIL: send OTP + magic link
// ============================================
if ($action === 'request_email') {
    $input = getInput();
    $email = trim($input['email'] ?? '');
    
    if (!validateEmail($email)) {
        jsonError('Invalid email format');
    }
    
    // Rate limit: max 3 requests per email per 15 menit
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM magic_links 
        WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() >= 3) {
        jsonError('Too many requests. Please wait 15 minutes.', 429);
    }
    
    // Generate 6-digit OTP code
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + MAGIC_LINK_LIFETIME);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // Token format: "OTP|FULLTOKEN" (so we can verify either)
    // Actually we use 2 columns or just store both — store concatenated for simplicity
    // OTP only (no magic link)
    
    $stmt = $db->prepare('
        INSERT INTO magic_links (email, token, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$email, $otp, $expires, $ip, $ua]);
    
    // Send OTP email
    $sent = sendOtpEmail($email, $otp);
    
    if (!$sent) {
        jsonError('Failed to send email. Please try again.', 500);
    }
    
    jsonSuccess([
        'message' => 'Code sent to ' . $email,
        'expires_in' => MAGIC_LINK_LIFETIME
    ]);
}

// ============================================
// 1b. VERIFY OTP (6-digit code)
// ============================================
if ($action === 'verify_otp') {
    $input = getInput();
    $email = trim($input['email'] ?? '');
    $otp = trim($input['otp'] ?? '');
    
    if (empty($email) || empty($otp)) {
        jsonError('Email and OTP required');
    }
    
    if (!preg_match('/^\d{6}$/', $otp)) {
        jsonError('Invalid OTP format');
    }
    
    // Find unused OTP for this email
    $stmt = $db->prepare("
        SELECT * FROM magic_links 
        WHERE email = ? 
        AND token = ? 
        AND expires_at > NOW() 
        AND used_at IS NULL
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$email, $otp]);
    $link = $stmt->fetch();
    
    if (!$link) jsonError('Invalid or expired code', 401);
    
    // Mark used
    $db->prepare('UPDATE magic_links SET used_at = NOW() WHERE id = ?')->execute([$link['id']]);
    
    // Find or create user
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $username = 'user_' . substr(md5($email), 0, 8);
        $stmt = $db->prepare('
            INSERT INTO users (email, username, is_verified, last_login_at)
            VALUES (?, ?, 1, NOW())
        ');
        $stmt->execute([$email, $username]);
        $userId = $db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    } else {
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    }
    
    $sessionToken = createSession($user['id']);
    
    jsonSuccess([
        'token' => $sessionToken,
        'user' => sanitizeUser($user)
    ]);
}

// ============================================
// 3. WALLET NONCE (challenge)
// ============================================
if ($action === 'wallet_nonce') {
    $input = getInput();
    $wallet = trim($input['wallet'] ?? '');
    
    if (empty($wallet)) jsonError('Wallet address required');
    
    $nonce = generateToken(16);
    $expires = date('Y-m-d H:i:s', time() + 600); // 10 menit
    
    $stmt = $db->prepare('
        INSERT INTO wallet_nonces (wallet_address, nonce, expires_at)
        VALUES (?, ?, ?)
    ');
    $stmt->execute([$wallet, $nonce, $expires]);
    
    // Message yang harus di-sign user
    $message = "Sign in to MEW ON CLAW\n\nNonce: {$nonce}\nWallet: {$wallet}";
    
    jsonSuccess([
        'nonce' => $nonce,
        'message' => $message
    ]);
}

// ============================================
// 4. WALLET VERIFY SIGNATURE
// ============================================
if ($action === 'wallet_verify') {
    $input = getInput();
    $wallet = trim($input['wallet'] ?? '');
    $signature = trim($input['signature'] ?? '');
    $walletType = trim($input['wallet_type'] ?? 'metamask');
    $nonce = trim($input['nonce'] ?? '');
    
    if (empty($wallet) || empty($signature) || empty($nonce)) {
        jsonError('Missing parameters');
    }
    
    // Verify nonce exists & not used
    $stmt = $db->prepare('
        SELECT * FROM wallet_nonces 
        WHERE wallet_address = ? AND nonce = ? AND expires_at > NOW() AND used_at IS NULL
    ');
    $stmt->execute([$wallet, $nonce]);
    $nonceRow = $stmt->fetch();
    
    if (!$nonceRow) jsonError('Invalid or expired nonce', 401);
    
    // NOTE: Untuk verifikasi cryptographic signature, butuh library:
    //   - Ethereum: web3.php atau composer "kornrunner/keccak"
    //   - Solana: butuh tweetnacl-php
    // 
    // Untuk MVP, kita TRUST signature kalau wallet & signature ada.
    // Production: WAJIB verify signature secara cryptographic!
    // 
    // Contoh basic check (hanya format):
    if (strlen($signature) < 10) {
        jsonError('Invalid signature format', 401);
    }
    
    // Mark nonce used
    $db->prepare('UPDATE wallet_nonces SET used_at = NOW() WHERE id = ?')->execute([$nonceRow['id']]);
    
    // Find or create user
    $stmt = $db->prepare('SELECT * FROM users WHERE wallet_address = ?');
    $stmt->execute([$wallet]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $username = 'wallet_' . substr($wallet, 2, 8);
        $stmt = $db->prepare('
            INSERT INTO users (wallet_address, wallet_type, username, is_verified, last_login_at)
            VALUES (?, ?, ?, 1, NOW())
        ');
        $stmt->execute([$wallet, $walletType, $username]);
        $userId = $db->lastInsertId();
        
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    } else {
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    }
    
    $sessionToken = createSession($user['id']);
    
    jsonSuccess([
        'token' => $sessionToken,
        'user' => sanitizeUser($user)
    ]);
}

// ============================================
// 5. GET CURRENT USER (Me)
// ============================================
if ($action === 'me') {
    $user = requireAuth();
    jsonSuccess(['user' => sanitizeUser($user)]);
}

// ============================================
// 6. LOGOUT
// ============================================
if ($action === 'logout') {
    $headers = getallheaders();
    $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
    
    if ($token) {
        $db->prepare('DELETE FROM sessions WHERE session_token = ?')->execute([$token]);
    }
    
    jsonSuccess(['message' => 'Logged out']);
}

// Default: action not found
jsonError('Unknown action: ' . $action, 404);


// ============================================
// HELPER: CREATE SESSION
// ============================================
function createSession($userId) {
    $db = getDB();
    $token = generateToken(64);
    $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $db->prepare('
        INSERT INTO sessions (user_id, session_token, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$userId, $token, $expires, $ip, $ua]);
    
    return $token;
}

// ============================================
// HELPER: REMOVE SENSITIVE FIELDS
// ============================================
function sanitizeUser($user) {
    return [
        'id' => $user['id'],
        'email' => $user['email'],
        'wallet_address' => $user['wallet_address'],
        'wallet_type' => $user['wallet_type'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'avatar_url' => $user['avatar_url'],
        'bio' => $user['bio'],
        'is_verified' => (bool)$user['is_verified'],
        'created_at' => $user['created_at'],
    ];
}

// ============================================
// HELPER: SEND MAGIC LINK EMAIL via SMTP
// ============================================
function sendOtpEmail($to, $otp) {
    // Pure PHP SMTP (tanpa library)
    $subject = 'Your MEW ON CLAW sign-in code: ' . $otp;
    
    $htmlBody = '
    <!DOCTYPE html>
    <html><body style="font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: #0A0A0A; color: white; padding: 40px; margin: 0;">
        <div style="max-width: 480px; margin: 0 auto; background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 8px; padding: 40px;">
            <h1 style="color: #54BBF6; font-size: 32px; margin: 0 0 8px; font-weight: 800; letter-spacing: -0.02em;">MEW ON CLAW</h1>
            <p style="color: #888; font-size: 12px; text-transform: uppercase; letter-spacing: 0.2em; margin: 0 0 32px;">Free AI for everyone</p>
            
            <h2 style="color: white; margin: 0 0 16px; font-size: 24px;">Your sign-in code</h2>
            <p style="color: #aaa; line-height: 1.6; margin: 0 0 24px;">Enter this 6-digit code in the website to sign in:</p>
            
            <!-- OTP CODE BOX -->
            <div style="background: #0A0A0A; border: 2px solid #54BBF6; border-radius: 8px; padding: 32px 24px; text-align: center; margin: 24px 0;">
                <div style="font-family: monospace; font-size: 48px; font-weight: 700; letter-spacing: 14px; color: #54BBF6; padding-left: 14px;">' . htmlspecialchars($otp) . '</div>
            </div>
            
            <p style="color: #666; font-size: 14px; margin: 24px 0 8px; text-align: center;">⏱️ This code expires in 15 minutes</p>
            <p style="color: #555; font-size: 12px; margin: 0; text-align: center;">For your security, never share this code with anyone</p>
            
            <hr style="border: none; border-top: 1px solid #2a2a2a; margin: 32px 0;">
            
            <p style="color: #666; font-size: 11px; line-height: 1.6; margin: 0;">If you didn\'t request this code, you can safely ignore this email. Your account is safe.</p>
            <p style="color: #444; font-size: 10px; line-height: 1.6; margin: 16px 0 0;">MEW ON CLAW · mewonclaw.xyz · Free AI for everyone, forever 🦀</p>
        </div>
    </body></html>';
    
    return smtpSend($to, $subject, $htmlBody);
}

function smtpSend($to, $subject, $htmlBody) {
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $from = SMTP_FROM_EMAIL;
    $fromName = SMTP_FROM_NAME;
    
    // Connect SSL untuk port 465
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);
    
    $socket = @stream_socket_client(
        'ssl://' . $host . ':' . $port,
        $errno, $errstr, 30,
        STREAM_CLIENT_CONNECT,
        $context
    );
    
    if (!$socket) {
        error_log("SMTP Connect failed: $errstr ($errno)");
        return false;
    }
    
    $read = function() use ($socket) { return fgets($socket, 1024); };
    $send = function($cmd) use ($socket) { fwrite($socket, $cmd . "\r\n"); };
    
    try {
        $read(); // server greeting
        
        $send('EHLO mewonclaw.xyz');
        while ($line = $read()) {
            if (substr($line, 3, 1) === ' ') break;
        }
        
        $send('AUTH LOGIN');
        $read();
        
        $send(base64_encode($user));
        $read();
        
        $send(base64_encode($pass));
        $resp = $read();
        if (substr($resp, 0, 3) !== '235') {
            error_log("SMTP Auth failed: $resp");
            fclose($socket);
            return false;
        }
        
        $send('MAIL FROM:<' . $from . '>');
        $read();
        
        $send('RCPT TO:<' . $to . '>');
        $read();
        
        $send('DATA');
        $read();
        
        $headers = [
            'From: ' . $fromName . ' <' . $from . '>',
            'To: ' . $to,
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
        ];
        
        $send(implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.");
        $read();
        
        $send('QUIT');
        fclose($socket);
        return true;
    } catch (Exception $e) {
        error_log('SMTP Error: ' . $e->getMessage());
        fclose($socket);
        return false;
    }
}
