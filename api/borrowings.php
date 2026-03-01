<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

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

function markActiveBorrowingsAsOverdue($conn) {
    mysqli_query(
        $conn,
        "UPDATE borrowings
         SET status = 'overdue'
         WHERE status = 'active' AND due_date < CURDATE()"
    );
}

function insertNotificationIfNotExists($conn, $userId, $type, $title, $message, $intervalHours = 24) {
    $dedupeStmt = mysqli_prepare(
        $conn,
        "SELECT id
         FROM notifications
         WHERE user_id = ?
           AND type = ?
           AND title = ?
           AND message = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
         LIMIT 1"
    );

    if (!$dedupeStmt) {
        return false;
    }

    mysqli_stmt_bind_param($dedupeStmt, 'isssi', $userId, $type, $title, $message, $intervalHours);
    mysqli_stmt_execute($dedupeStmt);
    $dedupeResult = mysqli_stmt_get_result($dedupeStmt);
    if ($dedupeResult && mysqli_fetch_assoc($dedupeResult)) {
        return false;
    }

    $insertStmt = mysqli_prepare(
        $conn,
        'INSERT INTO notifications (user_id, type, title, message, is_read) VALUES (?, ?, ?, ?, 0)'
    );
    if (!$insertStmt) {
        return false;
    }

    mysqli_stmt_bind_param($insertStmt, 'isss', $userId, $type, $title, $message);
    return mysqli_stmt_execute($insertStmt) === true;
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

    $restrictionCheckStmt = mysqli_prepare(
        $conn,
        'SELECT restrict_book_search, restrict_book_recommendations, restrict_book_reservations FROM student_portal_restrictions WHERE student_user_id = ? LIMIT 1'
    );
    if (!$restrictionCheckStmt) {
        throw new Exception('Failed to prepare current restriction check query');
    }

    mysqli_stmt_bind_param($restrictionCheckStmt, 'i', $studentUserId);
    mysqli_stmt_execute($restrictionCheckStmt);
    $restrictionResult = mysqli_stmt_get_result($restrictionCheckStmt);
    $restrictionRow = $restrictionResult ? mysqli_fetch_assoc($restrictionResult) : null;

    $wasFullyRestricted =
        $restrictionRow &&
        (int) ($restrictionRow['restrict_book_search'] ?? 0) === 1 &&
        (int) ($restrictionRow['restrict_book_recommendations'] ?? 0) === 1 &&
        (int) ($restrictionRow['restrict_book_reservations'] ?? 0) === 1;

    $hadAnyRestriction =
        $restrictionRow && (
            (int) ($restrictionRow['restrict_book_search'] ?? 0) === 1 ||
            (int) ($restrictionRow['restrict_book_recommendations'] ?? 0) === 1 ||
            (int) ($restrictionRow['restrict_book_reservations'] ?? 0) === 1
        );

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

        if (!$wasFullyRestricted) {
            $title = 'Student Account Restricted';
            $message = 'Your account has been automatically restricted because you have overdue borrowed books. Please return overdue books to restore full access.';
            insertNotificationIfNotExists($conn, $studentUserId, 'overdue', $title, $message, 24);
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

    if ($hadAnyRestriction) {
        $title = 'Student Account Restriction Removed';
        $message = 'Your account is no longer restricted. You now have full access to search books, view recommendations, and place reservations.';
        insertNotificationIfNotExists($conn, $studentUserId, 'overdue', $title, $message, 24);
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

function isStudentPortalAccountRestricted($conn, $studentUserId) {
    if ($studentUserId <= 0) {
        return false;
    }

    $restrictionStmt = mysqli_prepare(
        $conn,
        'SELECT restrict_book_search, restrict_book_recommendations, restrict_book_reservations FROM student_portal_restrictions WHERE student_user_id = ? LIMIT 1'
    );

    if (!$restrictionStmt) {
        return false;
    }

    mysqli_stmt_bind_param($restrictionStmt, 'i', $studentUserId);
    mysqli_stmt_execute($restrictionStmt);
    $restrictionResult = mysqli_stmt_get_result($restrictionStmt);
    $restrictionRow = $restrictionResult ? mysqli_fetch_assoc($restrictionResult) : null;

    if (!$restrictionRow) {
        return false;
    }

    return
        (int) ($restrictionRow['restrict_book_search'] ?? 0) === 1 ||
        (int) ($restrictionRow['restrict_book_recommendations'] ?? 0) === 1 ||
        (int) ($restrictionRow['restrict_book_reservations'] ?? 0) === 1;
}

function resolveLibrarianLevelById($conn, $librarianId) {
    if ($librarianId <= 0) {
        return '';
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT librarian_level FROM users WHERE id = ? AND role = 'librarian' LIMIT 1"
    );

    if (!$stmt) {
        return '';
    }

    mysqli_stmt_bind_param($stmt, 'i', $librarianId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    return trim((string) ($row['librarian_level'] ?? ''));
}

function resolveSignatureRoleLabel($librarianLevel, $librarianRole, $configuredSignatureRole) {
    $normalizedLevel = strtolower(trim((string) $librarianLevel));
    if ($normalizedLevel === 'head_librarian') {
        return 'Head Librarian';
    }

    if ($normalizedLevel === 'librarian') {
        return 'Librarian';
    }

    $normalizedRole = strtolower(trim((string) $librarianRole));
    if ($normalizedRole === 'head_librarian') {
        return 'Head Librarian';
    }

    if ($normalizedRole === 'librarian') {
        return 'Librarian';
    }

    $fallbackRole = trim((string) $configuredSignatureRole);
    return $fallbackRole !== '' ? $fallbackRole : 'Librarian';
}

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function sendBorrowingReturnedEmailWithPhpMailer($toEmail, $recipientName, $bookTitle, $librarianName, $librarianRole, $librarianLevel, $mailConfig, $isOverdueReturn = false, $wasRestrictedBefore = false, $restrictionRemovedAfterReturn = false) {
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return [
            'success' => false,
            'message' => 'PHPMailer is not installed. Run: composer require phpmailer/phpmailer',
        ];
    }

    $smtpHost = isset($mailConfig['host']) ? trim((string) $mailConfig['host']) : '';
    $smtpUsername = isset($mailConfig['username']) ? trim((string) $mailConfig['username']) : '';
    $smtpPassword = isset($mailConfig['password']) ? trim((string) $mailConfig['password']) : '';
    $smtpPort = isset($mailConfig['port']) ? (int) $mailConfig['port'] : 587;
    $smtpEncryption = isset($mailConfig['encryption']) ? strtolower(trim((string) $mailConfig['encryption'])) : 'tls';
    $fromAddress = isset($mailConfig['fromAddress']) ? trim((string) $mailConfig['fromAddress']) : 'no-reply@sanmartinlibrary.local';
    $fromName = isset($mailConfig['fromName']) ? trim((string) $mailConfig['fromName']) : 'San Martin Library';
    $configuredSignatureName = isset($mailConfig['signatureName']) ? trim((string) $mailConfig['signatureName']) : '';
    $configuredSignatureRole = isset($mailConfig['signatureRole']) ? trim((string) $mailConfig['signatureRole']) : '';
    $signatureName = trim((string) $librarianName);
    $signatureRole = resolveSignatureRoleLabel($librarianLevel, $librarianRole, $configuredSignatureRole);
    if ($signatureName === '') {
        $signatureName = $configuredSignatureName !== '' ? $configuredSignatureName : $fromName;
    }

    if ($smtpHost === '' || $smtpUsername === '' || $smtpPassword === '') {
        return [
            'success' => false,
            'message' => 'SMTP is not configured. Set MAIL_HOST, MAIL_USERNAME, and MAIL_PASSWORD.',
        ];
    }

    if (stripos($smtpHost, 'smtp.gmail.com') !== false) {
        $smtpPassword = str_replace(' ', '', $smtpPassword);

        if (stripos($smtpUsername, '@gmail.com') === false) {
            return [
                'success' => false,
                'message' => 'Gmail SMTP requires a Gmail account in username.',
            ];
        }

        if (strcasecmp($fromAddress, $smtpUsername) !== 0) {
            return [
                'success' => false,
                'message' => 'For Gmail SMTP, fromAddress must match SMTP username.',
            ];
        }
    }

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->Port = $smtpPort;

        if ($smtpEncryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Library Return Confirmation';

        $safeRecipientName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $safeBookTitle = htmlspecialchars($bookTitle, ENT_QUOTES, 'UTF-8');
        $safeSignatureName = htmlspecialchars($signatureName, ENT_QUOTES, 'UTF-8');
        $safeSignatureRole = htmlspecialchars($signatureRole, ENT_QUOTES, 'UTF-8');
        $processedDate = date('Y-m-d');
        $hasStatusNote = $isOverdueReturn || $wasRestrictedBefore;

        $statusHtml = '';
        $statusText = '';

        if ($hasStatusNote) {
            if ($restrictionRemovedAfterReturn) {
                $statusHtml = '<p>This return was processed from an overdue/restricted account. Your account restriction has now been removed, and your full student portal access is restored.</p>';
                $statusText = "This return was processed from an overdue/restricted account. Your account restriction has now been removed, and your full student portal access is restored.\n\n";
            } else {
                $statusHtml = '<p>This return was processed from an overdue/restricted account. Your account is still restricted because there are remaining overdue items. Please return all overdue books to restore full access.</p>';
                $statusText = "This return was processed from an overdue/restricted account. Your account is still restricted because there are remaining overdue items. Please return all overdue books to restore full access.\n\n";
            }
        }

        $mail->Body = "<p>Dear {$safeRecipientName},</p>"
            . "<p>We are pleased to confirm that your returned library material has been received and successfully processed.</p>"
            . "<p><strong>Book Title:</strong> {$safeBookTitle}<br>"
            . "<strong>Date Processed:</strong> {$processedDate}</p>"
            . $statusHtml
            . "<p>Thank you for returning your borrowed item on time. If you have any questions regarding your account or borrowing records, please contact the library.</p>"
            . "<p>Sincerely,<br><strong>{$safeSignatureName}</strong><br>{$safeSignatureRole}</p>";

        $mail->AltBody = "Dear {$recipientName},\n\n"
            . "We are pleased to confirm that your returned library material has been received and successfully processed.\n\n"
            . "Book Title: {$bookTitle}\n"
            . "Date Processed: {$processedDate}\n\n"
            . $statusText
            . "Thank you for returning your borrowed item on time. If you have any questions regarding your account or borrowing records, please contact the library.\n\n"
            . "Sincerely,\n"
            . "{$signatureName}\n"
            . "{$signatureRole}";

        $mail->send();

        return [
            'success' => true,
            'message' => 'Return confirmation email sent',
        ];
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();

        if (stripos($smtpHost, 'smtp.gmail.com') !== false && stripos($errorMessage, 'Could not authenticate') !== false) {
            return [
                'success' => false,
                'message' => 'Gmail SMTP auth failed. Use a Google App Password (16 characters), enable 2-Step Verification, and set fromAddress equal to username.',
            ];
        }

        return [
            'success' => false,
            'message' => 'PHPMailer error: ' . $errorMessage,
        ];
    }
}

function mapStatusToUi($status) {
    switch ($status) {
        case 'active':
            return 'Active';
        case 'returned':
            return 'Returned';
        case 'overdue':
            return 'Overdue';
        default:
            return 'Active';
    }
}

function mapBorrowRowToResponse($row) {
    $middleName = trim((string) ($row['middle_name'] ?? ''));
    $userName = trim(
        ($row['first_name'] ?? '') . ' ' .
        ($middleName !== '' ? $middleName . ' ' : '') .
        ($row['last_name'] ?? '')
    );

    return [
        'id' => (string) $row['id'],
        'bookId' => (string) $row['book_id'],
        'bookTitle' => $row['book_title'],
        'userId' => (string) $row['user_id'],
        'userName' => $userName,
        'studentLrn' => $row['student_id'] ?? '',
        'borrowDate' => $row['borrow_date'],
        'dueDate' => $row['due_date'],
        'borrowDays' => max((int) ($row['borrow_days'] ?? 14), 1),
        'returnDate' => $row['return_date'],
        'status' => mapStatusToUi($row['status']),
    ];
}

function getBookCirculationStats($conn, $bookId) {
    $stmt = mysqli_prepare($conn, "
        SELECT
            b.quantity,
            COALESCE(SUM(CASE WHEN br.status IN ('active', 'overdue') THEN 1 ELSE 0 END), 0) AS active_borrowed,
            COALESCE(SUM(CASE WHEN br.status = 'overdue' THEN 1 ELSE 0 END), 0) AS overdue_borrowed,
            (
                SELECT COALESCE(COUNT(*), 0)
                FROM reservations r
                WHERE r.book_id = b.id AND r.status IN ('pending', 'fulfilled')
            ) AS active_reservations
        FROM books b
        LEFT JOIN borrowings br ON br.book_id = b.id
        WHERE b.id = ?
        GROUP BY b.id, b.quantity
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Failed to prepare circulation stats query');
    }

    mysqli_stmt_bind_param($stmt, 'i', $bookId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $stats = $result ? mysqli_fetch_assoc($result) : null;

    if (!$stats) {
        return null;
    }

    return [
        'quantity' => (int) $stats['quantity'],
        'activeBorrowed' => (int) $stats['active_borrowed'],
        'overdueBorrowed' => (int) $stats['overdue_borrowed'],
        'activeReservations' => (int) $stats['active_reservations'],
    ];
}

function resolveBookStatusFromStats($stats) {
    if ($stats['overdueBorrowed'] > 0) {
        return 'Overdue';
    }

    $availableQuantity = $stats['quantity'] - $stats['activeBorrowed'];
    if ($availableQuantity > 0) {
        return 'Available';
    }

    if ($stats['activeReservations'] > 0) {
        return 'Reserved';
    }

    return 'Borrowed';
}

function refreshBookStatus($conn, $bookId) {
    $stats = getBookCirculationStats($conn, $bookId);
    if (!$stats) {
        throw new Exception('Book not found');
    }

    $status = resolveBookStatusFromStats($stats);
    $updateStmt = mysqli_prepare($conn, 'UPDATE books SET status = ? WHERE id = ?');
    if (!$updateStmt) {
        throw new Exception('Failed to prepare book status update query');
    }

    mysqli_stmt_bind_param($updateStmt, 'si', $status, $bookId);
    if (!mysqli_stmt_execute($updateStmt)) {
        throw new Exception('Failed to update book status');
    }
}

$method = $_SERVER['REQUEST_METHOD'];

ensureBorrowDaysColumn($conn);
ensureStudentPortalRestrictionsTable($conn);

if ($method === 'GET') {
    markActiveBorrowingsAsOverdue($conn);
    syncAllStudentPortalRestrictionsByOverdue($conn);

    $userId = isset($_GET['userId']) ? (int) $_GET['userId'] : 0;

    $query = "
        SELECT
            br.id,
            br.user_id,
            br.book_id,
            b.title AS book_title,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.student_id,
            br.borrow_date,
            br.due_date,
            b.borrow_days,
            br.return_date,
            br.status
        FROM borrowings br
        INNER JOIN books b ON b.id = br.book_id
        INNER JOIN users u ON u.id = br.user_id
    ";

    $types = '';
    $params = [];
    if ($userId > 0) {
        $query .= ' WHERE br.user_id = ?';
        $types = 'i';
        $params[] = $userId;
    }

    $query .= ' ORDER BY br.id DESC';

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare borrowings list query',
        ]);
    }

    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (!$result) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to fetch borrowings',
        ]);
    }

    $records = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $records[] = mapBorrowRowToResponse($row);
    }

    respond(200, [
        'success' => true,
        'records' => $records,
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
    $userId = isset($data['userId']) ? (int) $data['userId'] : 0;
    $studentName = isset($data['studentName']) ? trim($data['studentName']) : '';
    $bookId = isset($data['bookId']) ? (int) $data['bookId'] : 0;
    $dueDate = isset($data['dueDate']) ? trim($data['dueDate']) : '';

    if (($userId <= 0 && $studentName === '') || $bookId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Please fill in all required borrowing fields',
        ]);
    }

    $user = null;
    if ($userId > 0) {
        $userStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ? AND role = 'student' LIMIT 1");
        if (!$userStmt) {
            respond(500, [
                'success' => false,
                'message' => 'Failed to prepare user id lookup query',
            ]);
        }
        mysqli_stmt_bind_param($userStmt, 'i', $userId);
        mysqli_stmt_execute($userStmt);
        $userResult = mysqli_stmt_get_result($userStmt);
        $user = $userResult ? mysqli_fetch_assoc($userResult) : null;
    } else {
        $userQuery = "
            SELECT id
            FROM users
            WHERE role = 'student'
              AND (
                CONCAT(TRIM(first_name), ' ', TRIM(last_name)) = ?
                OR CONCAT(TRIM(first_name), ' ', TRIM(COALESCE(middle_name, '')), ' ', TRIM(last_name)) = ?
              )
            LIMIT 1
        ";
        $userStmt = mysqli_prepare($conn, $userQuery);
        if (!$userStmt) {
            respond(500, [
                'success' => false,
                'message' => 'Failed to prepare user lookup query',
            ]);
        }

        mysqli_stmt_bind_param($userStmt, 'ss', $studentName, $studentName);
        mysqli_stmt_execute($userStmt);
        $userResult = mysqli_stmt_get_result($userStmt);
        $user = $userResult ? mysqli_fetch_assoc($userResult) : null;
    }

    if (!$user) {
        respond(404, [
            'success' => false,
            'message' => 'Student not found.',
        ]);
    }

    $resolvedUserId = (int) $user['id'];

    mysqli_begin_transaction($conn);
    try {

        $bookLockStmt = mysqli_prepare($conn, 'SELECT id, quantity, borrow_days FROM books WHERE id = ? LIMIT 1 FOR UPDATE');
        if (!$bookLockStmt) {
            throw new Exception('Failed to prepare book lock query');
        }
        mysqli_stmt_bind_param($bookLockStmt, 'i', $bookId);
        mysqli_stmt_execute($bookLockStmt);
        $bookLockResult = mysqli_stmt_get_result($bookLockStmt);
        $lockedBook = $bookLockResult ? mysqli_fetch_assoc($bookLockResult) : null;

        if (!$lockedBook) {
            throw new Exception('Book not found', 404);
        }

        $borrowDays = max((int) ($lockedBook['borrow_days'] ?? 14), 1);
        $dueDate = date('Y-m-d', strtotime("+{$borrowDays} days"));

        $activeBorrowCountStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM borrowings WHERE user_id = ? AND status IN ('active', 'overdue')");
        if (!$activeBorrowCountStmt) {
            throw new Exception('Failed to prepare active borrowing count query');
        }
        mysqli_stmt_bind_param($activeBorrowCountStmt, 'i', $resolvedUserId);
        mysqli_stmt_execute($activeBorrowCountStmt);
        $activeBorrowCountResult = mysqli_stmt_get_result($activeBorrowCountStmt);
        $activeBorrowCountRow = $activeBorrowCountResult ? mysqli_fetch_assoc($activeBorrowCountResult) : null;
        $activeBorrowCount = (int) ($activeBorrowCountRow['total'] ?? 0);

        if ($activeBorrowCount >= 5) {
            throw new Exception('Student already has 5 active borrowed books', 409);
        }

        $sameBookBorrowStmt = mysqli_prepare($conn, "SELECT id FROM borrowings WHERE user_id = ? AND book_id = ? AND status IN ('active', 'overdue') LIMIT 1");
        if (!$sameBookBorrowStmt) {
            throw new Exception('Failed to prepare duplicate borrowing query');
        }
        mysqli_stmt_bind_param($sameBookBorrowStmt, 'ii', $resolvedUserId, $bookId);
        mysqli_stmt_execute($sameBookBorrowStmt);
        $sameBookBorrowResult = mysqli_stmt_get_result($sameBookBorrowStmt);
        if ($sameBookBorrowResult && mysqli_fetch_assoc($sameBookBorrowResult)) {
            throw new Exception('Student already has this book borrowed', 409);
        }

        $sameBookReservationStmt = mysqli_prepare($conn, "SELECT id FROM reservations WHERE user_id = ? AND book_id = ? AND status IN ('pending', 'fulfilled') LIMIT 1");
        if (!$sameBookReservationStmt) {
            throw new Exception('Failed to prepare duplicate reservation query');
        }
        mysqli_stmt_bind_param($sameBookReservationStmt, 'ii', $resolvedUserId, $bookId);
        mysqli_stmt_execute($sameBookReservationStmt);
        $sameBookReservationResult = mysqli_stmt_get_result($sameBookReservationStmt);
        if ($sameBookReservationResult && mysqli_fetch_assoc($sameBookReservationResult)) {
            throw new Exception('Student already has an active reservation for this book', 409);
        }

        $stats = getBookCirculationStats($conn, $bookId);
        if (!$stats) {
            throw new Exception('Book not found', 404);
        }

        $availableQuantity = $stats['quantity'] - $stats['activeBorrowed'];
        if ($availableQuantity <= 0) {
            throw new Exception('No available copies left for this book', 409);
        }

        $insertStmt = mysqli_prepare($conn, 'INSERT INTO borrowings (user_id, book_id, borrow_date, due_date, status) VALUES (?, ?, CURDATE(), ?, ?)' );
        if (!$insertStmt) {
            throw new Exception('Failed to prepare insert borrowing query');
        }

        $status = 'active';
        mysqli_stmt_bind_param($insertStmt, 'iiss', $resolvedUserId, $bookId, $dueDate, $status);

        if (!mysqli_stmt_execute($insertStmt)) {
            throw new Exception('Failed to create borrowing record');
        }

        refreshBookStatus($conn, $bookId);

        mysqli_commit($conn);

        respond(201, [
            'success' => true,
            'message' => 'Book borrowed successfully',
            'dueDate' => $dueDate,
            'borrowDays' => $borrowDays,
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $statusCode = $e->getCode();
        if (!is_int($statusCode) || $statusCode < 400 || $statusCode > 499) {
            $statusCode = 500;
        }
        respond($statusCode, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
}

if ($method === 'PUT') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $librarianId = isset($data['librarianId']) ? (int) $data['librarianId'] : 0;
    $librarianName = isset($data['librarianName']) ? trim((string) $data['librarianName']) : '';
    $librarianRole = isset($data['librarianRole']) ? trim((string) $data['librarianRole']) : 'librarian';
    $librarianLevel = isset($data['librarianLevel']) ? trim((string) $data['librarianLevel']) : '';
    $resolvedLevelFromDb = resolveLibrarianLevelById($conn, $librarianId);
    $effectiveLibrarianLevel = $resolvedLevelFromDb !== '' ? $resolvedLevelFromDb : $librarianLevel;

    if ($id <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Borrowing id is required',
        ]);
    }

    $fetchStmt = mysqli_prepare($conn, '
        SELECT
            br.id,
            br.user_id,
            br.book_id,
            br.status,
            b.title AS book_title,
            u.email,
            u.first_name,
            u.middle_name,
            u.last_name
        FROM borrowings br
        INNER JOIN books b ON b.id = br.book_id
        INNER JOIN users u ON u.id = br.user_id
        WHERE br.id = ?
        LIMIT 1
    ');
    mysqli_stmt_bind_param($fetchStmt, 'i', $id);
    mysqli_stmt_execute($fetchStmt);
    $result = mysqli_stmt_get_result($fetchStmt);
    $record = $result ? mysqli_fetch_assoc($result) : null;

    if (!$record) {
        respond(404, [
            'success' => false,
            'message' => 'Borrowing record not found',
        ]);
    }

    if ($record['status'] === 'returned') {
        respond(409, [
            'success' => false,
            'message' => 'Borrowing already returned',
        ]);
    }

    $studentUserId = (int) $record['user_id'];
    $isOverdueReturn = (string) ($record['status'] ?? '') === 'overdue';
    $wasRestrictedBefore = isStudentPortalAccountRestricted($conn, $studentUserId);

    mysqli_begin_transaction($conn);
    try {
        $returnStatus = 'returned';
        $updateBorrowStmt = mysqli_prepare($conn, 'UPDATE borrowings SET status = ?, return_date = CURDATE() WHERE id = ?');
        if (!$updateBorrowStmt) {
            throw new Exception('Failed to prepare borrowing return query');
        }
        mysqli_stmt_bind_param($updateBorrowStmt, 'si', $returnStatus, $id);
        if (!mysqli_stmt_execute($updateBorrowStmt)) {
            throw new Exception('Failed to update borrowing status');
        }

        $bookId = (int) $record['book_id'];
        refreshBookStatus($conn, $bookId);

        if ($studentUserId > 0) {
            syncStudentPortalRestrictionAutomation($conn, $studentUserId);
        }

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        respond(500, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }

    $recipientEmail = trim((string) ($record['email'] ?? ''));
    $firstName = trim((string) ($record['first_name'] ?? ''));
    $middleName = trim((string) ($record['middle_name'] ?? ''));
    $lastName = trim((string) ($record['last_name'] ?? ''));
    $bookTitle = trim((string) ($record['book_title'] ?? 'this book'));

    $nameParts = [];
    if ($firstName !== '') {
        $nameParts[] = $firstName;
    }
    if ($middleName !== '') {
        $nameParts[] = $middleName;
    }
    if ($lastName !== '') {
        $nameParts[] = $lastName;
    }
    $recipientName = trim(implode(' ', $nameParts));
    if ($recipientName === '') {
        $recipientName = 'Student';
    }

    $emailWarning = '';
    if ($recipientEmail === '') {
        $emailWarning = 'Student email is missing for this borrowing record';
    } else {
        $isRestrictedAfter = isStudentPortalAccountRestricted($conn, $studentUserId);
        $restrictionRemovedAfterReturn = $wasRestrictedBefore && !$isRestrictedAfter;
        $activeMailConfig = isset($mailConfig) && is_array($mailConfig) ? $mailConfig : [];
        $mailResult = sendBorrowingReturnedEmailWithPhpMailer(
            $recipientEmail,
            $recipientName,
            $bookTitle,
            $librarianName,
            $librarianRole,
            $effectiveLibrarianLevel,
            $activeMailConfig,
            $isOverdueReturn,
            $wasRestrictedBefore,
            $restrictionRemovedAfterReturn
        );
        if (!isset($mailResult['success']) || $mailResult['success'] !== true) {
            $emailWarning = isset($mailResult['message']) ? (string) $mailResult['message'] : 'Failed to send return confirmation email';
        }
    }

    $message = 'Book returned successfully';
    if ($emailWarning === '') {
        $message .= ' and email sent';
    } else {
        $message .= ' (email not sent)';
    }

    respond(200, [
        'success' => true,
        'message' => $message,
        'emailWarning' => $emailWarning,
    ]);
}

respond(405, [
    'success' => false,
    'message' => 'Method not allowed',
]);
