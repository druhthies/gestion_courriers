<?php
require_once __DIR__ . '/../config.php';

$user = exigerConnexion();

$chemin = $_GET['chemin'] ?? '';
if (!$chemin) {
    http_response_code(400);
    echo 'Chemin requis.';
    exit;
}

// Sécurise le chemin (empêche de sortir du dossier SCANS_BASE_PATH)
$cheminSur = str_replace(['..', "\0"], '', $chemin);
$cheminSur = str_replace(['\\', '//'], '/', $cheminSur);
$cheminSur = trim($cheminSur, '/');

$moduleAliases = [
    'entrant' => 'Courriers Entrants',
    'sortant' => 'Courriers Sortants',
];

$candidates = [];
if ($cheminSur !== '') {
    $candidates[] = $cheminSur;

    $segments = explode('/', $cheminSur);
    if (count($segments) >= 2) {
        $first = $segments[0];
        if (isset($moduleAliases[$first])) {
            $segments[0] = $moduleAliases[$first];
            $candidates[] = implode('/', $segments);
        } elseif (in_array($first, $moduleAliases, true)) {
            $legacyKey = array_search($first, $moduleAliases, true);
            if ($legacyKey !== false) {
                $segments[0] = $legacyKey;
                $candidates[] = implode('/', $segments);
            }
        }
    }
}

$realBase = realpath(SCANS_BASE_PATH);
$realFile = null;
foreach ($candidates as $candidate) {
    $fullPath = rtrim(SCANS_BASE_PATH, '/') . '/' . ltrim($candidate, '/');
    $candidateRealPath = realpath($fullPath);
    if ($candidateRealPath && is_file($candidateRealPath) && $realBase && strpos($candidateRealPath, $realBase) === 0) {
        $realFile = $candidateRealPath;
        break;
    }
}

if (!$realFile || !$realBase || strpos($realFile, $realBase) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    echo 'Fichier introuvable.';
    exit;
}

$mime = mime_content_type($realFile) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($realFile) . '"');
header('Content-Length: ' . filesize($realFile));
readfile($realFile);
