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

function ensureBorrowDaysColumn($conn) {
    $checkQuery = "
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'books'
          AND COLUMN_NAME = 'borrow_days'
    ";

    $checkResult = mysqli_query($conn, $checkQuery);
    $row = $checkResult ? mysqli_fetch_assoc($checkResult) : null;
    $exists = (int) ($row['total'] ?? 0) > 0;

    if ($exists) {
        return;
    }

    mysqli_query(
        $conn,
        "ALTER TABLE books ADD COLUMN borrow_days INT NOT NULL DEFAULT 14 AFTER quantity"
    );
}

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function mapBookRowToResponse($row) {
    $quantity = (int) $row['quantity'];
    $borrowedQuantity = (int) ($row['active_borrowed'] ?? 0);
    $reservedCount = (int) ($row['active_reservations'] ?? 0);
    $overdueCount = (int) ($row['overdue_borrowed'] ?? 0);
    $availableQuantity = max($quantity - $borrowedQuantity, 0);

    if ($overdueCount > 0) {
        $status = 'Overdue';
    } elseif ($availableQuantity > 0) {
        $status = 'Available';
    } elseif ($reservedCount > 0) {
        $status = 'Reserved';
    } else {
        $status = 'Borrowed';
    }

    return [
        'id' => (string) $row['id'],
        'title' => $row['title'],
        'author' => $row['author'],
        'isbn' => $row['isbn'],
        'subject' => $row['subject'],
        'genre' => $row['genre'] ?? '',
        'shelfLocation' => $row['shelf_location'],
        'quantity' => $quantity,
        'borrowDays' => max((int) ($row['borrow_days'] ?? 14), 1),
        'availableQuantity' => $availableQuantity,
        'borrowedQuantity' => $borrowedQuantity,
        'reservedCount' => $reservedCount,
        'status' => $status,
        'description' => $row['description'] ?? '',
        'coverImage' => $row['cover_image'] ?? '',
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

ensureBorrowDaysColumn($conn);

if ($method === 'GET') {
    $query = "
        SELECT
            b.id,
            b.title,
            b.author,
            b.isbn,
            b.subject,
            b.genre,
            b.shelf_location,
            b.quantity,
            b.borrow_days,
            b.status,
            b.description,
            b.cover_image,
            COALESCE(SUM(CASE WHEN br.status IN ('active', 'overdue') THEN 1 ELSE 0 END), 0) AS active_borrowed,
            COALESCE(SUM(CASE WHEN br.status = 'overdue' THEN 1 ELSE 0 END), 0) AS overdue_borrowed,
            (
                SELECT COALESCE(COUNT(*), 0)
                FROM reservations r
                WHERE r.book_id = b.id AND r.status IN ('pending', 'fulfilled')
            ) AS active_reservations
        FROM books b
        LEFT JOIN borrowings br ON br.book_id = b.id
        GROUP BY
            b.id,
            b.title,
            b.author,
            b.isbn,
            b.subject,
            b.genre,
            b.shelf_location,
            b.quantity,
            b.borrow_days,
            b.status,
            b.description,
            b.cover_image
        ORDER BY b.id DESC
    ";
    $result = mysqli_query($conn, $query);

    if (!$result) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to fetch books',
        ]);
    }

    $books = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $books[] = mapBookRowToResponse($row);
    }

    respond(200, [
        'success' => true,
        'books' => $books,
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
    $author = isset($data['author']) ? trim($data['author']) : '';
    $isbn = isset($data['isbn']) ? trim($data['isbn']) : '';
    $subject = isset($data['subject']) ? trim($data['subject']) : '';
    $genre = isset($data['genre']) ? trim($data['genre']) : '';
    $shelfLocation = isset($data['shelfLocation']) ? trim($data['shelfLocation']) : '';
    $quantity = isset($data['quantity']) ? (int) $data['quantity'] : 1;
    $borrowDays = isset($data['borrowDays']) ? (int) $data['borrowDays'] : 14;
    $description = isset($data['description']) ? trim($data['description']) : '';
    $coverImage = isset($data['coverImage']) ? $data['coverImage'] : '';

    if ($title === '' || $author === '' || $isbn === '' || $subject === '' || $shelfLocation === '') {
        respond(400, [
            'success' => false,
            'message' => 'Please fill in all required book fields',
        ]);
    }

    if ($quantity < 1) {
        $quantity = 1;
    }

    if ($borrowDays < 1) {
        $borrowDays = 1;
    }

    $status = 'Available';
    $insertQuery = 'INSERT INTO books (title, author, isbn, subject, genre, shelf_location, quantity, borrow_days, status, description, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = mysqli_prepare($conn, $insertQuery);

    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare insert query',
        ]);
    }

    mysqli_stmt_bind_param(
        $stmt,
        'ssssssiisss',
        $title,
        $author,
        $isbn,
        $subject,
        $genre,
        $shelfLocation,
        $quantity,
        $borrowDays,
        $status,
        $description,
        $coverImage
    );

    if (!mysqli_stmt_execute($stmt)) {
        $errorCode = mysqli_errno($conn);
        if ($errorCode === 1062) {
            respond(409, [
                'success' => false,
                'message' => 'ISBN already exists',
            ]);
        }

        respond(500, [
            'success' => false,
            'message' => 'Failed to add book',
        ]);
    }

    $insertId = mysqli_insert_id($conn);
    $fetchStmt = mysqli_prepare($conn, 'SELECT id, title, author, isbn, subject, genre, shelf_location, quantity, borrow_days, status, description, cover_image FROM books WHERE id = ?');
    mysqli_stmt_bind_param($fetchStmt, 'i', $insertId);
    mysqli_stmt_execute($fetchStmt);
    $result = mysqli_stmt_get_result($fetchStmt);
    $book = $result ? mysqli_fetch_assoc($result) : null;

    respond(201, [
        'success' => true,
        'message' => 'Book added successfully',
        'book' => $book ? mapBookRowToResponse($book) : null,
    ]);
}

