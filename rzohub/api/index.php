<?php
/**
 * RZO HUB - Vercel Serverless Master Gateway
 * Routes incoming serverless invocations to corresponding public handlers.
 */

declare(strict_types=1);

set_exception_handler(function (Throwable $e) {
    error_log("UNHANDLED CRITICAL EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);

    $isApi = str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'valid' => false,
            'code' => 'INTERNAL_ERROR',
            'message' => 'An internal server error occurred. Check /status.php for system diagnostics.'
        ]);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>System Error &mdash; RZO HUB</title><link rel="stylesheet" href="/assets/css/style.css"></head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:#050508;color:#fff;font-family:sans-serif;"><div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.1);padding:32px;border-radius:12px;max-width:500px;text-align:center;"><h2 style="margin-top:0;">Temporary System Notice</h2><p style="color:#a1a1aa;font-size:0.9rem;">An error occurred during request dispatch. System diagnostics are available.</p><a href="/status.php" style="display:inline-block;margin-top:16px;padding:10px 20px;background:#fff;color:#000;text-decoration:none;border-radius:6px;font-weight:600;font-size:0.85rem;">View Diagnostics &rarr;</a></div></body></html>';
    exit;
});

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = trim($requestUri, '/');

// 1. Root / Landing
if ($path === '' || $path === 'index' || $path === 'index.php') {
    require __DIR__ . '/../public/index.php';
    exit;
}

// 2. Direct match in public directory (e.g. login.php, dashboard.php)
$target = __DIR__ . '/../public/' . $path;
if (file_exists($target) && !is_dir($target)) {
    require $target;
    exit;
}

// 3. Extensionless URL match (e.g. /login -> /public/login.php)
if (file_exists($target . '.php')) {
    require $target . '.php';
    exit;
}

// 4. API endpoint match (e.g. /api/verify)
if (str_starts_with($path, 'api/')) {
    $apiFile = __DIR__ . '/../public/' . $path;
    if (file_exists($apiFile . '.php')) {
        require $apiFile . '.php';
        exit;
    }
    if (file_exists($apiFile) && !is_dir($apiFile)) {
        require $apiFile;
        exit;
    }
}

// 5. Default fallback
http_response_code(404);
require __DIR__ . '/../public/index.php';
