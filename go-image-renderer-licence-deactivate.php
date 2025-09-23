<?php
header('Content-Type: application/json; charset=utf-8');

$licenseKey = isset($_POST['license_key']) ? trim($_POST['license_key']) : '';
$domain     = isset($_POST['domain'])      ? trim($_POST['domain'])      : '';

if (empty($licenseKey) || empty($domain)) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing license or domain.'
    ]);
    exit;
}

// Connect to DB
$db_name = $_ENV['licences_db'] ?? null;
$db_user = $_ENV['licences_db_user'] ?? null;
$db_pass = $_ENV['licences_db_pw'] ?? null;
$db_host = $_ENV['licences_db_host'] ?? null;

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection error.'
    ]);
    exit;
}

// Check key
$stmt = $pdo->prepare("SELECT * FROM wp_media_renderer WHERE license_key = ?");
$stmt->execute([$licenseKey]);
$license = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$license) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid license key.'
    ]);
    exit;
}

// Check if domain is the right one before deactivation
if ($license['domain'] !== $domain) {
    echo json_encode([
        'success' => false,
        'message' => 'Domain mismatch. Deactivation refused.'
    ]);
    exit;
}

// Set "active" to 0
$update = $pdo->prepare("UPDATE wp_media_renderer SET active = 0 WHERE license_key = ?");
$update->execute([$licenseKey]);

echo json_encode([
    'success' => true,
    'message' => 'License deactivated successfully. Domain retained.'
]);
