<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($requestMethod === 'OPTIONS') {
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

function respond($statusCode, $payload) {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function appendBorrowingMailLog($message) {
    $logDir = __DIR__ . '/tmp';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $logFile = $logDir . '/borrowing_mail.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
}

function loadBorrowingReminderStore($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }

    $raw = file_get_contents($filePath);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveBorrowingReminderStore($filePath, $store) {
    $dir = dirname($filePath);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }

    $json = json_encode($store, JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    return file_put_contents($filePath, $json, LOCK_EX) !== false;
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
         ADD COLUMN librarian_level ENUM('head_librarian', 'librarian') DEFAULT NULL AFTER role"
    );
}

function resolveCronSignatureFromUsers($conn, $mailConfig) {
    $fallbackName = isset($mailConfig['signatureName']) && trim((string) $mailConfig['signatureName']) !== ''
        ? trim((string) $mailConfig['signatureName'])
        : (isset($mailConfig['fromName']) ? trim((string) $mailConfig['fromName']) : 'San Martin Library');
    $fallbackRole = 'Head Librarian';

    $query = "
        SELECT
            first_name,
            middle_name,
            last_name,
            librarian_level
        FROM users
        WHERE role = 'librarian'
          AND librarian_level = 'head_librarian'
        ORDER BY id ASC
        LIMIT 1
    ";

    $result = mysqli_query($conn, $query);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    if (!$row) {
        return [
            'name' => $fallbackName,
            'role' => $fallbackRole,
        ];
    }

    $middleName = trim((string) ($row['middle_name'] ?? ''));
    $resolvedName = trim(
        (string) ($row['first_name'] ?? '') . ' '
        . ($middleName !== '' ? $middleName . ' ' : '')
        . (string) ($row['last_name'] ?? '')
    );
    if ($resolvedName === '') {
        $resolvedName = $fallbackName;
    }

    $resolvedRole = 'Head Librarian';

    return [
        'name' => $resolvedName,
        'role' => $resolvedRole,
    ];
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

function syncStudentRestrictionAndNotify($conn, $studentUserId, $hasOverdue, &$store, &$storeChanged) {
    if ($studentUserId <= 0) {
        return;
    }

    $restrictionCheckStmt = mysqli_prepare(
        $conn,
        'SELECT restrict_book_search, restrict_book_recommendations, restrict_book_reservations FROM student_portal_restrictions WHERE student_user_id = ? LIMIT 1'
    );

    if (!$restrictionCheckStmt) {
        return;
    }

    mysqli_stmt_bind_param($restrictionCheckStmt, 'i', $studentUserId);
    mysqli_stmt_execute($restrictionCheckStmt);
    $restrictionResult = mysqli_stmt_get_result($restrictionCheckStmt);
    $restrictionRow = $restrictionResult ? mysqli_fetch_assoc($restrictionResult) : null;

    $isRestricted =
        $restrictionRow &&
        (int) ($restrictionRow['restrict_book_search'] ?? 0) === 1 &&
        (int) ($restrictionRow['restrict_book_recommendations'] ?? 0) === 1 &&
        (int) ($restrictionRow['restrict_book_reservations'] ?? 0) === 1;

    if ($hasOverdue && !$isRestricted) {
        $enabled = 1;
        $upsertStmt = mysqli_prepare(
            $conn,
            'INSERT INTO student_portal_restrictions (student_user_id, restrict_book_search, restrict_book_recommendations, restrict_book_reservations) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE restrict_book_search = VALUES(restrict_book_search), restrict_book_recommendations = VALUES(restrict_book_recommendations), restrict_book_reservations = VALUES(restrict_book_reservations)'
        );

        if ($upsertStmt) {
            mysqli_stmt_bind_param($upsertStmt, 'iiii', $studentUserId, $enabled, $enabled, $enabled);
            if (mysqli_stmt_execute($upsertStmt)) {
                $title = 'Student Account Restricted';
                $message = 'Your account has been automatically restricted because you have overdue borrowed books. Please return overdue books to restore full access.';
                insertNotificationIfNotExists($conn, $studentUserId, 'overdue', $title, $message, 24);
            }
        }
    }

    if (!$hasOverdue && $isRestricted) {
        $deleteStmt = mysqli_prepare($conn, 'DELETE FROM student_portal_restrictions WHERE student_user_id = ?');
        if ($deleteStmt) {
            mysqli_stmt_bind_param($deleteStmt, 'i', $studentUserId);
            mysqli_stmt_execute($deleteStmt);
        }
    }

    $studentsStore = isset($store['_students']) && is_array($store['_students']) ? $store['_students'] : [];
    $currentState = isset($studentsStore[(string) $studentUserId]) && is_array($studentsStore[(string) $studentUserId])
        ? $studentsStore[(string) $studentUserId]
        : [];
    $nextRestricted = $hasOverdue;
    $prevRestricted = isset($currentState['restricted']) ? (bool) $currentState['restricted'] : null;
    if ($prevRestricted !== $nextRestricted) {
        $studentsStore[(string) $studentUserId] = [
            'restricted' => $nextRestricted,
            'updatedOn' => date('Y-m-d'),
        ];
        $store['_students'] = $studentsStore;
        $storeChanged = true;
    }
}

function recordStudentOverdueOffense($conn, $studentUserId, $borrowingId) {
    if ($studentUserId <= 0 || $borrowingId <= 0) {
        return;
    }

    $storePath = __DIR__ . '/tmp/borrowing_email_alerts.json';
    $store = loadBorrowingReminderStore($storePath);
    $storeChanged = false;

    syncStudentRestrictionAndNotify($conn, $studentUserId, true, $store, $storeChanged);

    if ($storeChanged) {
        saveBorrowingReminderStore($storePath, $store);
    }
}

function buildRecipientName($firstName, $middleName, $lastName) {
    $parts = [];
    $firstName = trim((string) $firstName);
    $middleName = trim((string) $middleName);
    $lastName = trim((string) $lastName);

    if ($firstName !== '') {
        $parts[] = $firstName;
    }
    if ($middleName !== '') {
        $parts[] = $middleName;
    }
    if ($lastName !== '') {
        $parts[] = $lastName;
    }

    $name = trim(implode(' ', $parts));
    return $name !== '' ? $name : 'Student';
}

function sendBorrowingAlertEmailWithPhpMailer($toEmail, $recipientName, $subject, $body, $mailConfig) {
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
    $signatureName = isset($mailConfig['signatureName']) ? trim((string) $mailConfig['signatureName']) : '';
    $signatureRole = isset($mailConfig['signatureRole']) ? trim((string) $mailConfig['signatureRole']) : '';

    if ($signatureName === '') {
        $signatureName = $fromName;
    }
    if ($signatureRole === '') {
        $signatureRole = 'Librarian';
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
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $bodyWithSignature = rtrim($body) . "\n\n"
            . "Sincerely,\n"
            . $signatureName . "\n"
            . $signatureRole;

        $mail->Body = $bodyWithSignature;
        $mail->send();

        return [
            'success' => true,
            'message' => 'Email sent successfully',
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

function processBorrowingEmailAlerts($conn, $mailConfig) {
    $dueSoonWindowDays = 2;
    $storePath = __DIR__ . '/tmp/borrowing_email_alerts.json';
    $store = loadBorrowingReminderStore($storePath);

    $summary = [
        'checked' => 0,
        'autoMarkedOverdue' => 0,
        'dueSoonSent' => 0,
        'overdueSent' => 0,
        'failed' => 0,
    ];

    $query = "
        SELECT
            br.id,
            br.user_id,
            br.status,
            br.due_date,
            u.email,
            u.first_name,
            u.middle_name,
            u.last_name,
            b.title AS book_title
        FROM borrowings br
        INNER JOIN users u ON u.id = br.user_id
        INNER JOIN books b ON b.id = br.book_id
        WHERE br.status IN ('active', 'overdue')
    ";

    $result = mysqli_query($conn, $query);
    if (!$result) {
        appendBorrowingMailLog('Failed to query borrowings for reminder cron run');
        return $summary;
    }

    $today = new DateTime('today');
    $todayRaw = $today->format('Y-m-d');
    $storeChanged = false;
    $overdueUserIds = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $summary['checked']++;

        $borrowingId = (string) ($row['id'] ?? '');
        if ($borrowingId === '') {
            continue;
        }

        $dueDateRaw = trim((string) ($row['due_date'] ?? ''));
        if ($dueDateRaw === '') {
            appendBorrowingMailLog("Borrowing {$borrowingId} skipped: missing due date");
            continue;
        }

        $dueDate = DateTime::createFromFormat('Y-m-d', $dueDateRaw);
        if (!$dueDate) {
            appendBorrowingMailLog("Borrowing {$borrowingId} skipped: invalid due date {$dueDateRaw}");
            continue;
        }
        $dueDate->setTime(0, 0, 0);

        $daysUntilDue = (int) $today->diff($dueDate)->format('%r%a');
        $status = strtolower(trim((string) ($row['status'] ?? 'active')));

        if ($status === 'active' && $daysUntilDue < 0) {
            $overdueStatus = 'overdue';
            $updateStmt = mysqli_prepare($conn, 'UPDATE borrowings SET status = ? WHERE id = ? AND status = ?');
            if ($updateStmt) {
                $borrowingIdInt = (int) $borrowingId;
                $activeStatus = 'active';
                mysqli_stmt_bind_param($updateStmt, 'sis', $overdueStatus, $borrowingIdInt, $activeStatus);
                if (mysqli_stmt_execute($updateStmt)) {
                    $status = 'overdue';
                    $summary['autoMarkedOverdue']++;
                    appendBorrowingMailLog("Borrowing {$borrowingId} auto-marked overdue during cron run");
                    recordStudentOverdueOffense($conn, (int) ($row['user_id'] ?? 0), $borrowingIdInt);
                }
            }
        }

        $studentUserId = (int) ($row['user_id'] ?? 0);
        if ($status === 'overdue' && $studentUserId > 0) {
            $overdueUserIds[(string) $studentUserId] = true;
        }

        $recipientEmail = trim((string) ($row['email'] ?? ''));
        if ($recipientEmail === '') {
            appendBorrowingMailLog("Borrowing {$borrowingId} skipped reminder: empty student email");
            continue;
        }

        $recipientName = buildRecipientName(
            $row['first_name'] ?? '',
            $row['middle_name'] ?? '',
            $row['last_name'] ?? ''
        );
        $bookTitle = trim((string) ($row['book_title'] ?? 'this book'));
        $entry = isset($store[$borrowingId]) && is_array($store[$borrowingId]) ? $store[$borrowingId] : [];

        if ($status === 'active' && $daysUntilDue >= 0 && $daysUntilDue <= $dueSoonWindowDays) {
            $alreadySentForDueDate = isset($entry['dueSoonSentForDueDate']) ? (string) $entry['dueSoonSentForDueDate'] : '';
            if ($alreadySentForDueDate !== $dueDateRaw) {
                $subject = 'Library Reminder: Return Date is Near';
                $body = "Good day {$recipientName},\n\n"
                    . "This is a reminder that your borrowed book is almost due.\n\n"
                    . "Book: {$bookTitle}\n"
                    . "Due date: {$dueDateRaw}\n"
                    . "Days left: {$daysUntilDue}\n\n"
                    . "Please return or renew on time to avoid overdue status.";

                $mailResult = sendBorrowingAlertEmailWithPhpMailer($recipientEmail, $recipientName, $subject, $body, $mailConfig);
                if (isset($mailResult['success']) && $mailResult['success'] === true) {
                    $entry['dueSoonSentForDueDate'] = $dueDateRaw;
                    $store[$borrowingId] = $entry;
                    $storeChanged = true;
                    $summary['dueSoonSent']++;
                    appendBorrowingMailLog("Due-soon reminder sent for borrowing {$borrowingId} to {$recipientEmail}");
                } else {
                    $summary['failed']++;
                    appendBorrowingMailLog(
                        "Due-soon reminder failed for borrowing {$borrowingId}: "
                        . (isset($mailResult['message']) ? $mailResult['message'] : 'Unknown error')
                    );
                }
            }
        }

        if ($status === 'overdue') {
            recordStudentOverdueOffense($conn, (int) ($row['user_id'] ?? 0), (int) $borrowingId);
            $alreadySentOverdueOnDate = isset($entry['overdueSentOnDate']) ? (string) $entry['overdueSentOnDate'] : '';
            if ($alreadySentOverdueOnDate !== $todayRaw) {
                $subject = 'Library Notice: Book is Overdue';
                $body = "Good day {$recipientName},\n\n"
                    . "Your borrowed book is now overdue.\n\n"
                    . "Book: {$bookTitle}\n"
                    . "Due date: {$dueDateRaw}\n\n"
                    . "This is a daily reminder while your book remains overdue.\n\n"
                    . "Please return it to the library as soon as possible.";

                $mailResult = sendBorrowingAlertEmailWithPhpMailer($recipientEmail, $recipientName, $subject, $body, $mailConfig);
                if (isset($mailResult['success']) && $mailResult['success'] === true) {
                    $entry['overdueSentOnDate'] = $todayRaw;
                    $store[$borrowingId] = $entry;
                    $storeChanged = true;
                    $summary['overdueSent']++;
                    appendBorrowingMailLog("Overdue notice sent for borrowing {$borrowingId} to {$recipientEmail}");
                } else {
                    $summary['failed']++;
                    appendBorrowingMailLog(
                        "Overdue notice failed for borrowing {$borrowingId}: "
                        . (isset($mailResult['message']) ? $mailResult['message'] : 'Unknown error')
                    );
                }
            }
        }
    }

    $studentsResult = mysqli_query($conn, "SELECT id FROM users WHERE role = 'student'");
    if ($studentsResult) {
        while ($studentRow = mysqli_fetch_assoc($studentsResult)) {
            $studentId = (int) ($studentRow['id'] ?? 0);
            if ($studentId <= 0) {
                continue;
            }

            $hasOverdue = isset($overdueUserIds[(string) $studentId]);
            syncStudentRestrictionAndNotify($conn, $studentId, $hasOverdue, $store, $storeChanged);
        }
    }

    if ($storeChanged) {
        if (!saveBorrowingReminderStore($storePath, $store)) {
            appendBorrowingMailLog('Failed to persist borrowing reminder store after cron run');
        }
    }

    return $summary;
}

$method = $requestMethod;
if ($method !== 'GET' && $method !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Method not allowed',
    ]);
}

$remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
$isCli = PHP_SAPI === 'cli';
$isLocal = $remoteAddr === '127.0.0.1' || $remoteAddr === '::1' || $remoteAddr === '';

if (!$isCli && !$isLocal) {
    respond(403, [
        'success' => false,
        'message' => 'Forbidden: cron endpoint is limited to local requests',
    ]);
}

ignore_user_abort(true);
@set_time_limit(120);

appendBorrowingMailLog('Cron borrowing reminder job started');
$activeMailConfig = isset($mailConfig) && is_array($mailConfig) ? $mailConfig : [];
ensureStudentPortalRestrictionsTable($conn);
ensureLibrarianLevelColumn($conn);
$resolvedSignature = resolveCronSignatureFromUsers($conn, $activeMailConfig);
$activeMailConfig['signatureName'] = $resolvedSignature['name'];
$activeMailConfig['signatureRole'] = $resolvedSignature['role'];
$summary = processBorrowingEmailAlerts($conn, $activeMailConfig);
appendBorrowingMailLog('Cron borrowing reminder job finished');

respond(200, [
    'success' => true,
    'message' => 'Borrowing reminder job executed',
    'summary' => $summary,
]);
