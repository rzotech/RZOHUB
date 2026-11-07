<?php
/**
 * RZO Key Management - Logout Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

logoutUser();
setFlash('info', 'Anda telah keluar dari sesi.');
header('Location: login.php');
exit;
