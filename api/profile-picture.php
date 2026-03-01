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

function ensureProfilePictureColumn($conn) {
    $checkQuery = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'profile_picture'
        LIMIT 1
    ";

    $checkResult = mysqli_query($conn, $checkQuery);
    $hasColumn = $checkResult && mysqli_fetch_assoc($checkResult);

    if ($hasColumn) {
        return true;
    }

    $alterQuery = 'ALTER TABLE users ADD COLUMN profile_picture LONGTEXT NULL';
    return mysqli_query($conn, $alterQuery) === true;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$userId = isset($data['userId']) ? (int) $data['userId'] : 0;
$profilePicture = isset($data['profilePicture']) ? trim((string) $data['profilePicture']) : '';

if ($userId <= 0 || $profilePicture === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'userId and profilePicture are required',
    ]);
    exit;
}

if (!ensureProfilePictureColumn($conn)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare profile picture database column',
    ]);
    exit;
}

if (strlen($profilePicture) > 6 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode([
        'success' => false,
        'message' => 'Profile picture is too large',
    ]);
    exit;
}

$checkStmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE id = ? LIMIT 1');
if (!$checkStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare user lookup query',
    ]);
    exit;
}

mysqli_stmt_bind_param($checkStmt, 'i', $userId);
mysqli_stmt_execute($checkStmt);
$checkResult = mysqli_stmt_get_result($checkStmt);
if (!$checkResult || !mysqli_fetch_assoc($checkResult)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'User not found',
    ]);
    exit;
}

$updateStmt = mysqli_prepare($conn, 'UPDATE users SET profile_picture = ? WHERE id = ?');
if (!$updateStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare profile picture update query',
    ]);
    exit;
}

mysqli_stmt_bind_param($updateStmt, 'si', $profilePicture, $userId);
if (!mysqli_stmt_execute($updateStmt)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update profile picture',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Profile picture updated successfully',
]);
