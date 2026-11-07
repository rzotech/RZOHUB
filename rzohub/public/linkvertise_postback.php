<?php
/**
 * RZO HUB - Linkvertise S2S Postback Endpoint
 * Handles server-to-server conversion notifications from Linkvertise
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/lootlabs.php';

header('Content-Type: application/json; charset=utf-8');

$clickId = trim($_GET['click_id'] ?? $_POST['click_id'] ?? $_GET['token'] ?? '');
$payout = floatval($_GET['payout'] ?? $_POST['payout'] ?? 0);

if (empty($clickId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing click_id or token parameter']);
    exit;
}

$pdo = getDb();
$stmt = $pdo->prepare("SELECT user_id FROM loot_sessions WHERE token = :token LIMIT 1");
$stmt->execute([':token' => $clickId]);
$session = $stmt->fetch();

if (!$session) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Session not found']);
    exit;
}

$result = processKeyClaim((int)$session['user_id'], $clickId);

if ($result['success']) {
    logAction((int)$session['user_id'], 'LINKVERTISE_S2S_POSTBACK_SUCCESS', "Payout: {$payout}");
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'License activated +24H', 'data' => $result]);
} else {
    logAction((int)$session['user_id'], 'LINKVERTISE_S2S_POSTBACK_IGNORED', $result['message']);
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'message' => $result['message']]);
}
exit;

