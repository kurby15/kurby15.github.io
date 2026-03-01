<?php
require_once __DIR__ . '/cors.php';
applyCors('POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

require_once __DIR__ . '/config.php';

function ensureStudentPortalRestrictionsTable($conn) {
    $checkQuery = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'student_portal_restrictions'
    ";

    $checkResult = mysqli_query($conn, $checkQuery);
    $row = $checkResult ? mysqli_fetch_assoc($checkResult) : null;
    $exists = (int) ($row['total'] ?? 0) > 0;

    if ($exists) {
        return;
    }

    mysqli_query(
        $conn,
        "CREATE TABLE student_portal_restrictions (
            student_user_id INT PRIMARY KEY,
            restrict_book_search TINYINT(1) NOT NULL DEFAULT 0,
            restrict_book_recommendations TINYINT(1) NOT NULL DEFAULT 0,
            restrict_book_reservations TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_student_portal_restrictions_user
                FOREIGN KEY (student_user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function ensureLibrarianLevelColumn($conn) {
    $checkQuery = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'librarian_level'
    ";

    $checkResult = mysqli_query($conn, $checkQuery);
    $row = $checkResult ? mysqli_fetch_assoc($checkResult) : null;
    $exists = (int) ($row['total'] ?? 0) > 0;

    if ($exists) {
        return;
    }

    mysqli_query(
        $conn,
        "ALTER TABLE users
         ADD COLUMN librarian_level ENUM('head_librarian', 'librarian_staff') DEFAULT NULL AFTER role"
    );
}

function normalizeLibrarianLevels($conn) {
    mysqli_query(
        $conn,
        "UPDATE users
         SET librarian_level = 'librarian_staff'
         WHERE role = 'librarian' AND (librarian_level IS NULL OR librarian_level = '')"
    );

    mysqli_query(
        $conn,
        "UPDATE users
         SET librarian_level = 'head_librarian'
         WHERE role = 'librarian' AND email = 'admin@library.com'"
    );
}

function countStudentOverdueBorrowings($conn, $studentUserId) {
    $countStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total
         FROM borrowings
         WHERE user_id = ? AND (status = 'overdue' OR (status = 'active' AND due_date < CURDATE()))"
    );

    if (!$countStmt) {
        return 0;
    }

    mysqli_stmt_bind_param($countStmt, 'i', $studentUserId);
    mysqli_stmt_execute($countStmt);
    $countResult = mysqli_stmt_get_result($countStmt);
    $countRow = $countResult ? mysqli_fetch_assoc($countResult) : null;
    return (int) ($countRow['total'] ?? 0);
}

function syncStudentPortalRestrictionAutomation($conn, $studentUserId) {
    $overdueCount = countStudentOverdueBorrowings($conn, $studentUserId);

    if ($overdueCount > 0) {
        $enabled = 1;
        $upsertStmt = mysqli_prepare(
            $conn,
            'INSERT INTO student_portal_restrictions (student_user_id, restrict_book_search, restrict_book_recommendations, restrict_book_reservations) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE restrict_book_search = VALUES(restrict_book_search), restrict_book_recommendations = VALUES(restrict_book_recommendations), restrict_book_reservations = VALUES(restrict_book_reservations)'
        );

        if (!$upsertStmt) {
            return;
        }

        mysqli_stmt_bind_param(
            $upsertStmt,
            'iiii',
            $studentUserId,
            $enabled,
            $enabled,
            $enabled
        );
        mysqli_stmt_execute($upsertStmt);
        return;
    }

    $deleteStmt = mysqli_prepare($conn, 'DELETE FROM student_portal_restrictions WHERE student_user_id = ?');
    if (!$deleteStmt) {
        return;
    }

    mysqli_stmt_bind_param($deleteStmt, 'i', $studentUserId);
    mysqli_stmt_execute($deleteStmt);
}

function hasProfilePictureColumn($conn) {
    $query = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME = 'profile_picture'
        LIMIT 1
    ";

    $result = mysqli_query($conn, $query);
    if (!$result) {
        return false;
    }

    return mysqli_fetch_assoc($result) !== null;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$email = isset($data['email']) ? trim($data['email']) : '';
$password = isset($data['password']) ? $data['password'] : '';
$role = isset($data['role']) ? trim($data['role']) : '';

$allowedRoles = ['student', 'teacher', 'librarian'];

if ($email === '' || $password === '' || !in_array($role, $allowedRoles, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid login payload',
    ]);
    exit;
}

$profilePictureSelect = hasProfilePictureColumn($conn)
    ? 'u.profile_picture AS profile_picture'
    : 'NULL AS profile_picture';

ensureStudentPortalRestrictionsTable($conn);
ensureLibrarianLevelColumn($conn);
normalizeLibrarianLevels($conn);

$query = "
    SELECT
        u.id,
        u.first_name,
        u.middle_name,
        u.last_name,
        u.student_id,
        u.employee_id,
        u.email,
        u.password,
        u.role,
        u.librarian_level,
        {$profilePictureSelect},
        lr.catalog_view_only,
        lr.restrict_analytics,
        lr.restrict_reports,
        lr.restrict_manage_librarians,
        spr.restrict_book_search,
        spr.restrict_book_recommendations,
        spr.restrict_book_reservations
    FROM users u
    LEFT JOIN librarian_restrictions lr ON lr.librarian_user_id = u.id
    LEFT JOIN student_portal_restrictions spr ON spr.student_user_id = u.id
    WHERE u.email = ? AND u.role = ?
    LIMIT 1
";
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
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email or password',
    ]);
    exit;
}