if ($method === 'PUT') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $title = isset($data['title']) ? trim($data['title']) : '';
    $author = isset($data['author']) ? trim($data['author']) : '';
    $isbn = isset($data['isbn']) ? trim($data['isbn']) : '';
    $subject = isset($data['subject']) ? trim($data['subject']) : '';
    $genre = isset($data['genre']) ? trim($data['genre']) : '';
    $shelfLocation = isset($data['shelfLocation']) ? trim($data['shelfLocation']) : '';
    $quantity = isset($data['quantity']) ? (int) $data['quantity'] : 1;
    $borrowDays = isset($data['borrowDays']) ? (int) $data['borrowDays'] : 14;
    $description = isset($data['description']) ? trim($data['description']) : '';
    $coverImage = isset($data['coverImage']) ? $data['coverImage'] : '';
    $status = isset($data['status']) ? trim($data['status']) : 'Available';

    if ($id <= 0 || $title === '' || $author === '' || $isbn === '' || $subject === '' || $shelfLocation === '') {
        respond(400, [
            'success' => false,
            'message' => 'Invalid update payload',
        ]);
    }

    if ($quantity < 1) {
        $quantity = 1;
    }

    if ($borrowDays < 1) {
        $borrowDays = 1;
    }

    $allowedStatuses = ['Available', 'Borrowed', 'Reserved', 'Overdue'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'Available';
    }

    $updateQuery = 'UPDATE books SET title = ?, author = ?, isbn = ?, subject = ?, genre = ?, shelf_location = ?, quantity = ?, borrow_days = ?, status = ?, description = ?, cover_image = ? WHERE id = ?';
    $stmt = mysqli_prepare($conn, $updateQuery);

    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare update query',
        ]);
    }

    mysqli_stmt_bind_param(
        $stmt,
        'ssssssiisssi',
        $title,
        $author,
        $isbn,
        $subject,
        $genre,
        $shelfLocation,
        $quantity,
        $borrowDays,
        $status,
        $description,
        $coverImage,
        $id
    );

    if (!mysqli_stmt_execute($stmt)) {
        $errorCode = mysqli_errno($conn);
        if ($errorCode === 1062) {
            respond(409, [
                'success' => false,
                'message' => 'ISBN already exists',
            ]);
        }

        respond(500, [
            'success' => false,
            'message' => 'Failed to update book',
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Book updated successfully',
    ]);
}

if ($method === 'DELETE') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    if ($id <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Book id is required',
        ]);
    }

    $stmt = mysqli_prepare($conn, 'DELETE FROM books WHERE id = ?');
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare delete query',
        ]);
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        respond(500, [
            'success' => false,
            'message' => 'Book is linked to transactions and cannot be deleted',
        ]);
    }

    if (mysqli_stmt_affected_rows($stmt) === 0) {
        respond(404, [
            'success' => false,
            'message' => 'Book not found',
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Book deleted successfully',
    ]);
}

respond(405, [
    'success' => false,
    'message' => 'Method not allowed',
]);
