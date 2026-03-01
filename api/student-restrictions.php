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

function syncStudentPortalRestrictionAutomation($conn, $studentUserId) {
    $countStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total
         FROM borrowings
         WHERE user_id = ? AND (status = 'overdue' OR (status = 'active' AND due_date < CURDATE()))"
    );

    if (!$countStmt) {
        throw new Exception('Failed to prepare overdue count query');
    }

    mysqli_stmt_bind_param($countStmt, 'i', $studentUserId);
    mysqli_stmt_execute($countStmt);
    $countResult = mysqli_stmt_get_result($countStmt);
    $countRow = $countResult ? mysqli_fetch_assoc($countResult) : null;
    $overdueCount = (int) ($countRow['total'] ?? 0);

    if ($overdueCount > 0) {
        $enabled = 1;
        $upsertStmt = mysqli_prepare(
            $conn,
            'INSERT INTO student_portal_restrictions (student_user_id, restrict_book_search, restrict_book_recommendations, restrict_book_reservations) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE restrict_book_search = VALUES(restrict_book_search), restrict_book_recommendations = VALUES(restrict_book_recommendations), restrict_book_reservations = VALUES(restrict_book_reservations)'
        );

        if (!$upsertStmt) {
            throw new Exception('Failed to prepare student restriction upsert query');
        }

        mysqli_stmt_bind_param(
            $upsertStmt,
            'iiii',
            $studentUserId,
            $enabled,
            $enabled,
            $enabled
        );

        if (!mysqli_stmt_execute($upsertStmt)) {
            throw new Exception('Failed to auto-apply student restrictions');
        }

        return;
    }

    $deleteStmt = mysqli_prepare(
        $conn,
        'DELETE FROM student_portal_restrictions WHERE student_user_id = ?'
    );

    if (!$deleteStmt) {
        throw new Exception('Failed to prepare student restriction delete query');
    }

    mysqli_stmt_bind_param($deleteStmt, 'i', $studentUserId);
    if (!mysqli_stmt_execute($deleteStmt)) {
        throw new Exception('Failed to auto-clear student restrictions');
    }
}

function syncAllStudentPortalRestrictionsByOverdue($conn) {
    $studentsResult = mysqli_query($conn, "SELECT id FROM users WHERE role = 'student'");
    if (!$studentsResult) {
        return;
    }

    while ($studentRow = mysqli_fetch_assoc($studentsResult)) {
        $studentId = (int) ($studentRow['id'] ?? 0);
        if ($studentId > 0) {
            syncStudentPortalRestrictionAutomation($conn, $studentId);
        }
    }
}

function mapStudentRestrictions($row) {
    $restrictBookSearch = (int) ($row['restrict_book_search'] ?? 0) === 1;
    $restrictBookRecommendations = (int) ($row['restrict_book_recommendations'] ?? 0) === 1;
    $restrictBookReservations = (int) ($row['restrict_book_reservations'] ?? 0) === 1;

    if (!$restrictBookSearch && !$restrictBookRecommendations && !$restrictBookReservations) {
        return null;
    }

    return [
        'restrictBookSearch' => $restrictBookSearch,
        'restrictBookRecommendations' => $restrictBookRecommendations,
        'restrictBookReservations' => $restrictBookReservations,
    ];
}

function fetchStudentsWithRestrictions($conn) {
    $query = "
        SELECT
            u.id,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.student_id,
            u.email,
            spr.restrict_book_search,
            spr.restrict_book_recommendations,
            spr.restrict_book_reservations,
            COALESCE(overdue_counts.total_overdue, 0) AS overdue_count
        FROM users u
        LEFT JOIN student_portal_restrictions spr ON spr.student_user_id = u.id
        LEFT JOIN (
            SELECT user_id, COUNT(*) AS total_overdue
            FROM borrowings
            WHERE status = 'overdue' OR (status = 'active' AND due_date < CURDATE())
            GROUP BY user_id
        ) overdue_counts ON overdue_counts.user_id = u.id
        WHERE u.role = 'student'
        ORDER BY u.first_name ASC, u.last_name ASC
    ";

    $result = mysqli_query($conn, $query);
    if (!$result) {
        throw new Exception('Failed to fetch student restrictions');
    }

    $students = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $middleName = trim((string) ($row['middle_name'] ?? ''));
        $name = trim(
            ($row['first_name'] ?? '') . ' ' .
            ($middleName !== '' ? $middleName . ' ' : '') .
            ($row['last_name'] ?? '')
        );

        $students[] = [
            'id' => (string) $row['id'],
            'name' => $name,
            'studentLrn' => $row['student_id'] ?? '',
            'email' => $row['email'] ?? '',
            'overdueCount' => (int) ($row['overdue_count'] ?? 0),
            'hasOverdue' => (int) ($row['overdue_count'] ?? 0) > 0,
            'studentRestrictions' => mapStudentRestrictions($row),
        ];
    }

    return $students;
}

