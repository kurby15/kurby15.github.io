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

$firstName = isset($data['firstName']) ? trim($data['firstName']) : '';
$middleName = isset($data['middleName']) ? trim($data['middleName']) : '';
$lastName = isset($data['lastName']) ? trim($data['lastName']) : '';
$studentId = isset($data['studentId']) ? trim($data['studentId']) : null;
$employeeId = isset($data['employeeId']) ? trim($data['employeeId']) : null;
$email = isset($data['email']) ? trim($data['email']) : '';
$password = isset($data['password']) ? $data['password'] : '';
$role = isset($data['role']) ? trim($data['role']) : '';

$allowedRoles = ['student', 'teacher'];

if ($firstName === '' || $lastName === '' || $email === '' || $password === '' || !in_array($role, $allowedRoles, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required registration fields',
    ]);
    exit;
}

if ($role === 'student' && ($studentId === null || $studentId === '')) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Student LRN is required for students',
    ]);
    exit;
}

if ($role === 'teacher' && ($employeeId === null || $employeeId === '')) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Employee ID is required for teachers',
    ]);
    exit;
}

$checkQuery = 'SELECT id FROM users WHERE email = ? LIMIT 1';
$checkStmt = mysqli_prepare($conn, $checkQuery);

if (!$checkStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare email check',
    ]);
    exit;
}

mysqli_stmt_bind_param($checkStmt, 's', $email);
mysqli_stmt_execute($checkStmt);
$existingResult = mysqli_stmt_get_result($checkStmt);

if ($existingResult && mysqli_fetch_assoc($existingResult)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Email already registered. Please use a different email.',
    ]);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$insertQuery = 'INSERT INTO users (first_name, middle_name, last_name, student_id, employee_id, email, password, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
$insertStmt = mysqli_prepare($conn, $insertQuery);

if (!$insertStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare registration query',
    ]);
    exit;
}

$middleNameValue = $middleName !== '' ? $middleName : null;
$studentIdValue = $studentId !== '' ? $studentId : null;
$employeeIdValue = $employeeId !== '' ? $employeeId : null;

mysqli_stmt_bind_param(
    $insertStmt,
    'ssssssss',
    $firstName,
    $middleNameValue,
    $lastName,
    $studentIdValue,
    $employeeIdValue,
    $email,
    $passwordHash,
    $role
);

if (!mysqli_stmt_execute($insertStmt)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create account',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Account created successfully',
]);
