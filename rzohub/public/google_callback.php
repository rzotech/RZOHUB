<?php
/**
 * RZO HUB - Google Identity Services Callback
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['credential'])) {
    setFlash('danger', 'Invalid Google authentication request.');
    header('Location: login.php');
    exit;
}

$credential = $_POST['credential'];
$authResult = handleGoogleAuth($credential);

if ($authResult['success']) {
    if (!empty($authResult['is_new'])) {
        $msg = ($authResult['role'] === 'admin') 
            ? 'Welcome Administrator! Account created via Google.'
            : 'Account created with Google! Welcome to RZO HUB.';
        setFlash('success', $msg);
    } else {
        $msg = ($authResult['role'] === 'admin')
            ? 'Welcome Administrator! Signed in via Google.'
            : 'Signed in successfully via Google.';
        setFlash('success', $msg);
    }

    if ($authResult['role'] === 'admin') {
        header('Location: admin.php');
    } else {
        header('Location: dashboard.php');
    }
    exit;
} else {
    setFlash('danger', $authResult['message'] ?? 'Google authentication failed.');
    header('Location: login.php');
    exit;
}
