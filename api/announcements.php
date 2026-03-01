<?php
require_once __DIR__ . '/cors.php';
applyCors('GET, POST, PUT, DELETE, OPTIONS');

require_once __DIR__ . '/config.php';

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function mapAnnouncementRow($row) {
    return [
        'id' => (string) $row['id'],
        'title' => $row['title'],
        'content' => $row['content'],
        'type' => $row['type'] ?? 'policy',
        'date' => isset($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : date('Y-m-d'),
    ];
}

function ensureAnnouncementsColumns($conn) {
    $checks = [
        'type' => "ALTER TABLE announcements ADD COLUMN type ENUM('new-arrival', 'policy', 'event') NOT NULL DEFAULT 'policy' AFTER content",
        'created_by' => "ALTER TABLE announcements ADD COLUMN created_by INT DEFAULT NULL AFTER type",
        'updated_at' => "ALTER TABLE announcements ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($checks as $column => $alterQuery) {
        $checkResult = mysqli_query(
            $conn,
            "SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = '{$column}'"
        );

        $row = $checkResult ? mysqli_fetch_assoc($checkResult) : null;
        $exists = (int) ($row['total'] ?? 0) > 0;

        if (!$exists) {
            mysqli_query($conn, $alterQuery);
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];
ensureAnnouncementsColumns($conn);

if ($method === 'GET') {
    $query = "
        SELECT id, title, content, type, created_at
        FROM announcements
        ORDER BY created_at DESC, id DESC
    ";

    $result = mysqli_query($conn, $query);
    if (!$result) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to fetch announcements',
        ]);
    }

    $announcements = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $announcements[] = mapAnnouncementRow($row);
    }

    respond(200, [
        'success' => true,
        'announcements' => $announcements,
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
    $title = isset($data['title']) ? trim($data['title']) : '';
    $content = isset($data['content']) ? trim($data['content']) : '';
    $type = isset($data['type']) ? trim($data['type']) : 'policy';
    $createdBy = isset($data['createdBy']) ? (int) $data['createdBy'] : null;

    $allowedTypes = ['new-arrival', 'policy', 'event'];
    if ($title === '' || $content === '' || !in_array($type, $allowedTypes, true)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid announcement payload',
        ]);
    }

    $stmt = mysqli_prepare($conn, 'INSERT INTO announcements (title, content, type, created_by) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare announcement insert query',
        ]);
    }

    mysqli_stmt_bind_param($stmt, 'sssi', $title, $content, $type, $createdBy);
    if (!mysqli_stmt_execute($stmt)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to create announcement',
        ]);
    }

    respond(201, [
        'success' => true,
        'message' => 'Announcement created successfully',
    ]);
}

if ($method === 'PUT') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $title = isset($data['title']) ? trim($data['title']) : '';
    $content = isset($data['content']) ? trim($data['content']) : '';
    $type = isset($data['type']) ? trim($data['type']) : 'policy';

    $allowedTypes = ['new-arrival', 'policy', 'event'];
    if ($id <= 0 || $title === '' || $content === '' || !in_array($type, $allowedTypes, true)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid announcement update payload',
        ]);
    }

    $stmt = mysqli_prepare($conn, 'UPDATE announcements SET title = ?, content = ?, type = ? WHERE id = ?');
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare announcement update query',
        ]);
    }

    mysqli_stmt_bind_param($stmt, 'sssi', $title, $content, $type, $id);
    if (!mysqli_stmt_execute($stmt)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to update announcement',
        ]);
    }

    if (mysqli_stmt_affected_rows($stmt) === 0) {
        respond(404, [
            'success' => false,
            'message' => 'Announcement not found',
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Announcement updated successfully',
    ]);
}

if ($method === 'DELETE') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    if ($id <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Announcement id is required',
        ]);
    }

    $stmt = mysqli_prepare($conn, 'DELETE FROM announcements WHERE id = ?');
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare announcement delete query',
        ]);
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to delete announcement',
        ]);
    }

    if (mysqli_stmt_affected_rows($stmt) === 0) {
        respond(404, [
            'success' => false,
            'message' => 'Announcement not found',
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Announcement deleted successfully',
    ]);
}

respond(405, [
    'success' => false,
    'message' => 'Method not allowed',
]);
