<?php
// --- OLLAMA LOCAL AI SUPPORT ---
function callOllama($prompt, $model = 'gemma:2b') {
    $ch = curl_init('http://localhost:11434/api/generate');

    // Enable streaming for faster perceived response if frontend supports it
    $payload = json_encode([
        'model' => $model,
        'prompt' => $prompt,
        'stream' => true
    ]);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);

    if ($response === false) {
        error_log("cURL Error: " . curl_error($ch));
        curl_close($ch);
        return [null, 'Ollama is not running or not reachable on 127.0.0.1:11434'];
    }

    curl_close($ch);

    // If streaming, Ollama returns NDJSON (one JSON per line)
    $lines = explode("\n", $response);
    $final = '';
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $obj = json_decode($line, true);
        if (isset($obj['response'])) {
            $final .= $obj['response'];
        }
        if (isset($obj['error'])) {
            return [null, $obj['error']];
        }
    }
    return [$final !== '' ? $final : null, null];
}
require_once __DIR__ . '/cors.php';
applyCors('POST, OPTIONS');

function readEnvValueFromFiles($key)
{
    $candidateFiles = [
        __DIR__ . '/../.env.local',
        __DIR__ . '/../.env',
    ];

    foreach ($candidateFiles as $filePath) {
        if (!file_exists($filePath)) {
            continue;
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $equalsPos = strpos($line, '=');
            if ($equalsPos === false) {
                continue;
            }

            $envKey = trim(substr($line, 0, $equalsPos));
            if ($envKey !== $key) {
                continue;
            }

            $rawValue = trim(substr($line, $equalsPos + 1));
            return trim($rawValue, "\"'");
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed',
    ]);
    exit;
}

require_once __DIR__ . '/config.php';

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = [];
}


$userRole = isset($data['userRole']) ? trim((string) $data['userRole']) : '';
$userName = isset($data['userName']) ? trim((string) $data['userName']) : '';
$message = isset($data['message']) ? trim((string) $data['message']) : '';
$portalContext = isset($data['portalContext']) ? trim((string) $data['portalContext']) : '';
$localFallback = isset($data['localFallback']) ? trim((string) $data['localFallback']) : '';
$recentMessages = isset($data['recentMessages']) && is_array($data['recentMessages']) ? $data['recentMessages'] : [];
$profilePicture = isset($data['profilePicture']) ? trim((string) $data['profilePicture']) : null;

// DEBUG: Log the received message to a file for troubleshooting
$debugLogFile = __DIR__ . '/chatbot_debug.log';
$debugMsg = date('Y-m-d H:i:s') . " | userRole: $userRole | message: $message\n";
file_put_contents($debugLogFile, $debugMsg, FILE_APPEND);

$allowedRoles = ['student', 'teacher', 'librarian'];

if ($message === '' || !in_array($userRole, $allowedRoles, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid chatbot payload',
    ]);
    exit;
}



$historyLines = [];
$recentMessagesLimited = array_slice($recentMessages, -5); // Only last 5 messages
foreach ($recentMessagesLimited as $item) {
    if (!is_array($item)) {
        continue;
    }
    $sender = isset($item['sender']) ? trim((string) $item['sender']) : '';
    $text = isset($item['text']) ? trim((string) $item['text']) : '';
    if ($sender === '' || $text === '') {
        continue;
    }
    $historyLines[] = strtoupper($sender) . ': ' . $text;
}
$historyText = implode("\n", $historyLines);

