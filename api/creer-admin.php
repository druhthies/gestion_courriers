<?php
// ===================================================================
// SCRIPT À USAGE UNIQUE — Création du premier compte administrateur
// ===================================================================
// Utilisation : ouvrez ce fichier dans le navigateur UNE SEULE FOIS
// après avoir configuré config.php, puis SUPPRIMEZ-LE (ou renommez-le)
// une fois le compte admin créé, pour des raisons de sécurité.
//
// Exemple : https://votre-domaine/api/creer-admin.php
//           ?nom=Amadou+Diallo&username=admin&password=MotDePasseSolide123

require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');

$nom = trim($_GET['nom'] ?? '');
$username = trim($_GET['username'] ?? '');
$password = $_GET['password'] ?? '';

if (!$nom || !$username || !$password) {
    echo "Utilisation :\n";
    echo "creer-admin.php?nom=Votre+Nom&username=admin&password=VotreMotDePasse\n";
    exit;
}

if (strlen($password) < 6) {
    echo "Erreur : le mot de passe doit contenir au moins 6 caractères.\n";
    exit;
}

$payload = [
    'id' => 'user_' . time() . '_' . random_int(1000, 9999),
    'nom_complet' => $nom,
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'role' => 'ADMINISTRATEUR',
];

global $SUPABASE_HEADERS;
$ch = curl_init(SUPABASE_API_URL . '/utilisateurs');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $SUPABASE_HEADERS);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 201) {
    echo "Compte administrateur créé avec succès pour '$username'.\n";
    echo "⚠️  Supprimez ou renommez maintenant ce fichier (creer-admin.php).\n";
} else {
    echo "Erreur (code $httpCode) : $response\n";
    echo "Vérifiez que le username n'est pas déjà pris et que config.php est correctement rempli.\n";
}
