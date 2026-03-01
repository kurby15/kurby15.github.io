<?php
require_once __DIR__ . '/cors.php';
applyCors('GET, POST, PUT, PATCH, DELETE, OPTIONS');

require_once __DIR__ . '/config.php';

function respond($statusCode, $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function parseDataUrl($dataUrl)
{
    if (!is_string($dataUrl)) {
        return null;
    }

    if (!preg_match('/^data:([^;]+);base64,(.+)$/s', $dataUrl, $matches)) {
        return null;
    }

    $mimeType = strtolower(trim($matches[1]));
    $binary = base64_decode($matches[2], true);
    if ($binary === false) {
        return null;
    }

    return [
        'mimeType' => $mimeType,
        'binary' => $binary,
    ];
}

function removeDirectory($directoryPath)
{
    if (!is_dir($directoryPath)) {
        return;
    }

    $items = scandir($directoryPath);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $fullPath = $directoryPath . DIRECTORY_SEPARATOR . $item;
        if (is_dir($fullPath)) {
            removeDirectory($fullPath);
        } else {
            @unlink($fullPath);
        }
    }

    @rmdir($directoryPath);
}

function convertWordToPdfDataUrl($binaryContent, $sourceExtension)
{
    $tmpBase = tempnam(sys_get_temp_dir(), 'rr_');
    if ($tmpBase === false) {
        return null;
    }

    if (file_exists($tmpBase)) {
        @unlink($tmpBase);
    }

    if (!@mkdir($tmpBase, 0700, true)) {
        return null;
    }

    $sourceFile = $tmpBase . DIRECTORY_SEPARATOR . 'source' . $sourceExtension;
    if (file_put_contents($sourceFile, $binaryContent) === false) {
        removeDirectory($tmpBase);
        return null;
    }

    $executables = [
        'soffice',
        'libreoffice',
        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
    ];

    $pdfFile = $tmpBase . DIRECTORY_SEPARATOR . 'source.pdf';

    foreach ($executables as $executable) {
        $command = escapeshellarg($executable)
            . ' --headless --convert-to pdf --outdir '
            . escapeshellarg($tmpBase)
            . ' '
            . escapeshellarg($sourceFile)
            . ' 2>&1';

        $output = [];
        $exitCode = 1;
        @exec($command, $output, $exitCode);

        if ($exitCode === 0 && file_exists($pdfFile)) {
            $pdfBinary = file_get_contents($pdfFile);
            removeDirectory($tmpBase);

            if ($pdfBinary === false) {
                return null;
            }

            return [
                'dataUrl' => 'data:application/pdf;base64,' . base64_encode($pdfBinary),
                'size' => strlen($pdfBinary),
            ];
        }
    }

    removeDirectory($tmpBase);
    return null;
}

function ensureResearchReferencesTable($conn)
{
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS research_references (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            grade_level ENUM('11', '12') NOT NULL,
            track ENUM('STEM', 'ABM', 'HUMSS', 'GAS', 'TVL-ICT', 'TVL-HE') NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            file_data LONGTEXT NOT NULL,
            downloads INT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT UNSIGNED DEFAULT NULL,
            uploaded_by VARCHAR(150) NOT NULL DEFAULT 'Admin Librarian',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_grade_level (grade_level),
            INDEX idx_track (track),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!mysqli_query($conn, $createTableSql)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare research references table',
        ]);
    }

    mysqli_query(
        $conn,
        "UPDATE research_references SET track = 'TVL-ICT' WHERE track = 'TVL'"
    );

    mysqli_query(
        $conn,
        "ALTER TABLE research_references MODIFY COLUMN track ENUM('STEM', 'ABM', 'HUMSS', 'GAS', 'TVL-ICT', 'TVL-HE') NOT NULL"
    );
}

function mapResearchReferenceRow($row)
{
    return [
        'id' => (string) $row['id'],
        'title' => $row['title'],
        'description' => $row['description'],
        'gradeLevel' => $row['grade_level'],
        'track' => $row['track'],
        'fileName' => $row['file_name'],
        'fileSize' => (int) $row['file_size'],
        'fileData' => $row['file_data'],
        'downloads' => (int) ($row['downloads'] ?? 0),
        'uploadedBy' => $row['uploaded_by'] ?: 'Admin Librarian',
        'uploadedDate' => isset($row['created_at']) ? date('Y-m-d', strtotime($row['created_at'])) : date('Y-m-d'),
    ];
}

$method = $_SERVER['REQUEST_METHOD'];
ensureResearchReferencesTable($conn);