// ...existing code...
$systemPrompt = "You are Libby, an assistant for the SMNHS Library portal.\n"
    . "You must communicate clearly, kindly, and quickly with all users: students, teachers, and librarians.\n"
    . "Always clarify the difference between similar features if users seem confused.\n"
    . "\n"
    . "Student Portal Features and Workflows:\n"
    . "- Dashboard: Shows your current borrowed books, reservations, and recent updates.\n"
    . "- Search Books: Find books by keyword, title, author, ISBN, or subject. Use filters to narrow results. To reserve a book: Search, select a title, click Reserve, and confirm.\n"
    . "- Research References: Access and download PDF research materials by grade and academic track (STEM, ABM, HUMSS, GAS, TVL-ICT, TVL-HE). This is for academic research, not for searching the book catalog.\n"
    . "- My Books: Track all your borrowed books, due dates, active reservations, and borrowing history.\n"
    . "- Recommendations: See suggested books based on your activity and popular reads.\n"
    . "- Notifications: Bell icon and notifications tab show reservation updates, due date reminders, and announcements.\n"
    . "- Profile: Upload your picture, change password, logout.\n"
    . "- Restrictions: Overdue books may limit access to some features.\n"
    . "- Reservation Pickup: When your reserved book is ready for pickup, you will receive a notification in the portal. Check the Notifications tab or bell icon. The notification will say your book is ready and include pickup instructions. If you are unsure, always check your Notifications and My Books tab for status.\n"
    . "- Overdue Books: Check 'My Books' for due dates. Return books on time to avoid restrictions.\n"
    . "- If a student asks how to know if a book is ready for pickup, answer: 'You will receive a notification in the portal when your reserved book is ready. Check the Notifications tab or bell icon. The My Books tab will also show the status.'\n"
    . "\n"
    . "Teacher Portal Features and Workflows:\n"
    . "- Dashboard: Quick actions and overview of reading lists and requests.\n"
    . "- Reading Lists: Create and manage reading lists for students. Add books, set descriptions, and save.\n"
    . "- Resource Requests: Request new resources for the library. Submit title, details, and justification.\n"
    . "- Notifications: Updates and announcements about requests and reading lists.\n"
    . "- Profile: Upload picture, change password, logout.\n"
    . "\n"
    . "Librarian Portal Features and Workflows:\n"
    . "- Book Catalog: Add, edit, and manage books. Update metadata, stock, and shelf details.\n"
    . "- Borrowing & Returns: Manage checkouts and returns. For checkout: Find student, select/scan book, confirm due date, complete checkout. For return: Find/scan book, mark returned, update condition.\n"
    . "- Reservations: Approve and manage book reservations. Mark as ready, notify student, complete release on pickup.\n"
    . "- Research References: Upload and manage academic PDFs. Set grade and track, save to publish.\n"
    . "- Analytics & Reports: View/export borrowing trends, popular books, user activity.\n"
    . "- Announcements: Post updates for users. Set title, content, audience, and publish.\n"
    . "- Manage Librarians: Set permissions and manage librarian accounts.\n"
    . "- Notifications: Operational updates for reservations, overdue alerts, requests, and announcements.\n"
    . "- Profile: Upload picture, change password, edit info, logout.\n"
    . "\n"
    . "If a student asks about 'search' vs 'research', explain:\n"
    . "- 'Search Books' is for finding library books.\n"
    . "- 'Research References' is for academic research PDFs.\n"
    . "\n"
    . "If a user asks about a workflow (e.g., how to pick up a reserved book, how to get notified, how to upload a reference, how to request a resource), provide a clear, step-by-step answer based on the system flow above.\n"
    . "If the user asks about something outside the portal or the context does not provide an answer, you may use your general knowledge to help.\n"
    . "Always be helpful, concise, and professional. Respond as quickly as possible.\n"
    . "Do not invent unavailable system features.\n"
    . "When uncertain about a portal-specific question, tell the user to contact librarian/admin for verification.";

$userPromptParts = [
    "User role: {$userRole}",
    "User name: " . ($userName !== '' ? $userName : 'Unknown'),
    "Portal context:\n{$portalContext}",
];

if ($historyText !== '') {
    $userPromptParts[] = "Recent chat:\n{$historyText}";
}

$userPromptParts[] = "User question:\n{$message}";

if ($localFallback !== '') {
    $userPromptParts[] = "If context is limited, you may use this fallback guidance:\n{$localFallback}";
}

$userPromptParts[] = "Answer clearly with steps when possible.";

$userPrompt = implode("\n\n", $userPromptParts);


// --- Use Ollama instead of Gemini ---
$ollamaModel = 'llama3:8b-instruct-q4_K_M'; // Try a quantized fast model, fallback to gemma:2b if not available
echo json_encode([

// Fast static answer for Research References and similar queries
$msg = strtolower($message);

// Fast static answer for Research References and similar queries
if (
    strpos($msg, 'research reference') !== false ||
    strpos($msg, 'research') !== false ||
    strpos($msg, 'reference') !== false ||
    strpos($msg, 'pdf') !== false
) {
    $staticAnswer = "How to use Research References:\n" .
        "1. Open the 'Research References' tab in the portal.\n" .
        "2. Select your grade and academic track (STEM, ABM, HUMSS, GAS, TVL-ICT, TVL-HE).\n" .
        "3. Browse or search for available PDF research files.\n" .
        "4. Click to view or download the material you need.\n" .
        "\nResearch References is for academic PDFs. For library books, use the 'Search Books' tab.";
    echo json_encode([
        'success' => true,
        'answer' => $staticAnswer,
        'profilePicture' => $profilePicture,
    ]);
    exit;
}

// Fast static answer for librarian edit info process
if ($userRole === 'librarian' && (strpos($msg, 'edit info') !== false || strpos($msg, 'edit profile') !== false || strpos($msg, 'update info') !== false)) {
    $staticAnswer = "How to edit your librarian profile info:\n" .
        "1. Click your profile icon (top right of the portal).\n" .
        "2. In the dropdown menu, select 'Edit Info'.\n" .
        "3. Update your details as needed:\n" .
        "   • First Name\n" .
        "   • Middle Name (optional)\n" .
        "   • Last Name\n" .
        "   • Email Address\n" .
        "   • Employee ID\n" .
        "4. After making changes, click 'Save'.\n" .
        "\nTips:\n- Make sure your email is correct for notifications.\n- If you change your email, you may need to verify it.\n- If you have trouble editing your info or see an error, contact the system administrator.";
    echo json_encode([
        'success' => true,
        'answer' => $staticAnswer,
        'profilePicture' => $profilePicture,
    ]);
    exit;
}

$fullPrompt = $systemPrompt . "\n\n" . $userPrompt;
list($answer, $ollamaError) = callOllama($fullPrompt, $ollamaModel);

if (!is_string($answer) || trim($answer) === '') {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => $ollamaError ?: 'Ollama local AI is unavailable. Please ensure Ollama is running and the model is pulled.',
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'answer' => $answer,
    'profilePicture' => $profilePicture,
]);
