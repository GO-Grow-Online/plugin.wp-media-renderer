<?php
$db_name = $_ENV['licences_db'] ?? null;
$db_user = $_ENV['licences_db_user'] ?? null;
$db_pass = $_ENV['licences_db_pw'] ?? null;
$db_host = $_ENV['licences_db_host'] ?? null;

$SECRET_KEY = $_ENV['licences_generator_pw'] ?? '';

// Validate password
if (!isset($_POST['secret_key']) || $_POST['secret_key'] !== $SECRET_KEY) {
    http_response_code(403);
    die("🚫 Accès interdit.");
}

// Connect to DB
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Function to generate a unique key
function generateUniqueLicenseKey($pdo, $table) {
    do {
        $key = 'GO-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE license_key = ?");
        $stmt->execute([$key]);
        $exists = $stmt->fetchColumn();
    } while ($exists > 0);

    return $key;
}

// Select the right table
$table = isset($_POST['table']) && in_array($_POST['table'], ['wp_media_renderer', 'image_renderer', 'site800']) ? $_POST['table'] : 'wp_media_renderer';
$domain = $_POST['domain'] ?? '';

if (empty($domain)) {
    die("Le domaine est requis.");
}

// Check if domain allready have a entry
$stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE domain = ?");
$stmt->execute([$domain]);
$domain_exists = $stmt->fetchColumn();

if ($domain_exists > 0) {
    die("⚠️ Domain allready registered.");
}

// Generate and insert new key
$license_key = generateUniqueLicenseKey($pdo, $table);
$created_at = date('Y-m-d H:i:s');

$stmt = $pdo->prepare("INSERT INTO $table (license_key, domain, active, created_at) VALUES (?, ?, 0, ?)");
$success = $stmt->execute([$license_key, $domain, $created_at]);

if ($success) {
    echo "✅ Clé générée avec succès : $license_key";
} else {
    echo "❌ Erreur lors de l'insertion.";
}

?>
