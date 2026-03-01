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

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$email = isset($data['email']) ? trim($data['email']) : '';
$role = isset($data['role']) ? trim($data['role']) : '';
$oldPassword = isset($data['oldPassword']) ? $data['oldPassword'] : '';
$newPassword = isset($data['newPassword']) ? $data['newPassword'] : '';

$allowedRoles = ['student', 'teacher', 'librarian'];

if ($email === '' || $oldPassword === '' || $newPassword === '' || !in_array($role, $allowedRoles, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid change-password payload',
    ]);
    exit;
}

$query = 'SELECT id, password FROM users WHERE email = ? AND role = ? LIMIT 1';
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
$user = $result ? mysqli_fetch_assoc($result) : null;

if (!$user) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'User not found',
    ]);
    exit;
}

$storedPassword = (string) $user['password'];
$verified = password_verify($oldPassword, $storedPassword) || hash_equals($storedPassword, (string) $oldPassword);

if (!$verified) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Incorrect old password',
    ]);
    exit;
}

$newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
$updateQuery = 'UPDATE users SET password = ? WHERE id = ?';
$updateStmt = mysqli_prepare($conn, $updateQuery);

if (!$updateStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare password update query',
    ]);
    exit;
}

$userId = (int) $user['id'];
mysqli_stmt_bind_param($updateStmt, 'si', $newPasswordHash, $userId);

if (!mysqli_stmt_execute($updateStmt)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to change password',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Password changed successfully',
]);
