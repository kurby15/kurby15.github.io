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

function syncStudentPortalRestrictionAutomation($conn, $studentUserId) {
    $countStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total
         FROM borrowings
         WHERE user_id = ? AND (status = 'overdue' OR (status = 'active' AND due_date < CURDATE()))"
    );

    if (!$countStmt) {
        return;
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

function isStudentReservationRestricted($conn, $studentUserId) {
    $stmt = mysqli_prepare(
        $conn,
        'SELECT restrict_book_reservations FROM student_portal_restrictions WHERE student_user_id = ? LIMIT 1'
    );

    if (!$stmt) {
        throw new Exception('Failed to prepare student restriction lookup query');
    }

    mysqli_stmt_bind_param($stmt, 'i', $studentUserId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    return (int) ($row['restrict_book_reservations'] ?? 0) === 1;
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

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
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

function appendReservationMailLog($message) {
    $logDir = __DIR__ . '/tmp';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $logFile = $logDir . '/reservation_mail.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
}

function sendReservationReadyEmailWithPhpMailer($toEmail, $recipientName, $bookTitle, $librarianName, $librarianRole, $librarianLevel, $mailConfig) {
    appendReservationMailLog("Attempting ready email to {$toEmail} for book '{$bookTitle}'");

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        appendReservationMailLog('PHPMailer class not found');
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
        appendReservationMailLog('SMTP config missing required host/username/password');
        return [
            'success' => false,
            'message' => 'SMTP is not configured. Set MAIL_HOST, MAIL_USERNAME, and MAIL_PASSWORD.',
        ];
    }

    if (stripos($smtpHost, 'smtp.gmail.com') !== false) {
        $smtpPassword = str_replace(' ', '', $smtpPassword);

        if (stripos($smtpUsername, '@gmail.com') === false) {
            appendReservationMailLog('Invalid Gmail SMTP username format');
            return [
                'success' => false,
                'message' => 'Gmail SMTP requires a Gmail account in username.',
            ];
        }

        if (strcasecmp($fromAddress, $smtpUsername) !== 0) {
            appendReservationMailLog('Gmail SMTP fromAddress does not match username');
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
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        if ($smtpEncryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Your Reserved Book is Ready for Pickup';
        $safeRecipientName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $safeBookTitle = htmlspecialchars($bookTitle, ENT_QUOTES, 'UTF-8');
        $safeSignatureName = htmlspecialchars($signatureName, ENT_QUOTES, 'UTF-8');
        $safeSignatureRole = htmlspecialchars($signatureRole, ENT_QUOTES, 'UTF-8');

        $mail->Body = "<p>Dear {$safeRecipientName},</p>"
            . "<p>We are pleased to inform you that your reserved book is now ready for pickup.</p>"
            . "<p><strong>Book Title:</strong> {$safeBookTitle}</p>"
            . "<p>Please visit the library at your earliest convenience to complete the borrowing process.</p>"
            . "<p>Sincerely,<br><strong>{$safeSignatureName}</strong><br>{$safeSignatureRole}</p>";

        $mail->AltBody = "Dear {$recipientName},\n\n"
            . "We are pleased to inform you that your reserved book is now ready for pickup.\n\n"
            . "Book Title: {$bookTitle}\n\n"
            . "Please visit the library at your earliest convenience to complete the borrowing process.\n\n"
            . "Sincerely,\n"
            . "{$signatureName}\n"
            . "{$signatureRole}";

        $mail->send();
        appendReservationMailLog("Ready email sent successfully to {$toEmail}");

        return [
            'success' => true,
            'message' => 'Ready notification email sent',
        ];
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        appendReservationMailLog('PHPMailer exception: ' . $errorMessage);

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

function sendReservationCancelledEmailWithPhpMailer($toEmail, $recipientName, $bookTitle, $librarianName, $librarianRole, $librarianLevel, $mailConfig) {
    appendReservationMailLog("Attempting cancellation email to {$toEmail} for book '{$bookTitle}'");

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        appendReservationMailLog('PHPMailer class not found for cancellation email');
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
        appendReservationMailLog('SMTP config missing required host/username/password for cancellation email');
        return [
            'success' => false,
            'message' => 'SMTP is not configured. Set MAIL_HOST, MAIL_USERNAME, and MAIL_PASSWORD.',
        ];
    }

    if (stripos($smtpHost, 'smtp.gmail.com') !== false) {
        $smtpPassword = str_replace(' ', '', $smtpPassword);

        if (stripos($smtpUsername, '@gmail.com') === false) {
            appendReservationMailLog('Invalid Gmail SMTP username format for cancellation email');
            return [
                'success' => false,
                'message' => 'Gmail SMTP requires a Gmail account in username.',
            ];
        }

        if (strcasecmp($fromAddress, $smtpUsername) !== 0) {
            appendReservationMailLog('Gmail SMTP fromAddress does not match username for cancellation email');
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
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        if ($smtpEncryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromAddress, $fromName);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Your Reservation Was Cancelled';
        $safeRecipientName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $safeBookTitle = htmlspecialchars($bookTitle, ENT_QUOTES, 'UTF-8');
        $safeSignatureName = htmlspecialchars($signatureName, ENT_QUOTES, 'UTF-8');
        $safeSignatureRole = htmlspecialchars($signatureRole, ENT_QUOTES, 'UTF-8');

        $mail->Body = "<p>Dear {$safeRecipientName},</p>"
            . "<p>This is to inform you that your reservation has been cancelled by the library.</p>"
            . "<p><strong>Book Title:</strong> {$safeBookTitle}</p>"
            . "<p>If you still need this title, you may submit a new reservation request at any time.</p>"
            . "<p>Sincerely,<br><strong>{$safeSignatureName}</strong><br>{$safeSignatureRole}</p>";

        $mail->AltBody = "Dear {$recipientName},\n\n"
            . "This is to inform you that your reservation has been cancelled by the library.\n\n"
            . "Book Title: {$bookTitle}\n\n"
            . "If you still need this title, you may submit a new reservation request at any time.\n\n"
            . "Sincerely,\n"
            . "{$signatureName}\n"
            . "{$signatureRole}";

        $mail->send();
        appendReservationMailLog("Cancellation email sent successfully to {$toEmail}");

        return [
            'success' => true,
            'message' => 'Cancellation notification email sent',
        ];
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        appendReservationMailLog('Cancellation PHPMailer exception: ' . $errorMessage);

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

function mapReservationStatusToUi($status) {
    if ($status === 'pending') return 'Pending';
    if ($status === 'fulfilled') return 'Ready';
    return 'Completed';
}

function mapReservationRow($row) {
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
        'reservationDate' => $row['reservation_date'],
        'status' => mapReservationStatusToUi($row['status']),
        'queuePosition' => (int) $row['queue_position'],
    ];
}

function resequenceQueue($conn, $bookId) {
    $stmt = mysqli_prepare($conn, "
        SELECT id
        FROM reservations
        WHERE book_id = ? AND status IN ('pending', 'fulfilled')
        ORDER BY reserved_at ASC, id ASC
    ");
    if (!$stmt) {
        throw new Exception('Failed to prepare queue resequence query');
    }

    mysqli_stmt_bind_param($stmt, 'i', $bookId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $position = 1;
    while ($row = mysqli_fetch_assoc($result)) {
        $updateStmt = mysqli_prepare($conn, 'UPDATE reservations SET queue_position = ? WHERE id = ?');
        if (!$updateStmt) {
            throw new Exception('Failed to prepare queue update query');
        }
        $reservationId = (int) $row['id'];
        mysqli_stmt_bind_param($updateStmt, 'ii', $position, $reservationId);
        if (!mysqli_stmt_execute($updateStmt)) {
            throw new Exception('Failed to update queue position');
        }
        $position++;
    }
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
markActiveBorrowingsAsOverdue($conn);

if ($method === 'GET') {
    $userId = isset($_GET['userId']) ? (int) $_GET['userId'] : 0;
    $activeOnly = isset($_GET['active']) && $_GET['active'] === '1';

    $query = "
        SELECT
            r.id,
            r.user_id,
            r.book_id,
            r.queue_position,
            r.status,
            DATE(r.reserved_at) AS reservation_date,
            b.title AS book_title,
            u.first_name,
            u.middle_name,
            u.last_name,
            u.student_id
        FROM reservations r
        INNER JOIN books b ON b.id = r.book_id
        INNER JOIN users u ON u.id = r.user_id
    ";

    $conditions = [];
    $types = '';
    $params = [];

    if ($userId > 0) {
        $conditions[] = 'r.user_id = ?';
        $types .= 'i';
        $params[] = $userId;
    }

    if ($activeOnly) {
        $conditions[] = "r.status IN ('pending', 'fulfilled')";
    }

    if (count($conditions) > 0) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }

    if ($activeOnly) {
        $query .= ' ORDER BY r.book_id ASC, r.queue_position ASC, r.id ASC';
    } else {
        $query .= ' ORDER BY r.reserved_at DESC, r.id DESC';
    }

    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare reservation list query',
        ]);
    }

    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $reservations = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $reservations[] = mapReservationRow($row);
    }

    respond(200, [
        'success' => true,
        'reservations' => $reservations,
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
    $bookId = isset($data['bookId']) ? (int) $data['bookId'] : 0;

    if ($userId <= 0 || $bookId <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'User and book are required',
        ]);
    }

    $userStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    mysqli_stmt_bind_param($userStmt, 'i', $userId);
    mysqli_stmt_execute($userStmt);
    $userResult = mysqli_stmt_get_result($userStmt);
    if (!$userResult || !mysqli_fetch_assoc($userResult)) {
        respond(404, [
            'success' => false,
            'message' => 'Student not found',
        ]);
    }

    syncStudentPortalRestrictionAutomation($conn, $userId);
    if (isStudentReservationRestricted($conn, $userId)) {
        respond(403, [
            'success' => false,
            'message' => 'Your account has restricted reservation access. Please return overdue books first.',
        ]);
    }

    $bookStmt = mysqli_prepare($conn, 'SELECT id FROM books WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($bookStmt, 'i', $bookId);
    mysqli_stmt_execute($bookStmt);
    $bookResult = mysqli_stmt_get_result($bookStmt);
    $book = $bookResult ? mysqli_fetch_assoc($bookResult) : null;
    if (!$book) {
        respond(404, [
            'success' => false,
            'message' => 'Book not found',
        ]);
    }

    $dupStmt = mysqli_prepare($conn, "SELECT id FROM reservations WHERE user_id = ? AND book_id = ? AND status IN ('pending', 'fulfilled') LIMIT 1");
    mysqli_stmt_bind_param($dupStmt, 'ii', $userId, $bookId);
    mysqli_stmt_execute($dupStmt);
    $dupResult = mysqli_stmt_get_result($dupStmt);
    if ($dupResult && mysqli_fetch_assoc($dupResult)) {
        respond(409, [
            'success' => false,
            'message' => 'You already have an active reservation for this book',
        ]);
    }

    $activeBorrowDupStmt = mysqli_prepare($conn, "SELECT id FROM borrowings WHERE user_id = ? AND book_id = ? AND status IN ('active', 'overdue') LIMIT 1");
    if (!$activeBorrowDupStmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare active borrowing duplicate query',
        ]);
    }
    mysqli_stmt_bind_param($activeBorrowDupStmt, 'ii', $userId, $bookId);
    mysqli_stmt_execute($activeBorrowDupStmt);
    $activeBorrowDupResult = mysqli_stmt_get_result($activeBorrowDupStmt);
    if ($activeBorrowDupResult && mysqli_fetch_assoc($activeBorrowDupResult)) {
        respond(409, [
            'success' => false,
            'message' => 'You already borrowed this book',
        ]);
    }

    mysqli_begin_transaction($conn);
    try {
        $queueStmt = mysqli_prepare($conn, "SELECT COALESCE(MAX(queue_position), 0) AS max_pos FROM reservations WHERE book_id = ? AND status IN ('pending', 'fulfilled')");
        if (!$queueStmt) {
            throw new Exception('Failed to prepare queue query');
        }

        mysqli_stmt_bind_param($queueStmt, 'i', $bookId);
        mysqli_stmt_execute($queueStmt);
        $queueResult = mysqli_stmt_get_result($queueStmt);
        $maxPosRow = $queueResult ? mysqli_fetch_assoc($queueResult) : null;
        $nextPosition = ((int) ($maxPosRow['max_pos'] ?? 0)) + 1;

        $status = 'pending';
        $insertStmt = mysqli_prepare($conn, 'INSERT INTO reservations (user_id, book_id, queue_position, status) VALUES (?, ?, ?, ?)');
        if (!$insertStmt) {
            throw new Exception('Failed to prepare insert reservation query');
        }

        mysqli_stmt_bind_param($insertStmt, 'iiis', $userId, $bookId, $nextPosition, $status);
        if (!mysqli_stmt_execute($insertStmt)) {
            throw new Exception('Failed to create reservation');
        }

        refreshBookStatus($conn, $bookId);

        mysqli_commit($conn);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        respond(500, [
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }

    respond(201, [
        'success' => true,
        'message' => 'Reservation created successfully',
    ]);
}

if ($method === 'PUT') {
    $action = isset($data['action']) ? trim($data['action']) : '';
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $librarianId = isset($data['librarianId']) ? (int) $data['librarianId'] : 0;
    $librarianName = isset($data['librarianName']) ? trim((string) $data['librarianName']) : '';
    $librarianRole = isset($data['librarianRole']) ? trim((string) $data['librarianRole']) : 'librarian';
    $librarianLevel = isset($data['librarianLevel']) ? trim((string) $data['librarianLevel']) : '';
    $resolvedLevelFromDb = resolveLibrarianLevelById($conn, $librarianId);
    $effectiveLibrarianLevel = $resolvedLevelFromDb !== '' ? $resolvedLevelFromDb : $librarianLevel;

    if ($id <= 0 || $action === '') {
        respond(400, [
            'success' => false,
            'message' => 'Reservation id and action are required',
        ]);
    }

    $fetchStmt = mysqli_prepare($conn, '
        SELECT
            r.id,
            r.user_id,
            r.book_id,
            r.queue_position,
            r.status,
            u.email,
            u.first_name,
            u.middle_name,
            u.last_name,
            b.title AS book_title
        FROM reservations r
        INNER JOIN users u ON u.id = r.user_id
        INNER JOIN books b ON b.id = r.book_id
        WHERE r.id = ?
        LIMIT 1
    ');
    mysqli_stmt_bind_param($fetchStmt, 'i', $id);
    mysqli_stmt_execute($fetchStmt);
    $fetchResult = mysqli_stmt_get_result($fetchStmt);
    $reservation = $fetchResult ? mysqli_fetch_assoc($fetchResult) : null;

    if (!$reservation) {
        respond(404, [
            'success' => false,
            'message' => 'Reservation not found',
        ]);
    }

    $bookId = (int) $reservation['book_id'];

    if ($action === 'mark-ready') {
        if ($reservation['status'] !== 'pending') {
            respond(409, [
                'success' => false,
                'message' => 'Only pending reservations can be marked as ready',
            ]);
        }

        $stats = getBookCirculationStats($conn, $bookId);
        if (!$stats) {
            respond(404, [
                'success' => false,
                'message' => 'Book not found',
            ]);
        }

        $availableQuantity = $stats['quantity'] - $stats['activeBorrowed'];
        if ($availableQuantity <= 0) {
            respond(409, [
                'success' => false,
                'message' => 'Cannot mark ready while all copies are still borrowed',
            ]);
        }

        $firstPendingStmt = mysqli_prepare($conn, "
            SELECT id
            FROM reservations
            WHERE book_id = ? AND status = 'pending'
            ORDER BY queue_position ASC, reserved_at ASC, id ASC
            LIMIT 1
        ");
        if (!$firstPendingStmt) {
            respond(500, [
                'success' => false,
                'message' => 'Failed to validate reservation queue',
            ]);
        }

        mysqli_stmt_bind_param($firstPendingStmt, 'i', $bookId);
        mysqli_stmt_execute($firstPendingStmt);
        $firstPendingResult = mysqli_stmt_get_result($firstPendingStmt);
        $firstPending = $firstPendingResult ? mysqli_fetch_assoc($firstPendingResult) : null;

        if (!$firstPending || (int) $firstPending['id'] !== $id) {
            respond(409, [
                'success' => false,
                'message' => 'Only the first student in the waitlist queue can be marked ready',
            ]);
        }

        $recipientEmail = trim((string) ($reservation['email'] ?? ''));
        $firstName = trim((string) ($reservation['first_name'] ?? ''));
        $middleName = trim((string) ($reservation['middle_name'] ?? ''));
        $lastName = trim((string) ($reservation['last_name'] ?? ''));
        $bookTitle = trim((string) ($reservation['book_title'] ?? 'this book'));

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

        mysqli_begin_transaction($conn);
        try {
            $updateStmt = mysqli_prepare($conn, "UPDATE reservations SET status = 'fulfilled' WHERE id = ?");
            if (!$updateStmt) {
                throw new Exception('Failed to prepare mark-ready update query');
            }
            mysqli_stmt_bind_param($updateStmt, 'i', $id);
            if (!mysqli_stmt_execute($updateStmt)) {
                throw new Exception('Failed to mark reservation ready');
            }

            mysqli_commit($conn);
        } catch (Exception $e) {
            mysqli_rollback($conn);
            appendReservationMailLog("Reservation {$id} mark-ready failed: " . $e->getMessage());
            respond(500, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        $emailWarning = '';
        if ($recipientEmail === '') {
            $emailWarning = 'Student email is missing for this reservation';
            appendReservationMailLog("Reservation {$id} marked ready but student email is empty");
        } else {
            $activeMailConfig = isset($mailConfig) && is_array($mailConfig) ? $mailConfig : [];
            $mailResult = sendReservationReadyEmailWithPhpMailer($recipientEmail, $recipientName, $bookTitle, $librarianName, $librarianRole, $effectiveLibrarianLevel, $activeMailConfig);
            if (!isset($mailResult['success']) || $mailResult['success'] !== true) {
                $emailWarning = isset($mailResult['message']) ? (string) $mailResult['message'] : 'Failed to send ready notification email';
                appendReservationMailLog("Reservation {$id} marked ready but email failed: {$emailWarning}");
            } else {
                appendReservationMailLog("Reservation {$id} marked ready and email confirmed for {$recipientEmail}");
            }
        }

        $message = 'Reservation marked as ready';
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

    if ($action === 'complete') {
        mysqli_begin_transaction($conn);
        try {
            $dueDate = date('Y-m-d', strtotime('+14 days'));
            $borrowStatus = 'active';
            $userId = (int) $reservation['user_id'];

            syncStudentPortalRestrictionAutomation($conn, $userId);

            if (isStudentReservationRestricted($conn, $userId)) {
                throw new Exception('Student account is restricted from borrowing via reservation completion', 403);
            }

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
            mysqli_stmt_bind_param($activeBorrowCountStmt, 'i', $userId);
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
            mysqli_stmt_bind_param($sameBookBorrowStmt, 'ii', $userId, $bookId);
            mysqli_stmt_execute($sameBookBorrowStmt);
            $sameBookBorrowResult = mysqli_stmt_get_result($sameBookBorrowStmt);
            if ($sameBookBorrowResult && mysqli_fetch_assoc($sameBookBorrowResult)) {
                throw new Exception('Student already has this book borrowed', 409);
            }

            $stats = getBookCirculationStats($conn, $bookId);
            if (!$stats) {
                throw new Exception('Book not found', 404);
            }

            $availableQuantity = $stats['quantity'] - $stats['activeBorrowed'];
            if ($availableQuantity <= 0) {
                throw new Exception('No available copies left for this book', 409);
            }

            $insertBorrowStmt = mysqli_prepare($conn, 'INSERT INTO borrowings (user_id, book_id, borrow_date, due_date, status) VALUES (?, ?, CURDATE(), ?, ?)');
            if (!$insertBorrowStmt) {
                throw new Exception('Failed to prepare borrowing insert query');
            }

            mysqli_stmt_bind_param($insertBorrowStmt, 'iiss', $userId, $bookId, $dueDate, $borrowStatus);
            if (!mysqli_stmt_execute($insertBorrowStmt)) {
                throw new Exception('Failed to create borrowing record');
            }

            $deleteReservationStmt = mysqli_prepare($conn, 'DELETE FROM reservations WHERE id = ?');
            if (!$deleteReservationStmt) {
                throw new Exception('Failed to prepare reservation delete query');
            }
            mysqli_stmt_bind_param($deleteReservationStmt, 'i', $id);
            if (!mysqli_stmt_execute($deleteReservationStmt)) {
                throw new Exception('Failed to delete completed reservation');
            }

            resequenceQueue($conn, $bookId);
            refreshBookStatus($conn, $bookId);
            mysqli_commit($conn);

            respond(200, [
                'success' => true,
                'message' => 'Reservation completed and borrowing created',
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

    if ($action === 'cancel') {
        $recipientEmail = trim((string) ($reservation['email'] ?? ''));
        $firstName = trim((string) ($reservation['first_name'] ?? ''));
        $middleName = trim((string) ($reservation['middle_name'] ?? ''));
        $lastName = trim((string) ($reservation['last_name'] ?? ''));
        $bookTitle = trim((string) ($reservation['book_title'] ?? 'this book'));

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

        mysqli_begin_transaction($conn);
        try {
            $deleteStmt = mysqli_prepare($conn, 'DELETE FROM reservations WHERE id = ?');
            if (!$deleteStmt) {
                throw new Exception('Failed to prepare reservation cancel query');
            }
            mysqli_stmt_bind_param($deleteStmt, 'i', $id);
            if (!mysqli_stmt_execute($deleteStmt)) {
                throw new Exception('Failed to cancel reservation');
            }

            resequenceQueue($conn, $bookId);

            refreshBookStatus($conn, $bookId);

            mysqli_commit($conn);
        } catch (Exception $e) {
            mysqli_rollback($conn);
            appendReservationMailLog("Reservation {$id} cancel failed: " . $e->getMessage());
            respond(500, [
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        $emailWarning = '';
        if ($recipientEmail === '') {
            $emailWarning = 'Student email is missing for this reservation';
            appendReservationMailLog("Reservation {$id} cancelled but student email is empty");
        } else {
            $activeMailConfig = isset($mailConfig) && is_array($mailConfig) ? $mailConfig : [];
            $mailResult = sendReservationCancelledEmailWithPhpMailer($recipientEmail, $recipientName, $bookTitle, $librarianName, $librarianRole, $effectiveLibrarianLevel, $activeMailConfig);
            if (!isset($mailResult['success']) || $mailResult['success'] !== true) {
                $emailWarning = isset($mailResult['message']) ? (string) $mailResult['message'] : 'Failed to send cancellation notification email';
                appendReservationMailLog("Reservation {$id} cancelled but email failed: {$emailWarning}");
            } else {
                appendReservationMailLog("Reservation {$id} cancelled and email confirmed for {$recipientEmail}");
            }
        }

        $message = 'Reservation cancelled';
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

    respond(400, [
        'success' => false,
        'message' => 'Invalid action',
    ]);
}

respond(405, [
    'success' => false,
    'message' => 'Method not allowed',
]);
