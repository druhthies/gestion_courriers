<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$user = exigerConnexion();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJSON(405, 'Méthode non autorisée');
}

if (!isset($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    sendJSON(400, 'Aucun fichier valide envoyé.');
}

$module = $_POST['courrier_module'] ?? '';
$numero = $_POST['numero'] ?? '';

if (!in_array($module, ['entrant', 'sortant', 'imputation'], true)) {
    sendJSON(400, 'Champ courrier_module invalide.');
}
if ($module !== 'imputation' && !$numero) {
    sendJSON(400, 'Champ numero requis.');
}

$anneeSure = date('Y');
if (preg_match('/-(\d{4})-/', $numero, $m)) {
    $anneeSure = $m[1];
}
// Pas de numéro de courrier pour une imputation : on se base sur un
// identifiant temporaire unique pour nommer le fichier.
$numeroSur = $numero ? preg_replace('/[^a-zA-Z0-9\-_]/', '_', $numero) : 'imputation';

$moduleFolders = [
    'entrant' => 'Courriers Entrants',
    'sortant' => 'Courriers Sortants',
    'imputation' => 'Imputations',
];
$moduleFolder = $moduleFolders[$module] ?? ucfirst($module);
$moduleFolder = str_replace(['\\', '/'], '/', $moduleFolder);

$basePath = rtrim(SCANS_BASE_PATH, '/');
$targetDir = $basePath . '/' . $moduleFolder . '/' . $anneeSure;

if (!is_dir($basePath)) {
    sendJSON(503, 'Stockage NAS non accessible. Vérifiez que le partage est monté / que SCANS_BASE_PATH est correct.', [
        'chemin' => $basePath,
        'isOnNAS' => IS_ON_NAS,
    ]);
}

if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    sendJSON(500, 'Impossible de créer le dossier de destination sur le NAS.');
}

$extension = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
$extensionsAutorisees = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
if (!in_array($extension, $extensionsAutorisees, true)) {
    sendJSON(400, 'Format non autorisé. Formats acceptés : PDF, JPG, PNG, GIF, WEBP.');
}
$nomFichier = $numeroSur . '_' . uniqid() . '.' . $extension;
$targetFile = $targetDir . '/' . $nomFichier;

if (!move_uploaded_file($_FILES['fichier']['tmp_name'], $targetFile)) {
    sendJSON(500, 'Échec de l\'enregistrement du fichier sur le NAS.');
}

$cheminRelatif = $moduleFolder . '/' . $anneeSure . '/' . $nomFichier;
$cheminRelatif = str_replace(['\\', '//'], '/', $cheminRelatif);

sendJSON(200, 'Fichier enregistré.', [
    'chemin' => $cheminRelatif,
    'nom_original' => $_FILES['fichier']['name'],
    'type_mime' => $_FILES['fichier']['type'] ?? null,
]);
