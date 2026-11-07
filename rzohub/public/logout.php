<?php
/**
 * RZO Key Management - Logout Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

logoutUser();
setFlash('info', 'You have been signed out successfully.');
header('Location: login.php');
exit;