ensureStudentPortalRestrictionsTable($conn);
syncAllStudentPortalRestrictionsByOverdue($conn);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $students = fetchStudentsWithRestrictions($conn);
        respond(200, [
            'success' => true,
            'students' => $students,
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

if ($method === 'PUT') {
    $action = isset($data['action']) ? trim((string) $data['action']) : '';
    if ($action !== 'update-restrictions') {
        respond(400, [
            'success' => false,
            'message' => 'Invalid action',
        ]);
    }

    $studentId = isset($data['studentId']) ? (int) $data['studentId'] : 0;
    $restrictions = isset($data['restrictions']) && is_array($data['restrictions']) ? $data['restrictions'] : null;

    if ($studentId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Student id is required',
        ]);
    }

    $studentCheckStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    if (!$studentCheckStmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to validate student account',
        ]);
    }

    mysqli_stmt_bind_param($studentCheckStmt, 'i', $studentId);
    mysqli_stmt_execute($studentCheckStmt);
    $studentCheckResult = mysqli_stmt_get_result($studentCheckStmt);
    if (!$studentCheckResult || !mysqli_fetch_assoc($studentCheckResult)) {
        respond(404, [
            'success' => false,
            'message' => 'Student not found',
        ]);
    }

    $countStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total
         FROM borrowings
         WHERE user_id = ? AND (status = 'overdue' OR (status = 'active' AND due_date < CURDATE()))"
    );

    if (!$countStmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to validate overdue state',
        ]);
    }

    mysqli_stmt_bind_param($countStmt, 'i', $studentId);
    mysqli_stmt_execute($countStmt);
    $countResult = mysqli_stmt_get_result($countStmt);
    $countRow = $countResult ? mysqli_fetch_assoc($countResult) : null;
    $hasOverdue = (int) ($countRow['total'] ?? 0) > 0;

    if ($hasOverdue && !$restrictions) {
        respond(409, [
            'success' => false,
            'message' => 'Cannot remove restrictions while the student still has overdue books',
        ]);
    }

    $restrictBookSearch = $restrictions && !empty($restrictions['restrictBookSearch']) ? 1 : 0;
    $restrictBookRecommendations = $restrictions && !empty($restrictions['restrictBookRecommendations']) ? 1 : 0;
    $restrictBookReservations = $restrictions && !empty($restrictions['restrictBookReservations']) ? 1 : 0;

    mysqli_begin_transaction($conn);
    try {
        if ($restrictBookSearch || $restrictBookRecommendations || $restrictBookReservations) {
            $upsertStmt = mysqli_prepare(
                $conn,
                'INSERT INTO student_portal_restrictions (student_user_id, restrict_book_search, restrict_book_recommendations, restrict_book_reservations) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE restrict_book_search = VALUES(restrict_book_search), restrict_book_recommendations = VALUES(restrict_book_recommendations), restrict_book_reservations = VALUES(restrict_book_reservations)'
            );
            if (!$upsertStmt) {
                throw new Exception('Failed to prepare student restrictions update query');
            }

            mysqli_stmt_bind_param(
                $upsertStmt,
                'iiii',
                $studentId,
                $restrictBookSearch,
                $restrictBookRecommendations,
                $restrictBookReservations
            );

            if (!mysqli_stmt_execute($upsertStmt)) {
                throw new Exception('Failed to update student restrictions');
            }
        } else {
            $deleteStmt = mysqli_prepare($conn, 'DELETE FROM student_portal_restrictions WHERE student_user_id = ?');
            if (!$deleteStmt) {
                throw new Exception('Failed to prepare student restriction delete query');
            }

            mysqli_stmt_bind_param($deleteStmt, 'i', $studentId);
            if (!mysqli_stmt_execute($deleteStmt)) {
                throw new Exception('Failed to clear student restrictions');
            }
        }

        mysqli_commit($conn);
        $students = fetchStudentsWithRestrictions($conn);

        respond(200, [
            'success' => true,
            'message' => 'Student restrictions updated successfully',
            'students' => $students,
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        respond(500, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

respond(405, [
    'success' => false,
    'message' => 'Method not allowed',
]);
