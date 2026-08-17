<?php
// ===================================================================
// CONFIGURATION SUPABASE
// ===================================================================
// À remplir avec les infos de votre projet Supabase :
// Project Settings > API dans le dashboard Supabase

if (!defined('SUPABASE_PROJECT_REF')) {
    define('SUPABASE_PROJECT_REF', getenv('SUPABASE_PROJECT_REF') ?: 'nemkrtdwveobrpagtryb');
}
if (!defined('SUPABASE_SERVICE_ROLE_KEY')) {
    define('SUPABASE_SERVICE_ROLE_KEY', getenv('SUPABASE_SERVICE_ROLE_KEY') ?: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Im5lbWtydGR3dmVvYnJwYWd0cnliIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4NTE0MDgxOSwiZXhwIjoyMTAwNzE2ODE5fQ.tLnQkhV63Q1-Z5RljZDaIT-bVZQQasC3bChbvhV4MQ4');
}

define('SUPABASE_URL', 'https://' . SUPABASE_PROJECT_REF . '.supabase.co');
define('SUPABASE_API_URL', SUPABASE_URL . '/rest/v1');

// En-têtes utilisés pour toutes les requêtes vers Supabase.
// Le backend utilise TOUJOURS la clé service_role : elle ne doit jamais
// apparaître côté navigateur, uniquement ici côté serveur.
$SUPABASE_HEADERS = [
    'apikey: ' . SUPABASE_SERVICE_ROLE_KEY,
    'Authorization: Bearer ' . SUPABASE_SERVICE_ROLE_KEY,
    'Content-Type: application/json',
    'Prefer: return=representation'
];

define('DEBUG', true);

// ===================================================================
// DÉTECTION ENVIRONNEMENT & CHEMIN NAS
// ===================================================================
// Si ce code s'exécute directement sur le NAS (Synology Web Station),
// on stocke les scans dans un volume partagé. On tente plusieurs chemins
// fréquents pour couvrir les installations Synology.
$candidateNasPaths = [
    '/volume1/courriers/Plateforme',
    '/volume1/courriers/Plateforme/Courriers Entrants',
    '/volume1/courriers/Plateforme/Courriers Sortants',
    '/volume1/COURRIERS/Plateforme',
    '/volume1/COURRIERS/Plateforme/Courriers Entrants',
    '/volume1/COURRIERS/Plateforme/Courriers Sortants',
    '/mnt/volume1/courriers/Plateforme',
    '/mnt/volume1/courriers/Plateforme/Courriers Entrants',
    '/mnt/volume1/courriers/Plateforme/Courriers Sortants',
    '/mnt/volume1/COURRIERS/Plateforme',
    '/mnt/volume1/COURRIERS/Plateforme/Courriers Entrants',
    '/mnt/volume1/COURRIERS/Plateforme/Courriers Sortants',
];

$nasPath = null;
foreach ($candidateNasPaths as $path) {
    if (!empty($path) && is_dir($path)) {
        $nasPath = rtrim($path, '/\\');
        if (in_array(basename($nasPath), ['Courriers Entrants', 'Courriers Sortants'], true)) {
            $nasPath = dirname($nasPath);
        }
        break;
    }
}

$isOnNAS = $nasPath !== null;

if (!defined('SCANS_BASE_PATH')) {
    if ($isOnNAS) {
        define('SCANS_BASE_PATH', $nasPath);
    } else {
        // Hors NAS (test local, autre serveur) : variable d'environnement
        define('SCANS_BASE_PATH', getenv('SCANS_BASE_PATH') ?: __DIR__ . '/scans_local');
    }
}
define('IS_ON_NAS', $isOnNAS);

// ===================================================================
// FONCTIONS UTILITAIRES
// ===================================================================
function logDebug($message, $data = null) {
    if (DEBUG) {
        $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
        if ($data) {
            $log .= " - " . json_encode($data);
        }
        error_log($log);
    }
}

function sendJSON($status, $message, $data = null) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// ===================================================================
// SESSION (authentification)
// ===================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function utilisateurConnecte() {
    return $_SESSION['user'] ?? null;
}

function exigerConnexion() {
    $user = utilisateurConnecte();
    if (!$user) {
        sendJSON(401, 'Non authentifié. Veuillez vous reconnecter.');
    }
    return $user;
}

function exigerAdmin() {
    $user = exigerConnexion();
    if ($user['role'] !== 'ADMINISTRATEUR') {
        sendJSON(403, 'Accès réservé à l\'administrateur.');
    }
    return $user;
}

function exigerAdminOuSuperAgent() {
    $user = exigerConnexion();
    if (!in_array($user['role'], ['ADMINISTRATEUR', 'SUPER_AGENT'], true)) {
        sendJSON(403, 'Accès réservé à l\'administrateur et au super agent.');
    }
    return $user;
}