if ($user['role'] === 'student') {
    syncStudentPortalRestrictionAutomation($conn, (int) $user['id']);
}

$storedPassword = $user['password'];
$verified = password_verify($password, $storedPassword) || hash_equals((string) $storedPassword, (string) $password);

if (!$verified) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email or password',
    ]);
    exit;
}

$middleName = trim((string) ($user['middle_name'] ?? ''));
$name = trim($user['first_name'] . ' ' . ($middleName !== '' ? $middleName . ' ' : '') . $user['last_name']);

$catalogViewOnly = (int) ($user['catalog_view_only'] ?? 0) === 1;
$restrictAnalytics = (int) ($user['restrict_analytics'] ?? 0) === 1;
$restrictReports = (int) ($user['restrict_reports'] ?? 0) === 1;
$restrictManageLibrarians = (int) ($user['restrict_manage_librarians'] ?? 0) === 1;

$restrictBookSearch = (int) ($user['restrict_book_search'] ?? 0) === 1;
$restrictBookRecommendations = (int) ($user['restrict_book_recommendations'] ?? 0) === 1;
$restrictBookReservations = (int) ($user['restrict_book_reservations'] ?? 0) === 1;

if ($user['role'] === 'student') {
    $hasOverdueNow = countStudentOverdueBorrowings($conn, (int) $user['id']) > 0;
    if ($hasOverdueNow) {
        $restrictBookSearch = true;
        $restrictBookRecommendations = true;
        $restrictBookReservations = true;
    } else {
        $restrictBookSearch = false;
        $restrictBookRecommendations = false;
        $restrictBookReservations = false;
    }
}

$hasRestrictions = $catalogViewOnly || $restrictAnalytics || $restrictReports || $restrictManageLibrarians;
$hasStudentRestrictions = $restrictBookSearch || $restrictBookRecommendations || $restrictBookReservations;

$responseUser = [
    'id' => (string) $user['id'],
    'name' => $name,
    'email' => $user['email'],
    'role' => $user['role'],
    'librarianLevel' => $user['librarian_level'] ?? null,
    'studentLrn' => $user['student_id'],
    'employeeId' => $user['employee_id'],
    'profilePicture' => $user['profile_picture'] ?? null,
];

if ($user['role'] === 'librarian' && $hasRestrictions) {
    $responseUser['restrictions'] = [
        'catalogViewOnly' => $catalogViewOnly,
        'restrictAnalytics' => $restrictAnalytics,
        'restrictReports' => $restrictReports,
        'restrictManageLibrarians' => $restrictManageLibrarians,
    ];
}

if ($user['role'] === 'student' && $hasStudentRestrictions) {
    $responseUser['studentRestrictions'] = [
        'restrictBookSearch' => $restrictBookSearch,
        'restrictBookRecommendations' => $restrictBookRecommendations,
        'restrictBookReservations' => $restrictBookReservations,
    ];
}

echo json_encode([
    'success' => true,
    'user' => $responseUser,
]);
