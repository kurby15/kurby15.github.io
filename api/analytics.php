<?php
require_once __DIR__ . '/cors.php';
applyCors('GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

require_once __DIR__ . '/config.php';

function scalarCount($conn, $query) {
    $result = mysqli_query($conn, $query);
    if (!$result) {
        return 0;
    }
    $row = mysqli_fetch_assoc($result);
    return (int) ($row['count_val'] ?? 0);
}

$totalBooks = scalarCount($conn, 'SELECT COUNT(*) AS count_val FROM books');
$activeUsers = scalarCount($conn, "SELECT COUNT(*) AS count_val FROM users WHERE role IN ('student','teacher','librarian')");
$monthlyBorrows = scalarCount($conn, "SELECT COUNT(*) AS count_val FROM borrowings WHERE DATE_FORMAT(borrow_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
$overdueBooks = scalarCount($conn, "SELECT COUNT(*) AS count_val FROM borrowings WHERE status = 'overdue'");

$availableBooks = scalarCount($conn, "SELECT COUNT(*) AS count_val FROM books WHERE status = 'Available'");
$borrowedBooks = scalarCount($conn, "SELECT COUNT(*) AS count_val FROM books WHERE status = 'Borrowed'");

$statusCopies = [
    'availableCopies' => 0,
    'borrowedCopies' => 0,
    'overdueCopies' => 0,
];
$statusCopiesQuery = "
    SELECT
        SUM(
            GREATEST(
                COALESCE(b.quantity, 1) - COALESCE(borrow_stats.active_count, 0) - COALESCE(borrow_stats.overdue_count, 0),
                0
            )
        ) AS available_copies,
        SUM(COALESCE(borrow_stats.active_count, 0)) AS borrowed_copies,
        SUM(COALESCE(borrow_stats.overdue_count, 0)) AS overdue_copies
    FROM books b
    LEFT JOIN (
        SELECT
            book_id,
            SUM(CASE WHEN LOWER(status) = 'active' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN LOWER(status) = 'overdue' THEN 1 ELSE 0 END) AS overdue_count
        FROM borrowings
        GROUP BY book_id
    ) AS borrow_stats ON borrow_stats.book_id = b.id
";
$statusCopiesResult = mysqli_query($conn, $statusCopiesQuery);
if ($statusCopiesResult) {
    $copiesRow = mysqli_fetch_assoc($statusCopiesResult);
    if ($copiesRow) {
        $statusCopies = [
            'availableCopies' => (int) ($copiesRow['available_copies'] ?? 0),
            'borrowedCopies' => (int) ($copiesRow['borrowed_copies'] ?? 0),
            'overdueCopies' => (int) ($copiesRow['overdue_copies'] ?? 0),
        ];
    }
}

$monthlyTrends = [];
$monthlyQuery = "
    SELECT DATE_FORMAT(borrow_date, '%Y-%m') AS month_key, COUNT(*) AS borrow_count
    FROM borrowings
    WHERE borrow_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY DATE_FORMAT(borrow_date, '%Y-%m')
";
$monthlyResult = mysqli_query($conn, $monthlyQuery);
$monthlyMap = [];
if ($monthlyResult) {
    while ($row = mysqli_fetch_assoc($monthlyResult)) {
        $monthlyMap[$row['month_key']] = (int) $row['borrow_count'];
    }
}

for ($i = 5; $i >= 0; $i--) {
    $monthKey = date('Y-m', strtotime("-{$i} month"));
    $monthLabel = date('M', strtotime("-{$i} month"));
    $monthlyTrends[] = [
        'month' => $monthLabel,
        'borrows' => (int) ($monthlyMap[$monthKey] ?? 0),
    ];
}

$dailyTrends = [];
$dailyQuery = "
    SELECT DATE(borrow_date) AS day_key, COUNT(*) AS borrow_count
    FROM borrowings
    WHERE borrow_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(borrow_date)
";
$dailyResult = mysqli_query($conn, $dailyQuery);
$dailyMap = [];
if ($dailyResult) {
    while ($row = mysqli_fetch_assoc($dailyResult)) {
        $dailyMap[$row['day_key']] = (int) $row['borrow_count'];
    }
}

for ($i = 29; $i >= 0; $i--) {
    $dayKey = date('Y-m-d', strtotime("-{$i} day"));
    $dayLabel = date('M j', strtotime("-{$i} day"));
    $dailyTrends[] = [
        'day' => $dayLabel,
        'borrows' => (int) ($dailyMap[$dayKey] ?? 0),
    ];
}

$popularSubjects = [];
$subjectQuery = "
    SELECT b.subject, COUNT(*) AS borrow_count
    FROM borrowings br
    INNER JOIN books b ON b.id = br.book_id
    GROUP BY b.subject
    ORDER BY borrow_count DESC, b.subject ASC
    LIMIT 6
";
$subjectResult = mysqli_query($conn, $subjectQuery);
if ($subjectResult) {
    while ($row = mysqli_fetch_assoc($subjectResult)) {
        $popularSubjects[] = [
            'subject' => $row['subject'],
            'count' => (int) $row['borrow_count'],
        ];
    }
}

$mostBorrowedBooks = [];
$mostBorrowedQuery = "
    SELECT
        b.id,
        b.title,
        COUNT(*) AS borrow_count,
        MIN(br.borrow_date) AS first_borrow_date,
        SUM(
            CASE
                WHEN DATE_FORMAT(br.borrow_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
                THEN 1 ELSE 0
            END
        ) AS current_month_count,
        SUM(
            CASE
                WHEN DATE_FORMAT(br.borrow_date, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')
                THEN 1 ELSE 0
            END
        ) AS previous_month_count
    FROM borrowings br
    INNER JOIN books b ON b.id = br.book_id
    GROUP BY b.id, b.title
    ORDER BY borrow_count DESC, first_borrow_date ASC, b.title ASC
    LIMIT 5
";
$mostBorrowedResult = mysqli_query($conn, $mostBorrowedQuery);
if ($mostBorrowedResult) {
    while ($row = mysqli_fetch_assoc($mostBorrowedResult)) {
        $currentMonthCount = (int) ($row['current_month_count'] ?? 0);
        $previousMonthCount = (int) ($row['previous_month_count'] ?? 0);

        if ($previousMonthCount > 0) {
            $trendPercent = round((($currentMonthCount - $previousMonthCount) / $previousMonthCount) * 100);
        } elseif ($currentMonthCount > 0) {
            $trendPercent = 100;
        } else {
            $trendPercent = 0;
        }

        $trendPrefix = $trendPercent > 0 ? '+' : '';

        $mostBorrowedBooks[] = [
            'id' => (string) $row['id'],
            'title' => $row['title'],
            'count' => (int) $row['borrow_count'],
            'trend' => $trendPrefix . $trendPercent . '%',
        ];
    }
}

$highDemandBooks = [];
$highDemandQuery = "
    SELECT b.id, b.title, COUNT(br.id) AS borrow_count
    FROM books b
    LEFT JOIN borrowings br ON br.book_id = b.id
    GROUP BY b.id, b.title
    HAVING borrow_count >= 2
    ORDER BY borrow_count DESC, b.title ASC
";
$highDemandResult = mysqli_query($conn, $highDemandQuery);
if ($highDemandResult) {
    while ($row = mysqli_fetch_assoc($highDemandResult)) {
        $highDemandBooks[] = [
            'id' => (string) $row['id'],
            'title' => $row['title'],
            'borrowCount' => (int) $row['borrow_count'],
        ];
    }
}

$lowUsageBooks = [];
$lowUsageQuery = "
    SELECT b.id, b.title, COUNT(br.id) AS borrow_count
    FROM books b
    LEFT JOIN borrowings br ON br.book_id = b.id
    GROUP BY b.id, b.title
    HAVING borrow_count = 0
    ORDER BY b.title ASC
";
$lowUsageResult = mysqli_query($conn, $lowUsageQuery);
if ($lowUsageResult) {
    while ($row = mysqli_fetch_assoc($lowUsageResult)) {
        $lowUsageBooks[] = [
            'id' => (string) $row['id'],
            'title' => $row['title'],
            'borrowCount' => 0,
        ];
    }
}

echo json_encode([
    'success' => true,
    'stats' => [
        'totalBooks' => $totalBooks,
        'activeUsers' => $activeUsers,
        'monthlyBorrows' => $monthlyBorrows,
        'overdueBooks' => $overdueBooks,
        'availableBooks' => $availableBooks,
        'borrowedBooks' => $borrowedBooks,
        'availableCopies' => $statusCopies['availableCopies'],
        'borrowedCopies' => $statusCopies['borrowedCopies'],
        'overdueCopies' => $statusCopies['overdueCopies'],
        'monthlyTrends' => $monthlyTrends,
        'dailyTrends' => $dailyTrends,
        'popularSubjects' => $popularSubjects,
        'mostBorrowedBooks' => $mostBorrowedBooks,
        'highDemandBooks' => $highDemandBooks,
        'lowUsageBooks' => $lowUsageBooks,
    ],
]);
