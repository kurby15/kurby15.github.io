<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $userId = isset($_GET['userId']) ? (int) $_GET['userId'] : 0;

    if ($userId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'userId is required',
        ]);
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, first_name, middle_name, last_name, email, employee_id, role, librarian_level
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare profile query',
        ]);
    }

    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    if (!$row) {
        respond(404, [
            'success' => false,
            'message' => 'User not found',
        ]);
    }

    respond(200, [
        'success' => true,
        'profile' => [
            'id' => (string) $row['id'],
            'firstName' => $row['first_name'],
            'middleName' => $row['middle_name'] ?? '',
            'lastName' => $row['last_name'],
            'email' => $row['email'],
            'employeeId' => $row['employee_id'] ?? '',
            'role' => $row['role'],
            'librarianLevel' => $row['librarian_level'] ?? null,
        ],
    ]);
}

if ($method === 'PUT') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid JSON payload',
        ]);
    }

    $userId = isset($data['userId']) ? (int) $data['userId'] : 0;
    $firstName = isset($data['firstName']) ? trim((string) $data['firstName']) : '';
    $middleName = isset($data['middleName']) ? trim((string) $data['middleName']) : '';
    $lastName = isset($data['lastName']) ? trim((string) $data['lastName']) : '';
    $email = isset($data['email']) ? trim((string) $data['email']) : '';
    $employeeId = isset($data['employeeId']) ? trim((string) $data['employeeId']) : '';

    if ($userId <= 0 || $firstName === '' || $lastName === '' || $email === '') {
        respond(400, [
            'success' => false,
            'message' => 'userId, firstName, lastName, and email are required',
        ]);
    }

    $existingStmt = mysqli_prepare($conn, "SELECT id, role, librarian_level FROM users WHERE id = ? LIMIT 1");
    if (!$existingStmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare user lookup query',
        ]);
    }

    mysqli_stmt_bind_param($existingStmt, 'i', $userId);
    mysqli_stmt_execute($existingStmt);
    $existingResult = mysqli_stmt_get_result($existingStmt);
    $existingUser = $existingResult ? mysqli_fetch_assoc($existingResult) : null;

    if (!$existingUser) {
        respond(404, [
            'success' => false,
            'message' => 'User not found',
        ]);
    }

    if (($existingUser['role'] ?? '') !== 'librarian') {
        respond(403, [
            'success' => false,
            'message' => 'Only librarian accounts can be edited here',
        ]);
    }

    $emailCheckStmt = mysqli_prepare(
        $conn,
        'SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1'
    );
    if (!$emailCheckStmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare email uniqueness query',
        ]);
    }

    mysqli_stmt_bind_param($emailCheckStmt, 'si', $email, $userId);
    mysqli_stmt_execute($emailCheckStmt);
    $emailCheckResult = mysqli_stmt_get_result($emailCheckStmt);
    if ($emailCheckResult && mysqli_fetch_assoc($emailCheckResult)) {
        respond(409, [
            'success' => false,
            'message' => 'Email already exists',
        ]);
    }

    if ($employeeId !== '') {
        $employeeCheckStmt = mysqli_prepare(
            $conn,
            'SELECT id FROM users WHERE employee_id = ? AND id <> ? LIMIT 1'
        );
        if (!$employeeCheckStmt) {
            respond(500, [
                'success' => false,
                'message' => 'Failed to prepare employee ID uniqueness query',
            ]);
        }

        mysqli_stmt_bind_param($employeeCheckStmt, 'si', $employeeId, $userId);
        mysqli_stmt_execute($employeeCheckStmt);
        $employeeCheckResult = mysqli_stmt_get_result($employeeCheckStmt);
        if ($employeeCheckResult && mysqli_fetch_assoc($employeeCheckResult)) {
            respond(409, [
                'success' => false,
                'message' => 'Employee ID already exists',
            ]);
        }
    }

    $updateStmt = mysqli_prepare(
        $conn,
        'UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, email = ?, employee_id = ? WHERE id = ?'
    );
    if (!$updateStmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare profile update query',
        ]);
    }

    mysqli_stmt_bind_param($updateStmt, 'sssssi', $firstName, $middleName, $lastName, $email, $employeeId, $userId);
    if (!mysqli_stmt_execute($updateStmt)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to update profile',
        ]);
    }

    $updatedName = trim($firstName . ' ' . ($middleName !== '' ? $middleName . ' ' : '') . $lastName);

    respond(200, [
        'success' => true,
        'message' => 'Profile updated successfully',
        'profile' => [
            'id' => (string) $userId,
            'name' => $updatedName,
            'firstName' => $firstName,
            'middleName' => $middleName,
            'lastName' => $lastName,
            'email' => $email,
            'employeeId' => $employeeId,
            'role' => 'librarian',
            'librarianLevel' => $existingUser['librarian_level'] ?? null,
        ],
    ]);
}

respond(405, [
    'success' => false,
    'message' => 'Method not allowed',
]);
