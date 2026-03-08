<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$runSchedule = isset($data['run_schedule']) && $data['run_schedule'] === 'automatic' ? 'automatic' : 'manual';

// Check if ai_settings row exists for this user
$stmt = $db->prepare("SELECT id FROM ai_settings WHERE user_id = ? LIMIT 1");
$stmt->bindValue(1, $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$exists = $result->fetchArray(SQLITE3_ASSOC);
$stmt->close();

if ($exists) {
    $stmt = $db->prepare("UPDATE ai_settings SET run_schedule = :run_schedule WHERE user_id = :user_id");
    $stmt->bindValue(':run_schedule', $runSchedule, SQLITE3_TEXT);
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
} else {
    $response = [
        "success" => false,
        "message" => translate('error', $i18n)
    ];
    echo json_encode($response);
    exit;
}

if ($result) {
    $response = [
        "success" => true,
        "message" => translate('success', $i18n)
    ];
} else {
    $response = [
        "success" => false,
        "message" => translate('error', $i18n)
    ];
}
echo json_encode($response);
