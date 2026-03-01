<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'library';

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
    ]);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');

/*
|--------------------------------------------------------------------------
| Gmail SMTP Configuration
|--------------------------------------------------------------------------
|
| 1. Enable 2-Step Verification in your Google account
| 2. Generate App Password for Mail
| 3. Put that 16-character password below
|
*/

$mailConfig = [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => 'smnhs.library123@gmail.com',            // CHANGE THIS
    'password' => 'gklfxpnfjmpikaax', // CHANGE THIS
    'encryption' => 'tls',
    'fromAddress' => 'smnhs.library123@gmail.com',         // CHANGE THIS
    'fromName' => 'San Martin Library',
    'signatureName' => 'Head Librarian',                   // CHANGE THIS
    'signatureRole' => 'Librarian',                        // CHANGE THIS
];

/*
|--------------------------------------------------------------------------
| Gemini API Configuration (Optional)
|--------------------------------------------------------------------------
|
| Preferred: set GEMINI_API_KEY and GEMINI_MODEL in Apache/PHP environment.
| Fallback: place values below if environment variables are not available.
|
*/

$geminiConfig = [
    'apiKey' => 'AIzaSyAiyBa7eVpwOXAlFJ1HgIVrKnq2CSOgTA4', // Optional fallback key; leave blank if using environment variable
    'model' => 'gemini-2.0-flash',
];