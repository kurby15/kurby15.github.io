<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

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

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function buildRestrictions($row) {
    $catalog = (int) ($row['catalog_view_only'] ?? 0) === 1;
    $analytics = (int) ($row['restrict_analytics'] ?? 0) === 1;
    $reports = (int) ($row['restrict_reports'] ?? 0) === 1;
    $manage = (int) ($row['restrict_manage_librarians'] ?? 0) === 1;

    if (!$catalog && !$analytics && !$reports && !$manage) {
        return null;
    }

    return [
        'catalogViewOnly' => $catalog,
        'restrictAnalytics' => $analytics,
        'restrictReports' => $reports,
        'restrictManageLibrarians' => $manage,
    ];
}

function ensureActorIsHeadLibrarian($conn, $actorId) {
    if ($actorId <= 0) {
        respond(403, [
            'success' => false,
            'message' => 'Only head librarian can manage librarian accounts',
        ]);
    }

    $actorStmt = mysqli_prepare(
        $conn,
        "SELECT role, librarian_level FROM users WHERE id = ? LIMIT 1"
    );

    if (!$actorStmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to validate actor permissions',
        ]);
    }

    mysqli_stmt_bind_param($actorStmt, 'i', $actorId);
    mysqli_stmt_execute($actorStmt);
    $actorResult = mysqli_stmt_get_result($actorStmt);
    $actor = $actorResult ? mysqli_fetch_assoc($actorResult) : null;

    $isHeadLibrarian =
        $actor &&
        ($actor['role'] ?? '') === 'librarian' &&
        ($actor['librarian_level'] ?? '') === 'head_librarian';

    if (!$isHeadLibrarian) {
        respond(403, [
            'success' => false,
            'message' => 'Only head librarian can manage librarian accounts',
        ]);
    }
}

function fetchLibrarians($conn) {
    $query = "
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.password,
            u.profile_picture,
            u.librarian_level,
            lr.catalog_view_only,
            lr.restrict_analytics,
            lr.restrict_reports,
            lr.restrict_manage_librarians
        FROM users u
        LEFT JOIN librarian_restrictions lr ON lr.librarian_user_id = u.id
        WHERE u.role = 'librarian'
        ORDER BY u.first_name ASC, u.last_name ASC
    ";

    $result = mysqli_query($conn, $query);
    if (!$result) {
        throw new Exception('Failed to fetch librarians');
    }

    $librarians = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $librarians[] = [
            'id' => (string) $row['id'],
            'firstName' => $row['first_name'],
            'lastName' => $row['last_name'],
            'email' => $row['email'],
            'password' => $row['password'],
            'role' => 'librarian',
            'profilePicture' => $row['profile_picture'] ?? '',
            'librarianLevel' => $row['librarian_level'] ?? 'librarian_staff',
            'restrictions' => buildRestrictions($row),
        ];
    }

    return $librarians;
}

$method = $_SERVER['REQUEST_METHOD'];
ensureLibrarianLevelColumn($conn);

if ($method === 'GET') {
    try {
        $librarians = fetchLibrarians($conn);
        respond(200, [
            'success' => true,
            'librarians' => $librarians,
        ]);
    } catch (Exception $e) {
        respond(500, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Invalid JSON payload',
    ]);
}