if ($method === 'GET') {
    $query = '
        SELECT id, title, description, grade_level, track, file_name, file_size, file_data, downloads, uploaded_by, created_at
        FROM research_references
        ORDER BY created_at DESC, id DESC
    ';

    $result = mysqli_query($conn, $query);
    if (!$result) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to fetch research references',
        ]);
    }

    $references = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $references[] = mapResearchReferenceRow($row);
    }

    respond(200, [
        'success' => true,
        'references' => $references,
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
    $description = isset($data['description']) ? trim($data['description']) : '';
    $gradeLevel = isset($data['gradeLevel']) ? trim($data['gradeLevel']) : '';
    $track = isset($data['track']) ? trim($data['track']) : '';
    $fileName = isset($data['fileName']) ? trim($data['fileName']) : '';
    $fileSize = isset($data['fileSize']) ? (int) $data['fileSize'] : 0;
    $fileData = isset($data['fileData']) ? $data['fileData'] : '';
    $createdBy = isset($data['createdBy']) ? (int) $data['createdBy'] : null;
    $uploadedBy = isset($data['uploadedBy']) ? trim($data['uploadedBy']) : 'Admin Librarian';

    $allowedGrades = ['11', '12'];
    $allowedTracks = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL-ICT', 'TVL-HE'];
    $allowedMimeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    if (
        $title === '' ||
        $description === '' ||
        !in_array($gradeLevel, $allowedGrades, true) ||
        !in_array($track, $allowedTracks, true) ||
        $fileName === '' ||
        $fileData === ''
    ) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid research reference payload',
        ]);
    }

    if ($fileSize < 0) {
        $fileSize = 0;
    }

    $parsedDataUrl = parseDataUrl($fileData);
    if ($parsedDataUrl === null) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid file data format',
        ]);
    }

    $mimeType = $parsedDataUrl['mimeType'];
    $binaryFile = $parsedDataUrl['binary'];

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        respond(400, [
            'success' => false,
            'message' => 'Only PDF, DOC, and DOCX files are allowed',
        ]);
    }

    if ($mimeType !== 'application/pdf') {
        $sourceExtension = $mimeType === 'application/msword' ? '.doc' : '.docx';
        $converted = convertWordToPdfDataUrl($binaryFile, $sourceExtension);

        if ($converted === null) {
            respond(422, [
                'success' => false,
                'message' => 'Automatic DOC/DOCX to PDF conversion is not available on this server. Please upload a PDF file, or install LibreOffice on the server.',
            ]);
        }

        $fileData = $converted['dataUrl'];
        $fileSize = (int) $converted['size'];
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $fileName = ($baseName !== '' ? $baseName : 'document') . '.pdf';
    } else {
        $fileSize = strlen($binaryFile);
    }

    $insertSql = '
        INSERT INTO research_references (
            title,
            description,
            grade_level,
            track,
            file_name,
            file_size,
            file_data,
            created_by,
            uploaded_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ';

    $stmt = mysqli_prepare($conn, $insertSql);
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare research reference insert query',
        ]);
    }

    mysqli_stmt_bind_param(
        $stmt,
        'sssssisis',
        $title,
        $description,
        $gradeLevel,
        $track,
        $fileName,
        $fileSize,
        $fileData,
        $createdBy,
        $uploadedBy
    );

    if (!mysqli_stmt_execute($stmt)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to save research reference',
        ]);
    }

    respond(201, [
        'success' => true,
        'message' => 'Research reference uploaded successfully',
    ]);
}

if ($method === 'PUT') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    $uploadedDate = isset($data['uploadedDate']) ? trim($data['uploadedDate']) : '';

    if ($id <= 0 || $uploadedDate === '') {
        respond(400, [
            'success' => false,
            'message' => 'Research reference id and uploaded date are required',
        ]);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $uploadedDate)) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid date format. Use YYYY-MM-DD',
        ]);
    }

    $parsedTimestamp = strtotime($uploadedDate);
    if ($parsedTimestamp === false || date('Y-m-d', $parsedTimestamp) !== $uploadedDate) {
        respond(400, [
            'success' => false,
            'message' => 'Invalid uploaded date',
        ]);
    }

    $createdAt = $uploadedDate . ' 00:00:00';

    $stmt = mysqli_prepare($conn, 'UPDATE research_references SET created_at = ? WHERE id = ?');
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare research reference update query',
        ]);
    }

    mysqli_stmt_bind_param($stmt, 'si', $createdAt, $id);
    if (!mysqli_stmt_execute($stmt)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to update uploaded date',
        ]);
    }

    if (mysqli_stmt_affected_rows($stmt) === 0) {
        $checkStmt = mysqli_prepare($conn, 'SELECT id FROM research_references WHERE id = ?');
        if (!$checkStmt) {
            respond(500, [
                'success' => false,
                'message' => 'Failed to verify research reference record',
            ]);
        }

        mysqli_stmt_bind_param($checkStmt, 'i', $id);
        mysqli_stmt_execute($checkStmt);
        $existsResult = mysqli_stmt_get_result($checkStmt);
        $existing = $existsResult ? mysqli_fetch_assoc($existsResult) : null;

        if (!$existing) {
            respond(404, [
                'success' => false,
                'message' => 'Research reference not found',
            ]);
        }
    }

    respond(200, [
        'success' => true,
        'message' => 'Upload date updated successfully',
    ]);
}

if ($method === 'PATCH') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;

    if ($id <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Research reference id is required',
        ]);
    }

    $stmt = mysqli_prepare($conn, 'UPDATE research_references SET downloads = downloads + 1 WHERE id = ?');
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare research reference update query',
        ]);
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to increment download count',
        ]);
    }

    if (mysqli_stmt_affected_rows($stmt) === 0) {
        respond(404, [
            'success' => false,
            'message' => 'Research reference not found',
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Download count incremented successfully',
    ]);
}

if ($method === 'DELETE') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    if ($id <= 0) {
        respond(400, [
            'success' => false,
            'message' => 'Research reference id is required',
        ]);
    }

    $stmt = mysqli_prepare($conn, 'DELETE FROM research_references WHERE id = ?');
    if (!$stmt) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to prepare research reference delete query',
        ]);
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        respond(500, [
            'success' => false,
            'message' => 'Failed to delete research reference',
        ]);
    }

    if (mysqli_stmt_affected_rows($stmt) === 0) {
        respond(404, [
            'success' => false,
            'message' => 'Research reference not found',
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Research reference deleted successfully',
    ]);
}

respond(405, [
    'success' => false,
    'message' => 'Method not allowed',
]);
