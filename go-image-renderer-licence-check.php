<?php
// Définition du header JSON
header('Content-Type: application/json; charset=utf-8');

// Récupération des paramètres de la requête
$licenseKey = isset($_POST['license_key']) ? trim($_POST['license_key']) : '';
$domain     = isset($_POST['domain']) ? trim($_POST['domain']) : '';

if (empty($licenseKey) || empty($domain)) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing license or domain.'
    ]);
    exit;
}

// Connexion à la base de données
require_once 'common-db.php';

// Update "last_checked" date
$updateLastChecked = $pdo->prepare("UPDATE image_renderer SET last_checked = NOW() WHERE license_key = ?");
$updateLastChecked->execute([$licenseKey]);

// Vérification de la clé de licence
$stmt = $pdo->prepare("SELECT * FROM image_renderer WHERE license_key = ?");
$stmt->execute([$licenseKey]);
$license = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$license) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid license key.',
    ]);
    exit;
}

// Vérification du domaine
if ((int)$license['active'] === 1) {
    if (!empty($license['domain']) && $license['domain'] === $domain) {
        echo json_encode([
            'success' => true,
            'message' => 'Key already active for this domain.'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Key already used on another domain.',
        ]);
    }
    exit;
}

// Activation de la licence
$update = $pdo->prepare("UPDATE image_renderer SET active = 1, domain = ?, last_checked = NOW() WHERE license_key = ?");
$update->execute([$domain, $licenseKey]);

echo json_encode([
    'success' => true,
    'message' => 'License activated successfully.',
    'domain' => $domain,
]);
