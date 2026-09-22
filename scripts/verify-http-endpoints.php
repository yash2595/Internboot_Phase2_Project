<?php
// Path: scripts/verify-http-endpoints.php
// Tests the live HTTP API running on localhost:8000

$baseUrl = 'http://localhost:8000';

function http_req($url, $method = 'GET', $data = null, $cookies = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $headers = [];
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($data) ? $data : json_encode($data));
    }
    if ($cookies) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    // Extract cookies
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headerStr, $matches);
    $cookieList = [];
    foreach ($matches[1] as $c) {
        $cookieList[] = $c;
    }

    return [
        'status' => $statusCode,
        'headers' => $headerStr,
        'body' => $body,
        'json' => json_decode($body, true),
        'cookies' => implode('; ', $cookieList)
    ];
}

echo "=== TESTING HTTP API ON {$baseUrl} ===\n\n";

// 1. Student Login
echo "1. Testing Student Login...\n";
$loginRes = http_req("{$baseUrl}/api/auth/login.php", 'POST', [
    'email' => 'student001@example.com',
    'password' => 'StudentPass123!'
]);
echo "Status: {$loginRes['status']}\n";
$studentCookie = $loginRes['cookies'];
echo "Cookies: " . substr($studentCookie, 0, 50) . "...\n";

// 2. Fetch Student Preferences
echo "\n2. Testing Student Preference Endpoint (GET /api/slots/preference.php)...\n";
$prefRes = http_req("{$baseUrl}/api/slots/preference.php", 'GET', null, $studentCookie);
echo "Status: {$prefRes['status']}\n";
if ($prefRes['json']) {
    echo "Success: " . ($prefRes['json']['status'] ?? 'unknown') . "\n";
    echo "Available weekend options: " . count($prefRes['json']['data']['available_options'] ?? []) . "\n";
    if (!empty($prefRes['json']['data']['available_options'])) {
        $firstOpt = $prefRes['json']['data']['available_options'][0];
        echo "First option: Date={$firstOpt['date']}, Time={$firstOpt['time_slot']}, Status={$firstOpt['status_label']}\n";
    }
} else {
    echo "Body: {$prefRes['body']}\n";
}

// 2b. Fetch Student Dashboard
echo "\n2b. Testing Student Dashboard (GET /api/dashboard.php)...\n";
$dashRes = http_req("{$baseUrl}/api/dashboard.php", 'GET', null, $studentCookie);
echo "Status: {$dashRes['status']}\n";
if ($dashRes['json'] && isset($dashRes['json']['data'])) {
    $d = $dashRes['json']['data'];
    echo "Batch: " . json_encode($d['batch'] ?? null) . "\n";
    echo "Enrollment: " . json_encode($d['enrollment'] ?? null) . "\n";
    echo "Exam: " . json_encode($d['exam'] ?? null) . "\n";
} else {
    echo "Body: {$dashRes['body']}\n";
}

// 2c. Fetch Available Slots
echo "\n2c. Testing Available Slots (GET /api/slots/available.php)...\n";
$slotsRes = http_req("{$baseUrl}/api/slots/available.php?assessment_id=1", 'GET', null, $studentCookie);
echo "Status: {$slotsRes['status']}\n";
if ($slotsRes['json'] && isset($slotsRes['json']['data'])) {
    $slotsList = $slotsRes['json']['data']['slots'] ?? [];
    echo "Slots returned: " . count($slotsList) . "\n";
    foreach ($slotsList as $sl) {
        echo "  - Slot ID {$sl['id']}: {$sl['slot_date']} {$sl['start_time']}-{$sl['end_time']}, Seats: {$sl['seats_remaining']}/{$sl['capacity']}\n";
    }
} else {
    echo "Body: {$slotsRes['body']}\n";
}

// 3. Admin Login
echo "\n3. Testing Admin Login...\n";
$adminLogin = http_req("{$baseUrl}/api/auth/login.php", 'POST', [
    'email' => 'admin@internboot.com',
    'password' => 'AdminPass123!'
]);
echo "Status: {$adminLogin['status']}\n";
$adminCookie = $adminLogin['cookies'];

// 4. Fetch Admin Batches
echo "\n4. Testing Admin Batches Endpoint (GET /api/admin/evaluate.php?action=batches)...\n";
$batchesRes = http_req("{$baseUrl}/api/admin/evaluate.php?action=batches", 'GET', null, $adminCookie);
echo "Status: {$batchesRes['status']}\n";
if ($batchesRes['json'] && isset($batchesRes['json']['data']['batches'])) {
    $batches = $batchesRes['json']['data']['batches'];
    echo "Total Batches: " . count($batches) . "\n";
    foreach (array_slice($batches, 0, 3) as $b) {
        $slotsCount = count($b['slots'] ?? []);
        echo "  - Batch #{$b['batch_number']}: Slots Count = {$slotsCount}, Capacity = {$b['capacity']}\n";
    }
} else {
    echo "Body: " . substr($batchesRes['body'], 0, 200) . "\n";
}

echo "\n=== HTTP VERIFICATION COMPLETED ===\n";
