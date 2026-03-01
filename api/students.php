<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

require_once __DIR__ . '/config.php';

$query = "
    SELECT id, first_name, middle_name, last_name, student_id, email
    FROM users
    WHERE role = 'student'
    ORDER BY first_name ASC, last_name ASC
";

$result = mysqli_query($conn, $query);
if (!$result) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch students',
    ]);
    exit;
}

$students = [];
while ($row = mysqli_fetch_assoc($result)) {
    $middleName = trim((string) ($row['middle_name'] ?? ''));
    $fullName = trim(
        $row['first_name'] . ' ' .
        ($middleName !== '' ? $middleName . ' ' : '') .
        $row['last_name']
    );

    $students[] = [
        'id' => (string) $row['id'],
        'name' => $fullName,
        'studentLrn' => $row['student_id'] ?? '',
        'email' => $row['email'],
    ];
}

echo json_encode([
    'success' => true,
    'students' => $students,
]);