if ($method === 'POST') {
    $firstName = isset($data['firstName']) ? trim($data['firstName']) : '';
    $middleNameRaw = isset($data['middleName']) ? trim((string) $data['middleName']) : '';
    $lastName = isset($data['lastName']) ? trim($data['lastName']) : '';
    $employeeId = isset($data['employeeId']) ? trim((string) $data['employeeId']) : '';
    $email = isset($data['email']) ? trim($data['email']) : '';
    $password = isset($data['password']) ? $data['password'] : '';
    $librarianLevel = isset($data['librarianLevel']) ? trim((string) $data['librarianLevel']) : 'librarian_staff';
    $actorId = isset($data['actorId']) ? (int) $data['actorId'] : 0;
    $restrictions = isset($data['restrictions']) && is_array($data['restrictions']) ? $data['restrictions'] : null;

    $allowedLibrarianLevels = ['head_librarian', 'librarian_staff'];

    if ($firstName === '' || $lastName === '' || $employeeId === '' || $email === '' || $password === '') {
        respond(400, [
            'success' => false,
            'message' => 'Please fill in all required fields',
        ]);
    }

    if (!in_array($librarianLevel, $allowedLibrarianLevels, true)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid librarian level',
        ]);
    }

    ensureActorIsHeadLibrarian($conn, $actorId);

    $checkStmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE email = ? LIMIT 1');
    mysqli_stmt_bind_param($checkStmt, 's', $email);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    if ($checkResult && mysqli_fetch_assoc($checkResult)) {
        respond(409, [
            'success' => false,
            'message' => 'Email already exists',
        ]);
    }

    $employeeCheckStmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE employee_id = ? LIMIT 1');
    mysqli_stmt_bind_param($employeeCheckStmt, 's', $employeeId);
    mysqli_stmt_execute($employeeCheckStmt);
    $employeeCheckResult = mysqli_stmt_get_result($employeeCheckStmt);
    if ($employeeCheckResult && mysqli_fetch_assoc($employeeCheckResult)) {
        respond(409, [
            'success' => false,
            'message' => 'Employee ID already exists',
        ]);
    }

    mysqli_begin_transaction($conn);
    try {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $middleName = $middleNameRaw !== '' ? $middleNameRaw : null;
        $studentId = null;
        $role = 'librarian';

        $insertStmt = mysqli_prepare(
            $conn,
            'INSERT INTO users (first_name, middle_name, last_name, student_id, employee_id, email, password, role, librarian_level) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insertStmt) {
            throw new Exception('Failed to prepare librarian insert query');
        }

        mysqli_stmt_bind_param(
            $insertStmt,
            'sssssssss',
            $firstName,
            $middleName,
            $lastName,
            $studentId,
            $employeeId,
            $email,
            $passwordHash,
            $role,
            $librarianLevel
        );

        if (!mysqli_stmt_execute($insertStmt)) {
            throw new Exception('Failed to create librarian account');
        }

        $librarianId = mysqli_insert_id($conn);

        if ($restrictions) {
            $catalog = !empty($restrictions['catalogViewOnly']) ? 1 : 0;
            $analytics = !empty($restrictions['restrictAnalytics']) ? 1 : 0;
            $reports = !empty($restrictions['restrictReports']) ? 1 : 0;
            $manage = !empty($restrictions['restrictManageLibrarians']) ? 1 : 0;

            if ($catalog || $analytics || $reports || $manage) {
                $restrictionStmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO librarian_restrictions (librarian_user_id, catalog_view_only, restrict_analytics, restrict_reports, restrict_manage_librarians) VALUES (?, ?, ?, ?, ?)'
                );
                if (!$restrictionStmt) {
                    throw new Exception('Failed to prepare librarian restriction insert query');
                }

                mysqli_stmt_bind_param($restrictionStmt, 'iiiii', $librarianId, $catalog, $analytics, $reports, $manage);
                if (!mysqli_stmt_execute($restrictionStmt)) {
                    throw new Exception('Failed to save librarian restrictions');
                }
            }
        }

        mysqli_commit($conn);
        $librarians = fetchLibrarians($conn);

        respond(201, [
            'success' => true,
            'message' => 'Librarian added successfully',
            'librarians' => $librarians,
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        respond(500, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

if ($method === 'PUT') {
    $action = isset($data['action']) ? trim($data['action']) : '';
    if ($action !== 'update-restrictions') {
        respond(400, [
            'success' => false,
            'message' => 'Invalid action',
        ]);
    }

    $librarianId = isset($data['librarianId']) ? (int) $data['librarianId'] : 0;
    $actorId = isset($data['actorId']) ? (int) $data['actorId'] : 0;
    $restrictions = isset($data['restrictions']) && is_array($data['restrictions']) ? $data['restrictions'] : null;

    ensureActorIsHeadLibrarian($conn, $actorId);

    if ($librarianId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Librarian id is required',
        ]);
    }

    $checkStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ? AND role = 'librarian' LIMIT 1");
    mysqli_stmt_bind_param($checkStmt, 'i', $librarianId);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    if (!$checkResult || !mysqli_fetch_assoc($checkResult)) {
        respond(404, [
            'success' => false,
            'message' => 'Librarian not found',
        ]);
    }

    mysqli_begin_transaction($conn);
    try {
        $catalog = $restrictions && !empty($restrictions['catalogViewOnly']) ? 1 : 0;
        $analytics = $restrictions && !empty($restrictions['restrictAnalytics']) ? 1 : 0;
        $reports = $restrictions && !empty($restrictions['restrictReports']) ? 1 : 0;
        $manage = $restrictions && !empty($restrictions['restrictManageLibrarians']) ? 1 : 0;

        if ($catalog || $analytics || $reports || $manage) {
            $upsertStmt = mysqli_prepare(
                $conn,
                'INSERT INTO librarian_restrictions (librarian_user_id, catalog_view_only, restrict_analytics, restrict_reports, restrict_manage_librarians) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE catalog_view_only = VALUES(catalog_view_only), restrict_analytics = VALUES(restrict_analytics), restrict_reports = VALUES(restrict_reports), restrict_manage_librarians = VALUES(restrict_manage_librarians)'
            );
            if (!$upsertStmt) {
                throw new Exception('Failed to prepare restrictions update query');
            }

            mysqli_stmt_bind_param($upsertStmt, 'iiiii', $librarianId, $catalog, $analytics, $reports, $manage);
            if (!mysqli_stmt_execute($upsertStmt)) {
                throw new Exception('Failed to update restrictions');
            }
        } else {
            $deleteRestrictionStmt = mysqli_prepare($conn, 'DELETE FROM librarian_restrictions WHERE librarian_user_id = ?');
            if (!$deleteRestrictionStmt) {
                throw new Exception('Failed to prepare restriction delete query');
            }
            mysqli_stmt_bind_param($deleteRestrictionStmt, 'i', $librarianId);
            if (!mysqli_stmt_execute($deleteRestrictionStmt)) {
                throw new Exception('Failed to clear restrictions');
            }
        }

        mysqli_commit($conn);
        $librarians = fetchLibrarians($conn);

        respond(200, [
            'success' => true,
            'message' => 'Restrictions updated successfully',
            'librarians' => $librarians,
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        respond(500, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

if ($method === 'DELETE') {
    $librarianId = isset($data['librarianId']) ? (int) $data['librarianId'] : 0;
    $actorId = isset($data['actorId']) ? (int) $data['actorId'] : 0;
    if ($librarianId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Librarian id is required',
        ]);
    }

    ensureActorIsHeadLibrarian($conn, $actorId);

    $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ? AND role = 'librarian'");
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare delete query',
        ]);
    }

    mysqli_stmt_bind_param($stmt, 'i', $librarianId);
    if (!mysqli_stmt_execute($stmt)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to remove librarian',
        ]);
    }

    if (mysqli_stmt_affected_rows($stmt) === 0) {
        respond(404, [
            'success' => false,
            'message' => 'Librarian not found',
        ]);
    }

    try {
        $librarians = fetchLibrarians($conn);
    } catch (Exception $e) {
        respond(500, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Librarian removed successfully',
        'librarians' => $librarians,
    ]);
}

respond(405, [
    'success' => false,
    'message' => 'Method not allowed',
]);
