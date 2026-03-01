<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

require_once __DIR__ . '/config.php';

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

function loadForgotPasswordStore($filePath)
{
    if (!file_exists($filePath)) {
        return [];
    }

    $raw = file_get_contents($filePath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveForgotPasswordStore($filePath, $store)
{
    $dir = dirname($filePath);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode($store, JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    return file_put_contents($filePath, $json, LOCK_EX) !== false;
}

function forgotPasswordKey($email, $role)
{
    return strtolower(trim($email)) . '|' . strtolower(trim($role));
}

function buildVerificationEmailBody($recipientName, $verificationCode, $signatureName, $signatureRole)
{
    return "Dear {$recipientName},\n\n"
        . "We received a request to reset the password for your library account.\n\n"
        . "Verification Code: {$verificationCode}\n"
        . "Validity: 10 minutes\n\n"
        . "If you did not request this, please ignore this message or contact library support immediately.\n\n"
        . "Sincerely,\n"
        . "{$signatureName}\n"
        . "{$signatureRole}";
}

function sendVerificationEmailWithPhpMailer($toEmail, $recipientName, $verificationCode, $mailConfig)
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return [
            'success' => false,
            'message' => 'PHPMailer is not installed. Run: composer require phpmailer/phpmailer',
        ];
    }

    $smtpHost = isset($mailConfig['host']) ? trim((string) $mailConfig['host']) : '';
    $smtpUsername = isset($mailConfig['username']) ? trim((string) $mailConfig['username']) : '';
    $smtpPassword = isset($mailConfig['password']) ? trim((string) $mailConfig['password']) : '';
    $smtpPort = isset($mailConfig['port']) ? (int) $mailConfig['port'] : 587;
    $smtpEncryption = isset($mailConfig['encryption']) ? strtolower(trim((string) $mailConfig['encryption'])) : 'tls';
    $fromAddress = isset($mailConfig['fromAddress']) ? trim((string) $mailConfig['fromAddress']) : 'no-reply@sanmartinlibrary.local';
    $fromName = isset($mailConfig['fromName']) ? trim((string) $mailConfig['fromName']) : 'San Martin Library';

    if ($smtpHost === '' || $smtpUsername === '' || $smtpPassword === '') {
        return [
            'success' => false,
            'message' => 'SMTP is not configured in api/config.php (mailConfig host, username, password).',
        ];
    }

    $smtpPassword = str_replace(' ', '', $smtpPassword);

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->Port = $smtpPort;

        if ($smtpEncryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($toEmail);
        $mail->isHTML(false);
        $mail->Subject = 'Library Password Reset Verification Code';
        $mailBody = buildVerificationEmailBody($recipientName, $verificationCode, $fromName, 'Library Support Team');
        $mail->Body = $mailBody;

        $mail->send();

        return [
            'success' => true,
            'message' => 'Verification code sent to your email',
            'body' => $mailBody,
        ];
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();

        if (stripos($errorMessage, 'Could not authenticate') !== false) {
            return [
                'success' => false,
                'message' => 'SMTP auth failed using api/config.php mailConfig. Check host/port/encryption/username/password/fromAddress. Provider response: ' . $errorMessage,
            ];
        }

        return [
            'success' => false,
            'message' => 'PHPMailer error: ' . $errorMessage,
        ];
    }
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = [];
}

$action = isset($data['action']) ? trim($data['action']) : '';
$email = isset($data['email']) ? trim($data['email']) : '';
$role = isset($data['role']) ? trim($data['role']) : '';

$codeStorePath = __DIR__ . '/tmp/forgot_password_codes.json';
$codeTtlSeconds = 600;
$verificationTtlSeconds = 900;

$allowedRoles = ['student', 'teacher', 'librarian'];

if ($email === '' || !in_array($role, $allowedRoles, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email or role',
    ]);
    exit;
}

