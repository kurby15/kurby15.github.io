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

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function mapNotificationRow($row) {
    return [
        'id' => (string) $row['id'],
        'type' => $row['type'],
        'title' => $row['title'],
        'message' => $row['message'],
        'timestamp' => $row['created_at'],
        'read' => (int) $row['is_read'] === 1,
        'userRole' => $row['role'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $userId = isset($_GET['userId']) ? (int) $_GET['userId'] : 0;
    $userRole = isset($_GET['userRole']) ? trim($_GET['userRole']) : '';
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;

    if ($limit < 1) $limit = 1;
    if ($limit > 500) $limit = 500;

    $query = "
        SELECT n.id, n.type, n.title, n.message, n.is_read, n.created_at, u.role
        FROM notifications n
        INNER JOIN users u ON u.id = n.user_id
    ";

    $conditions = [];
    $types = '';
    $params = [];

    if ($userId > 0) {
        $conditions[] = 'n.user_id = ?';
        $types .= 'i';
        $params[] = $userId;
    }

    if ($userRole !== '') {
        $conditions[] = 'u.role = ?';
        $types .= 's';
        $params[] = $userRole;
    }

    if (count($conditions) > 0) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $query .= ' ORDER BY n.created_at DESC, n.id DESC LIMIT ?';
    $types .= 'i';
    $params[] = $limit;

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare notifications query',
        ]);
    }

    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $notifications = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $notifications[] = mapNotificationRow($row);
    }

    respond(200, [
        'success' => true,
        'notifications' => $notifications,
    ]);
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
    $type = isset($data['type']) ? trim($data['type']) : '';
    $title = isset($data['title']) ? trim($data['title']) : '';
    $message = isset($data['message']) ? trim($data['message']) : '';
    $userRole = isset($data['userRole']) ? trim($data['userRole']) : '';
    $targetUserId = isset($data['userId']) ? (int) $data['userId'] : 0;

    $allowedTypes = ['reservation', 'return', 'overdue', 'request', 'ready', 'approved', 'borrowed', 'announcement'];
    $allowedRoles = ['student', 'teacher', 'librarian'];

    if ($type === '' || $title === '' || $message === '' || !in_array($type, $allowedTypes, true)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid notification payload',
        ]);
    }

    mysqli_begin_transaction($conn);
    try {
        $targetUserIds = [];

        if ($targetUserId > 0) {
            $targetUserIds[] = $targetUserId;
        } else {
            if (!in_array($userRole, $allowedRoles, true)) {
                throw new Exception('Invalid user role for notification');
            }

            $userStmt = mysqli_prepare($conn, 'SELECT id FROM users WHERE role = ?');
            if (!$userStmt) {
                throw new Exception('Failed to prepare users lookup query');
            }
            mysqli_stmt_bind_param($userStmt, 's', $userRole);
            mysqli_stmt_execute($userStmt);
            $userResult = mysqli_stmt_get_result($userStmt);
            while ($row = mysqli_fetch_assoc($userResult)) {
                $targetUserIds[] = (int) $row['id'];
            }
        }

        if (count($targetUserIds) === 0) {
            throw new Exception('No target users found for notification');
        }

        $insertStmt = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, type, title, message, is_read) VALUES (?, ?, ?, ?, 0)');
        if (!$insertStmt) {
            throw new Exception('Failed to prepare notification insert query');
        }

        $dedupeStmt = mysqli_prepare($conn, "
            SELECT id
            FROM notifications
            WHERE user_id = ?
              AND type = ?
              AND title = ?
              AND message = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
            LIMIT 1
        ");
        if (!$dedupeStmt) {
            throw new Exception('Failed to prepare notification dedupe query');
        }

        foreach ($targetUserIds as $uid) {
            mysqli_stmt_bind_param($dedupeStmt, 'isss', $uid, $type, $title, $message);
            mysqli_stmt_execute($dedupeStmt);
            $dedupeResult = mysqli_stmt_get_result($dedupeStmt);
            if ($dedupeResult && mysqli_fetch_assoc($dedupeResult)) {
                continue;
            }

            mysqli_stmt_bind_param($insertStmt, 'isss', $uid, $type, $title, $message);
            if (!mysqli_stmt_execute($insertStmt)) {
                throw new Exception('Failed to create notification');
            }
        }

        mysqli_commit($conn);
        respond(201, [
            'success' => true,
            'message' => 'Notification created successfully',
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
    $action = isset($data['action']) ? trim($data['action']) : '';
    $notificationId = isset($data['id']) ? (int) $data['id'] : 0;
    $userId = isset($data['userId']) ? (int) $data['userId'] : 0;
    $userRole = isset($data['userRole']) ? trim($data['userRole']) : '';

    if ($action === 'delete-one') {
        if ($notificationId <= 0) {
            respond(400, [
                'success' => false,
                'message' => 'Notification id is required',
            ]);
        }

        if ($userId > 0) {
            $stmt = mysqli_prepare($conn, 'DELETE FROM notifications WHERE id = ? AND user_id = ?');
            mysqli_stmt_bind_param($stmt, 'ii', $notificationId, $userId);
        } else {
            $stmt = mysqli_prepare($conn, 'DELETE FROM notifications WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $notificationId);
        }

        if (!$stmt || !mysqli_stmt_execute($stmt)) {
            respond(500, [
                'success' => false,
                'message' => 'Failed to delete notification',
            ]);
        }

        respond(200, [
            'success' => true,
            'message' => 'Notification deleted',
        ]);
    }

    if ($action === 'delete-all') {
        if ($userId > 0) {
            $stmt = mysqli_prepare($conn, 'DELETE FROM notifications WHERE user_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $userId);
        } else {
            if ($userRole === '') {
                respond(400, [
                    'success' => false,
                    'message' => 'userId or userRole is required',
                ]);
            }

            $stmt = mysqli_prepare($conn, 'DELETE n FROM notifications n INNER JOIN users u ON u.id = n.user_id WHERE u.role = ?');
            mysqli_stmt_bind_param($stmt, 's', $userRole);
        }

        if (!$stmt || !mysqli_stmt_execute($stmt)) {
            respond(500, [
                'success' => false,
                'message' => 'Failed to delete all notifications',
            ]);
        }

        respond(200, [
            'success' => true,
            'message' => 'All notifications deleted',
        ]);
    }

    respond(400, [
        'success' => false,
        'message' => 'Invalid action',
    ]);
}

if ($method === 'PUT') {
    $action = isset($data['action']) ? trim($data['action']) : '';
    $notificationId = isset($data['id']) ? (int) $data['id'] : 0;
    $userId = isset($data['userId']) ? (int) $data['userId'] : 0;
    $userRole = isset($data['userRole']) ? trim($data['userRole']) : '';

    if ($action === 'mark-read') {
        if ($notificationId <= 0) {
            respond(400, [
                'success' => false,
                'message' => 'Notification id is required',
            ]);
        }

        if ($userId > 0) {
            $stmt = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
            mysqli_stmt_bind_param($stmt, 'ii', $notificationId, $userId);
        } else {
            $stmt = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $notificationId);
        }

        if (!$stmt || !mysqli_stmt_execute($stmt)) {
            respond(500, [
                'success' => false,
                'message' => 'Failed to mark notification as read',
            ]);
        }

        respond(200, [
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }

    if ($action === 'mark-all-read') {
        if ($userId > 0) {
            $stmt = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE user_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $userId);
        } else {
            if ($userRole === '') {
                respond(400, [
                    'success' => false,
                    'message' => 'userId or userRole is required',
                ]);
            }

            $stmt = mysqli_prepare($conn, 'UPDATE notifications n INNER JOIN users u ON u.id = n.user_id SET n.is_read = 1 WHERE u.role = ?');
            mysqli_stmt_bind_param($stmt, 's', $userRole);
        }

        if (!$stmt || !mysqli_stmt_execute($stmt)) {
            respond(500, [
                'success' => false,
                'message' => 'Failed to mark all notifications as read',
            ]);
        }

        respond(200, [
            'success' => true,
            'message' => 'All notifications marked as read',
        ]);
    }

    respond(400, [
        'success' => false,
        'message' => 'Invalid action',
    ]);
}

respond(405, [
    'success' => false,
    'message' => 'Method not allowed',
]);
