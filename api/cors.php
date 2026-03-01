<?php

function applyCors($allowedMethods = 'GET, POST, PUT, DELETE, OPTIONS')
{
    header('Content-Type: application/json');
    $defaultAllowedOrigins = [
        'https://smnhslibrary.me',
        'https://www.smnhslibrary.me',
        'https://api.smnhslibrary.me',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost',
        'http://127.0.0.1',
    ];

    $configuredAllowedOrigins = getenv('CORS_ALLOWED_ORIGINS');
    $allowedOrigins = $defaultAllowedOrigins;

    if ($configuredAllowedOrigins !== false && trim($configuredAllowedOrigins) !== '') {
        $extraOrigins = array_filter(array_map('trim', explode(',', $configuredAllowedOrigins)));
        $allowedOrigins = array_values(array_unique(array_merge($defaultAllowedOrigins, $extraOrigins)));
    }

    $normalizedAllowedOrigins = array_map(
        static fn($origin) => rtrim($origin, '/'),
        $allowedOrigins,
    );

    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($requestOrigin !== '') {
        $normalizedRequestOrigin = rtrim($requestOrigin, '/');
        if (in_array($normalizedRequestOrigin, $normalizedAllowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $normalizedRequestOrigin);
            header('Vary: Origin');
        } else {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Origin not allowed.',
            ]);
            exit;
        }
    }

    header('Access-Control-Allow-Methods: ' . $allowedMethods);
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
