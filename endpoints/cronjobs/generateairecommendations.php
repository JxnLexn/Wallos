<?php

set_time_limit(300);

require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';

require 'settimezone.php';

if (php_sapi_name() == 'cli') {
    $date = new DateTime('now');
    echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";
}

// --- Helper functions (mirrored from endpoints/ai/generate_recommendations.php) ---

function getPricePerMonth($cycle, $frequency, $price)
{
    switch ($cycle) {
        case 1:
            return $price * (30 / $frequency);        // daily
        case 2:
            return $price * (4.35 / $frequency);       // weekly
        case 3:
            return $price / $frequency;                // monthly
        case 4:
            return $price / (12 * $frequency);         // yearly
        default:
            return $price;
    }
}

function describeFrequency($cycle, $frequency)
{
    $unit = match ($cycle) {
        1 => 'day',
        2 => 'week',
        3 => 'month',
        4 => 'year',
        default => 'unit'
    };

    if ($frequency == 1) {
        return "Every $unit";
    } else {
        return "Every $frequency {$unit}s";
    }
}

// --- Main: iterate over all users ---

$usersResult = $db->query("SELECT id FROM user");

while ($userRow = $usersResult->fetchArray(SQLITE3_ASSOC)) {
    $userId = $userRow['id'];

    // Load AI settings for this user
    $stmt = $db->prepare("SELECT * FROM ai_settings WHERE user_id = ? LIMIT 1");
    $stmt->bindValue(1, $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $aiSettings = $result->fetchArray(SQLITE3_ASSOC);
    $stmt->close();

    if (!$aiSettings) {
        echo "Skipping user $userId: no AI settings configured<br />\n";
        continue;
    }

    // Check schedule — only run for "automatic"
    $runSchedule = isset($aiSettings['run_schedule']) ? $aiSettings['run_schedule'] : 'manual';
    if ($runSchedule !== 'automatic') {
        echo "Skipping user $userId: schedule is manual<br />\n";
        continue;
    }

    // Check enabled
    $enabled = isset($aiSettings['enabled']) ? (bool) $aiSettings['enabled'] : false;
    if (!$enabled) {
        echo "Skipping user $userId: AI is disabled<br />\n";
        continue;
    }

    $type = isset($aiSettings['type']) ? $aiSettings['type'] : '';
    $model = isset($aiSettings['model']) ? $aiSettings['model'] : '';

    if (!in_array($type, ['chatgpt', 'gemini', 'openrouter', 'ollama']) || empty($model)) {
        echo "Skipping user $userId: invalid provider or model<br />\n";
        continue;
    }

    $host = "";
    $apiKey = "";

    if ($type === 'ollama') {
        $host = isset($aiSettings['url']) ? $aiSettings['url'] : '';
        if (empty($host)) {
            echo "Skipping user $userId: ollama host not set<br />\n";
            continue;
        }
    } else {
        $apiKey = isset($aiSettings['api_key']) ? $aiSettings['api_key'] : '';
        if (empty($apiKey)) {
            echo "Skipping user $userId: API key not set<br />\n";
            continue;
        }
    }

    echo "Processing user $userId...<br />\n";

    // --- Fetch user data ---

    // Categories
    $stmt = $db->prepare("SELECT * FROM categories WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $categories = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $categories[$row['id']] = $row;
    }

    // Currencies
    $stmt = $db->prepare("SELECT * FROM currencies WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $currencies = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $currencies[$row['id']] = $row;
    }

    // Household members
    $stmt = $db->prepare("SELECT * FROM household WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $members = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $members[$row['id']] = $row;
    }

    // User language
    $stmt = $db->prepare("SELECT language FROM user WHERE id = :user_id");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $userLanguage = $result->fetchArray(SQLITE3_ASSOC)['language'] ?? 'en';

    require_once __DIR__ . '/../../includes/i18n/languages.php';
    $userLanguageName = $languages[$userLanguage]['name'] ?? 'English';

    // Subscriptions
    $stmt = $db->prepare("SELECT * FROM subscriptions WHERE user_id = :user_id AND inactive = 0");
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $subscriptions = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $subscriptions[] = $row;
    }

    if (empty($subscriptions)) {
        echo "Skipping user $userId: no active subscriptions<br />\n";
        continue;
    }

    // Build subscription data for AI
    $subscriptionsForAI = [];
    foreach ($subscriptions as $row) {
        if ($row['inactive'])
            continue;

        $price = round($row['price'], 2);
        $currencyCode = $currencies[$row['currency_id']]['code'] ?? '';
        $priceFormatted = $currencyCode ? "$price $currencyCode" : "$price";

        $payerName = $members[$row['payer_user_id']]['name'] ?? 'Unknown';

        $subscriptionsForAI[] = [
            'name' => $row['name'],
            'price' => $priceFormatted,
            'frequency' => describeFrequency($row['cycle'], $row['frequency']),
            'category' => $categories[$row['category_id']]['name'] ?? 'Uncategorized',
            'payer' => $payerName
        ];
    }

    // --- Build prompt ---

    $prompt = <<<PROMPT
    You are a helpful assistant designed to help users save money on digital subscriptions.

    The user has shared a list of their active subscriptions across household members. For each subscription, you are given:
    - Name of the service
    - Price (in original currency)
    - Payment frequency (e.g., every month, every year, etc.)
    - Category
    - Payer (which household member pays for it)

    Analyze the data and give 3 to 7 smart and specific recommendations to reduce subscription costs. If possible, include estimated savings for each suggestion.

    Follow these guidelines:
    - Do NOT suggest switching to family or group plans unless two or more different household members are paying for the same or similar service.
    - Recognize known feature overlaps, such as:
    • YouTube Premium includes YouTube Music.
    • Amazon Prime includes Prime Video.
    • Google One, iCloud+, and Proton all offer cloud storage.
    • Real Debrid, All Debrid, and Premiumize offer similar download capabilities.
    - Suggest rotating or cancelling subscriptions that serve similar purposes (e.g. multiple streaming or IPTV services).
    - Recommend switching from monthly to yearly plans only if it provides clear savings and the user is likely to keep the service long-term.
    - Suggest looking for promo or new customer deals if a service appears overpriced.
    - Only recommend cancelling rarely used services if they do not provide unique value.

    Return the result as a JSON array. Each item in the array should have:
    - "title": a short summary of the suggestion
    - "description": a longer explanation with reasoning
    - "savings": a rough estimate like "10 EUR/month" or "60 EUR/year" (if possible)

    If possible, all text should be in the user's language: {$userLanguageName}. Otherwise, use English.

    Do not include any other text, just the JSON output. Absolutely no additional comments or explanations.

    Here is the user's data:
    PROMPT;

    $prompt .= "\n\n" . json_encode($subscriptionsForAI, JSON_PRETTY_PRINT);

    // --- Call AI API ---

    $ch = curl_init();

    if ($type === 'ollama') {
        curl_setopt($ch, CURLOPT_URL, $host . '/api/generate');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['model' => $model, 'prompt' => $prompt, 'stream' => false]));
    } else {
        $headers = ['Content-Type: application/json'];

        if ($type === 'chatgpt') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $prompt]]
            ]));
        } elseif ($type === 'gemini') {
            curl_setopt(
                $ch,
                CURLOPT_URL,
                'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) .
                ':generateContent?key=' . urlencode($apiKey)
            );
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'contents' => [
                    [
                        'parts' => [['text' => $prompt]]
                    ]
                ]
            ]));
        } elseif ($type === 'openrouter') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            curl_setopt($ch, CURLOPT_URL, 'https://openrouter.ai/api/v1/chat/completions');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => $prompt]]
            ]));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);

    $reply = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "Error for user $userId: " . curl_error($ch) . "<br />\n";
        curl_close($ch);
        continue;
    }

    curl_close($ch);

    // --- Parse AI response ---

    $replyData = json_decode($reply, true);
    if (($type === 'chatgpt' || $type === 'openrouter') && isset($replyData['choices'][0]['message']['content'])) {
        $recommendationsJson = $replyData['choices'][0]['message']['content'];
        $recommendations = json_decode($recommendationsJson, true);
    } elseif ($type === 'gemini' && isset($replyData['candidates'][0]['content']['parts'][0]['text'])) {
        $recommendationsJson = $replyData['candidates'][0]['content']['parts'][0]['text'];
        $recommendationsJson = preg_replace('/^```json\s*|\s*```$/m', '', $recommendationsJson);
        $recommendationsJson = trim($recommendationsJson);
        $recommendations = json_decode($recommendationsJson, true);
    } else {
        $recommendations = json_decode($replyData['response'] ?? '[]', true);
    }

    if (json_last_error() === JSON_ERROR_NONE && is_array($recommendations)) {
        // Remove old recommendations for this user
        $stmt = $db->prepare("DELETE FROM ai_recommendations WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->execute();

        // Insert each new recommendation
        $insert = $db->prepare("
            INSERT INTO ai_recommendations (user_id, type, title, description, savings)
            VALUES (:user_id, :type, :title, :description, :savings)
        ");

        foreach ($recommendations as $rec) {
            $insert->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $insert->bindValue(':type', 'subscription', SQLITE3_TEXT);
            $insert->bindValue(':title', $rec['title'] ?? '', SQLITE3_TEXT);
            $insert->bindValue(':description', $rec['description'] ?? '', SQLITE3_TEXT);
            $insert->bindValue(':savings', $rec['savings'] ?? '', SQLITE3_TEXT);
            $insert->execute();
        }

        echo "Successfully generated " . count($recommendations) . " recommendations for user $userId<br />\n";
    } else {
        echo "Error parsing AI response for user $userId: " . json_last_error_msg() . "<br />\n";
    }
}

echo "AI recommendations cronjob completed.<br />\n";

?>