if ($action === 'send-code') {
    $query = 'SELECT id, email, first_name, middle_name, last_name FROM users WHERE email = ? AND role = ? LIMIT 1';
    $stmt = mysqli_prepare($conn, $query);

    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare query',
        ]);
        exit;
    }

    mysqli_stmt_bind_param($stmt, 'ss', $email, $role);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $userRecord = $result ? mysqli_fetch_assoc($result) : null;

    if (!$userRecord || !isset($userRecord['email'])) {
        echo json_encode([
            'success' => true,
            'message' => 'Verification code sent to your email',
        ]);
        exit;
    }

    $recipientEmail = trim((string) $userRecord['email']);
    $firstName = isset($userRecord['first_name']) ? trim((string) $userRecord['first_name']) : '';
    $middleName = isset($userRecord['middle_name']) ? trim((string) $userRecord['middle_name']) : '';
    $lastName = isset($userRecord['last_name']) ? trim((string) $userRecord['last_name']) : '';

    $nameParts = [];
    if ($firstName !== '') {
        $nameParts[] = $firstName;
    }
    if ($middleName !== '') {
        $nameParts[] = $middleName;
    }
    if ($lastName !== '') {
        $nameParts[] = $lastName;
    }

    $recipientName = trim(implode(' ', $nameParts));
    if ($recipientName === '') {
        $recipientName = 'Library User';
    }

    if (function_exists('random_int')) {
        $verificationCode = (string) random_int(100000, 999999);
    } else {
        $verificationCode = (string) mt_rand(100000, 999999);
    }

    $activeMailConfig = isset($mailConfig) && is_array($mailConfig) ? $mailConfig : [];

    $mailResult = sendVerificationEmailWithPhpMailer($recipientEmail, $recipientName, $verificationCode, $activeMailConfig);
    $mailSent = isset($mailResult['success']) && $mailResult['success'] === true;

    if (!$mailSent) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => isset($mailResult['message']) ? $mailResult['message'] : 'Failed to send verification email.',
        ]);
        exit;
    }

    $store = loadForgotPasswordStore($codeStorePath);
    $now = time();
    $key = forgotPasswordKey($email, $role);

    foreach ($store as $storedKey => $item) {
        $codeExpiresAt = isset($item['codeExpiresAt']) ? (int) $item['codeExpiresAt'] : 0;
        $verifiedExpiresAt = isset($item['verifiedExpiresAt']) ? (int) $item['verifiedExpiresAt'] : 0;
        if ($codeExpiresAt < $now && $verifiedExpiresAt < $now) {
            unset($store[$storedKey]);
        }
    }

    $store[$key] = [
        'codeHash' => password_hash($verificationCode, PASSWORD_DEFAULT),
        'codeExpiresAt' => $now + $codeTtlSeconds,
        'verified' => false,
        'verifiedExpiresAt' => 0,
    ];

    if (!saveForgotPasswordStore($codeStorePath, $store)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save verification code',
        ]);
        exit;
    }

    $response = [
        'success' => true,
        'message' => isset($mailResult['message']) ? $mailResult['message'] : 'Verification code sent to your email',
    ];

    if (isset($mailResult['body'])) {
        $response['body'] = $mailResult['body'];
    }

    echo json_encode($response);
    exit;
}

if ($action === 'verify-code') {
    $verificationCode = isset($data['verificationCode']) ? trim($data['verificationCode']) : '';

    if ($verificationCode === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Verification code is required',
        ]);
        exit;
    }

    $store = loadForgotPasswordStore($codeStorePath);
    $key = forgotPasswordKey($email, $role);
    $entry = isset($store[$key]) ? $store[$key] : null;

    if (!$entry) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No verification request found. Please request a new code.',
        ]);
        exit;
    }

    $now = time();
    $codeExpiresAt = isset($entry['codeExpiresAt']) ? (int) $entry['codeExpiresAt'] : 0;
    $codeHash = isset($entry['codeHash']) ? $entry['codeHash'] : '';

    if ($codeExpiresAt < $now || $codeHash === '' || !password_verify($verificationCode, $codeHash)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired verification code',
        ]);
        exit;
    }

    $entry['verified'] = true;
    $entry['verifiedExpiresAt'] = $now + $verificationTtlSeconds;
    $entry['codeHash'] = '';
    $entry['codeExpiresAt'] = 0;
    $store[$key] = $entry;

    if (!saveForgotPasswordStore($codeStorePath, $store)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save verification status',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Code verified successfully',
    ]);
    exit;
}

if ($action === 'reset-password') {
    $newPassword = isset($data['newPassword']) ? $data['newPassword'] : '';

    if ($newPassword === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'New password is required',
        ]);
        exit;
    }

    $store = loadForgotPasswordStore($codeStorePath);
    $key = forgotPasswordKey($email, $role);
    $entry = isset($store[$key]) ? $store[$key] : null;
    $now = time();
    $isVerified = $entry
        && isset($entry['verified'])
        && $entry['verified'] === true
        && isset($entry['verifiedExpiresAt'])
        && (int) $entry['verifiedExpiresAt'] >= $now;

    if (!$isVerified) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Verification is required before resetting password',
        ]);
        exit;
    }

    $checkQuery = 'SELECT id FROM users WHERE email = ? AND role = ? LIMIT 1';
    $checkStmt = mysqli_prepare($conn, $checkQuery);

    if (!$checkStmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare user check query',
        ]);
        exit;
    }

    mysqli_stmt_bind_param($checkStmt, 'ss', $email, $role);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    $user = $checkResult ? mysqli_fetch_assoc($checkResult) : null;

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Email not found in our system',
        ]);
        exit;
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateQuery = 'UPDATE users SET password = ? WHERE email = ? AND role = ?';
    $updateStmt = mysqli_prepare($conn, $updateQuery);

    if (!$updateStmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare password update query',
        ]);
        exit;
    }

    mysqli_stmt_bind_param($updateStmt, 'sss', $passwordHash, $email, $role);

    if (!mysqli_stmt_execute($updateStmt)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to reset password',
        ]);
        exit;
    }

    unset($store[$key]);
    saveForgotPasswordStore($codeStorePath, $store);

    echo json_encode([
        'success' => true,
        'message' => 'Password reset successfully',
    ]);
    exit;
}

http_response_code(400);
echo json_encode([
    'success' => false,
    'message' => 'Invalid action',
]);
