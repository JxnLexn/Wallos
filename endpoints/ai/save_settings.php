<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$aiEnabled = isset($data['ai_enabled']) ? (bool) $data['ai_enabled'] : false;
$aiType = isset($data['ai_type']) ? trim($data['ai_type']) : '';
$aiApiKey = isset($data['api_key']) ? trim($data['api_key']) : '';
$aiOllamaHost = isset($data['ollama_host']) ? trim($data['ollama_host']) : '';
$aiModel = isset($data['model']) ? trim($data['model']) : '';

// Read run_schedule from request, falling back to existing DB value to avoid
// overwriting when old cached JS doesn't send this field
$aiRunSchedule = 'manual';
if (isset($data['run_schedule']) && $data['run_schedule'] === 'automatic') {
    $aiRunSchedule = 'automatic';
} elseif (!isset($data['run_schedule'])) {
    // Fallback: preserve existing DB value if the field was not sent at all
    $existingStmt = $db->prepare("SELECT run_schedule FROM ai_settings WHERE user_id = ? LIMIT 1");
    $existingStmt->bindValue(1, $userId, SQLITE3_INTEGER);
    $existingResult = $existingStmt->execute();
    $existingRow = $existingResult->fetchArray(SQLITE3_ASSOC);
    $existingStmt->close();
    if ($existingRow && isset($existingRow['run_schedule'])) {
        $aiRunSchedule = $existingRow['run_schedule'];
    }
}

if (empty($aiType) || !in_array($aiType, ['chatgpt', 'gemini', 'openrouter', 'ollama'])) {
    $response = [
        "success" => false,
        "message" => translate('error', $i18n)
    ];
    echo json_encode($response);
    exit;
}

if (($aiType === 'chatgpt' || $aiType === 'gemini' || $aiType === 'openrouter') && empty($aiApiKey)) {
    $response = [
        "success" => false,
        "message" => translate('invalid_api_key', $i18n)
    ];
    echo json_encode($response);
    exit;
}

if ($aiType === 'ollama' && empty($aiOllamaHost)) {
    $response = [
        "success" => false,
        "message" => translate('invalid_host', $i18n)
    ];
    echo json_encode($response);
    exit;
}

if (empty($aiModel)) {
    $response = [
        "success" => false,
        "message" => translate('fill_mandatory_fields', $i18n)
    ];
    echo json_encode($response);
    exit;
}

if ($aiType === 'ollama') {
    $aiApiKey = ''; // Ollama does not require an API key
} else {
    $aiOllamaHost = ''; // Clear Ollama host if not using Ollama
}

// Remove existing AI settings for the user
$stmt = $db->prepare("DELETE FROM ai_settings WHERE user_id = ?");
$stmt->bindValue(1, $userId, SQLITE3_INTEGER);
$stmt->execute();
$stmt->close();

// Insert new AI settings
$stmt = $db->prepare("INSERT INTO ai_settings (user_id, type, enabled, api_key, model, url, run_schedule) VALUES (:user_id, :type, :enabled, :api_key, :model, :url, :run_schedule)");
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':type', $aiType, SQLITE3_TEXT);
$stmt->bindValue(':enabled', $aiEnabled, SQLITE3_INTEGER);
$stmt->bindValue(':api_key', $aiApiKey, SQLITE3_TEXT);
$stmt->bindValue(':model', $aiModel, SQLITE3_TEXT);
$stmt->bindValue(':url', $aiOllamaHost, SQLITE3_TEXT);
$stmt->bindValue(':run_schedule', $aiRunSchedule, SQLITE3_TEXT);
$result = $stmt->execute();

if ($result) {
    $response = [
        "success" => true,
        "message" => translate('success', $i18n),
        "enabled" => $aiEnabled
    ];
} else {
    $response = [
        "success" => false,
        "message" => translate('error', $i18n)
    ];
}
echo json_encode($response);
