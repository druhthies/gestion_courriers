<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

set_exception_handler(function ($e) {
    logDebug('Exception non capturée', ['message' => $e->getMessage()]);
    sendJSON(500, 'Erreur serveur : ' . $e->getMessage());
});

// ===================================================================
// APPEL SUPABASE (REST) - fonction générique
// ===================================================================
function supabaseRequest($path, $method = 'GET', $body = null, $extraHeaders = [], $attempt = 1) {
    global $SUPABASE_HEADERS;

    $url = SUPABASE_API_URL . $path;
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
    curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($SUPABASE_HEADERS, $extraHeaders));

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $shouldRetry = false;
    if ($error) {
        $shouldRetry = stripos($error, 'connection reset by peer') !== false
            || stripos($error, 'operation timed out') !== false
            || stripos($error, 'could not resolve host') !== false
            || stripos($error, 'failed to connect') !== false;
    } elseif (in_array($httpCode, [502, 503, 504], true)) {
        $shouldRetry = true;
    }

    if ($shouldRetry && $attempt < 3) {
        logDebug('Relance supabaseRequest', ['attempt' => $attempt + 1, 'url' => $url, 'httpCode' => $httpCode, 'error' => $error]);
        return supabaseRequest($path, $method, $body, $extraHeaders, $attempt + 1);
    }

    if ($error) {
        logDebug('Erreur curl vers Supabase', ['error' => $error, 'url' => $url]);
        return ['ok' => false, 'code' => 503, 'data' => null, 'error' => $error];
    }

    $decoded = json_decode($response, true);
    return ['ok' => $httpCode >= 200 && $httpCode < 300, 'code' => $httpCode, 'data' => $decoded];
}

function getSupabaseErrorMessage($res, $defaultMessage) {
    $detail = '';
    if (is_array($res['data']) && isset($res['data']['message']) && trim($res['data']['message']) !== 'OK') {
        $detail = $res['data']['message'];
    } elseif (is_array($res['data']) && !empty($res['data']['error'])) {
        $detail = $res['data']['error'];
    } elseif (!empty($res['error'])) {
        $detail = $res['error'];
    }
    if (!$detail && is_array($res['data']) && !empty($res['data'])) {
        $detail = json_encode($res['data']);
    }
    return $detail ?: $defaultMessage;
}

function logAudit($userId, $action, $module, $entity, $entityId = null, $details = null) {
    $payload = [
        'user_id' => $userId,
        'action' => $action,
        'courrier_module' => $module,
        'entity' => $entity,
        'entity_id' => $entityId,
        'details' => $details !== null ? (is_string($details) ? $details : json_encode($details)) : null,
    ];
    $res = supabaseRequest('/audit_logs', 'POST', $payload);
    if (!$res['ok']) {
        logDebug('Échec audit log', ['payload' => $payload, 'res' => $res]);
    }
}

function champsAutorisesDepuis($input, $champs) {
    return array_intersect_key($input, array_flip($champs));
}

// ===================================================================
// NOTIFICATIONS — créée à chaque nouveau courrier, nouvelle imputation,
// nouvelle consigne ou nouveau message (réponse à une imputation).
// ===================================================================
function creerNotification($userId, $type, $message, $lienModule = null, $lienId = null) {
    if (empty($userId)) return;
    $res = supabaseRequest('/notifications', 'POST', [
        'user_id' => $userId,
        'type' => $type,
        'message' => $message,
        'lien_module' => $lienModule,
        'lien_id' => $lienId,
    ]);
    if (!$res['ok']) {
        logDebug('Échec création notification', ['user_id' => $userId, 'type' => $type]);
    }
}

// Notifie tous les ADMINISTRATEUR / SUPER_AGENT (hors l'auteur) d'un nouveau courrier
function notifierEquipeGestion($auteurId, $type, $message, $lienModule, $lienId) {
    $res = supabaseRequest('/utilisateurs?select=id&role=in.(ADMINISTRATEUR,SUPER_AGENT)&actif=eq.true');
    if (!$res['ok']) return;
    foreach ($res['data'] as $u) {
        if ($u['id'] === $auteurId) continue;
        creerNotification($u['id'], $type, $message, $lienModule, $lienId);
    }
}

// Vérifie qu'un agent a bien reçu une imputation référençant ce courrier
function agentPeutAccederCourrier($user, $module, $courrierId) {
    if (in_array($user['role'], ['ADMINISTRATEUR', 'SUPER_AGENT'], true)) return true;
    $res = supabaseRequest(
        '/imputations?select=id&destinataire_id=eq.' . urlencode($user['id']) .
        '&courrier_module=eq.' . urlencode($module) .
        '&courrier_id=eq.' . urlencode($courrierId) .
        '&limit=1'
    );
    return $res['ok'] && !empty($res['data']);
}

// ===================================================================
// ROUTAGE
// ===================================================================
$route = $_GET['route'] ?? '';
$method = $_GET['method'] ?? $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($route) {

    // ---------------------------------------------------------------
    // AUTHENTIFICATION
    // ---------------------------------------------------------------
    case 'login':
        if ($method !== 'POST') sendJSON(405, 'Méthode non autorisée');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        if (!$username || !$password) sendJSON(400, 'Identifiant et mot de passe requis.');

        $res = supabaseRequest('/utilisateurs?username=eq.' . urlencode($username) . '&select=*');
        if (!$res['ok'] || empty($res['data'])) sendJSON(401, 'Identifiants incorrects.');

        $user = $res['data'][0];
        if (!$user['actif']) sendJSON(403, 'Compte désactivé. Contactez un administrateur.');
        if (!password_verify($password, $user['password_hash'])) sendJSON(401, 'Identifiants incorrects.');

        $_SESSION['user'] = [
            'id' => $user['id'],
            'nom_complet' => $user['nom_complet'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];
        sendJSON(200, 'Connexion réussie.', $_SESSION['user']);
        break;

    case 'logout':
        $_SESSION = [];
        session_destroy();
        sendJSON(200, 'Déconnecté.');
        break;

    case 'me':
        $user = utilisateurConnecte();
        if (!$user) sendJSON(401, 'Non connecté.');
        sendJSON(200, 'OK', $user);
        break;

    // ---------------------------------------------------------------
    // TYPES DE DOCUMENT
    // ---------------------------------------------------------------
    case 'types_document':
        exigerConnexion();
        if ($method === 'GET') {
            $res = supabaseRequest('/types_document?select=*&order=nom.asc');
            sendJSON($res['code'], $res['ok'] ? 'OK' : getSupabaseErrorMessage($res, 'Impossible de charger les types de document.'), $res['data']);
        }
        if ($method === 'POST') {
            exigerAdminOuSuperAgent();
            if (empty($input['nom'])) sendJSON(400, 'Nom requis.');
            $res = supabaseRequest('/types_document', 'POST', ['nom' => trim($input['nom'])]);
            sendJSON($res['code'], $res['ok'] ? 'Type ajouté.' : 'Erreur (déjà existant ?).', $res['data']);
        }
        if ($method === 'DELETE') {
            exigerAdmin();
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');
            $res = supabaseRequest('/types_document?id=eq.' . urlencode($id), 'DELETE');
            $message = $res['ok'] ? 'Supprimé.' : ('Impossible de supprimer. ' . getSupabaseErrorMessage($res, 'Vérifiez qu aucun courrier ne l utilise.'));
            sendJSON($res['code'], $message, null);
        }
        sendJSON(405, 'Méthode non autorisée');
        break;

    // ---------------------------------------------------------------
    // UTILISATEURS (administrateur uniquement)
    // ---------------------------------------------------------------
    case 'utilisateurs':
        if ($method === 'GET') {
            $user = exigerConnexion();
            // Les émetteurs (admin/super agent) doivent pouvoir choisir un destinataire d'imputation
            if ($user['role'] === 'AGENT') {
                $res = supabaseRequest('/utilisateurs?select=id,nom_complet,username,role,actif&order=nom_complet.asc');
            } else {
                exigerAdminOuSuperAgent();
                $res = supabaseRequest('/utilisateurs?select=id,nom_complet,username,role,actif,created_at&order=nom_complet.asc');
            }
            sendJSON($res['code'], $res['ok'] ? 'OK' : getSupabaseErrorMessage($res, 'Impossible de charger les utilisateurs.'), $res['data']);
        }
        if ($method === 'POST') {
            exigerAdmin();
            $nomComplet = trim($input['nom_complet'] ?? '');
            $username = trim($input['username'] ?? '');
            $password = $input['password'] ?? '';
            $roleDemande = strtoupper($input['role'] ?? 'AGENT');
            if (!$nomComplet || !$username || !$password) sendJSON(400, 'Nom complet, identifiant et mot de passe requis.');

            $payload = [
                'id' => 'user_' . time() . '_' . random_int(1000, 9999),
                'nom_complet' => $nomComplet,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => in_array($roleDemande, ['ADMINISTRATEUR', 'SUPER_AGENT', 'AGENT'], true) ? $roleDemande : 'AGENT',
            ];
            $res = supabaseRequest('/utilisateurs', 'POST', $payload);
            sendJSON($res['code'], $res['ok'] ? 'Compte créé.' : 'Erreur (identifiant déjà pris ?).', $res['data']);
        }
        if ($method === 'PATCH') {
            exigerAdmin();
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');
            $champsAutorises = ['role', 'actif', 'nom_complet'];
            $payload = champsAutorisesDepuis($input, $champsAutorises);
            if (!empty($input['password'])) $payload['password_hash'] = password_hash($input['password'], PASSWORD_DEFAULT);
            if (empty($payload)) sendJSON(400, 'Aucun champ modifiable fourni.');
            $res = supabaseRequest('/utilisateurs?id=eq.' . urlencode($id), 'PATCH', $payload);
            sendJSON($res['code'], 'Utilisateur mis à jour.', $res['data']);
        }
        if ($method === 'DELETE') {
            exigerAdmin();
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');
            $res = supabaseRequest('/utilisateurs?id=eq.' . urlencode($id), 'DELETE');
            $message = $res['ok'] ? 'Compte supprimé.' : ('Impossible de supprimer ce compte. ' . getSupabaseErrorMessage($res, 'Vérifiez qu il n a pas d imputations liées.'));
            sendJSON($res['code'], $message, null);
        }
        sendJSON(405, 'Méthode non autorisée');
        break;

    // ---------------------------------------------------------------
    // COURRIERS ENTRANTS (ADMINISTRATEUR / SUPER_AGENT uniquement)
    // ---------------------------------------------------------------
    case 'courriers_entrants':
        $user = exigerAdminOuSuperAgent();

        if ($method === 'GET') {
            $query = '/courriers_entrants?select=*,types_document(nom)&order=created_at.desc';
            if (!empty($_GET['statut'])) $query .= '&statut=eq.' . urlencode($_GET['statut']);
            if (!empty($_GET['priorite'])) $query .= '&priorite=eq.' . urlencode($_GET['priorite']);
            if (!empty($_GET['q'])) {
                $q = urlencode($_GET['q']);
                $query .= '&or=(objet.ilike.*' . $q . '*,expediteur.ilike.*' . $q . '*,numero.ilike.*' . $q . '*)';
            }
            $res = supabaseRequest($query);
            sendJSON($res['code'], $res['ok'] ? 'OK' : getSupabaseErrorMessage($res, 'Impossible de charger les courriers entrants.'), $res['data']);
        }

        if ($method === 'POST') {
            if (empty($input['expediteur']) || empty($input['objet'])) sendJSON(400, 'Expéditeur et objet requis.');
            $payload = [
                'date_reception' => $input['date_reception'] ?? date('Y-m-d'),
                'expediteur' => trim($input['expediteur']),
                'objet' => trim($input['objet']),
                'type_document_id' => $input['type_document_id'] ?? null,
                'priorite' => in_array($input['priorite'] ?? 'normal', ['normal', 'urgent', 'tres_urgent'], true) ? $input['priorite'] : 'normal',
                'accuse_reception' => !empty($input['accuse_reception']),
                'statut' => 'en_attente',
                'created_by' => $user['id'],
            ];
            $res = supabaseRequest('/courriers_entrants', 'POST', $payload);
            if ($res['ok']) {
                $nouveau = $res['data'][0];
                logAudit($user['id'], 'creation', 'entrant', 'courrier_entrant', $nouveau['id'], ['numero' => $nouveau['numero']]);
                notifierEquipeGestion($user['id'], 'courrier_entrant', 'Nouveau courrier entrant reçu : ' . $nouveau['numero'], 'entrant', $nouveau['id']);
            }
            sendJSON($res['code'], $res['ok'] ? 'Courrier entrant enregistré.' : getSupabaseErrorMessage($res, 'Erreur lors de l enregistrement.'), $res['data']);
        }

        if ($method === 'PATCH') {
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');
            $champsAutorises = ['expediteur', 'objet', 'date_reception', 'type_document_id', 'priorite', 'accuse_reception', 'statut'];
            $payload = champsAutorisesDepuis($input, $champsAutorises);
            if (empty($payload)) sendJSON(400, 'Aucun champ modifiable fourni.');
            $payload['updated_at'] = date('c');
            $res = supabaseRequest('/courriers_entrants?id=eq.' . urlencode($id), 'PATCH', $payload);
            if ($res['ok']) logAudit($user['id'], 'modification', 'entrant', 'courrier_entrant', (int)$id, $payload);
            sendJSON($res['code'], $res['ok'] ? 'Courrier mis à jour.' : getSupabaseErrorMessage($res, 'Erreur lors de la mise à jour.'), $res['data']);
        }

        if ($method === 'DELETE') {
            exigerAdmin();
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');
            $res = supabaseRequest('/courriers_entrants?id=eq.' . urlencode($id), 'DELETE');
            if ($res['ok']) logAudit($user['id'], 'suppression', 'entrant', 'courrier_entrant', (int)$id, null);
            sendJSON($res['code'], $res['ok'] ? 'Courrier supprimé.' : 'Erreur lors de la suppression.', null);
        }
        sendJSON(405, 'Méthode non autorisée');
        break;

    // ---------------------------------------------------------------
    // COURRIERS SORTANTS (ADMINISTRATEUR / SUPER_AGENT uniquement)
    // ---------------------------------------------------------------
    case 'courriers_sortants':
        $user = exigerAdminOuSuperAgent();

        if ($method === 'GET') {
            $query = '/courriers_sortants?select=*&order=created_at.desc';
            if (!empty($_GET['mode_envoi'])) $query .= '&mode_envoi=eq.' . urlencode($_GET['mode_envoi']);
            if (!empty($_GET['q'])) {
                $q = urlencode($_GET['q']);
                $query .= '&or=(objet.ilike.*' . $q . '*,destinataire.ilike.*' . $q . '*,numero.ilike.*' . $q . '*)';
            }
            $res = supabaseRequest($query);
            sendJSON($res['code'], $res['ok'] ? 'OK' : getSupabaseErrorMessage($res, 'Impossible de charger les courriers sortants.'), $res['data']);
        }

        if ($method === 'POST') {
            if (empty($input['destinataire']) || empty($input['objet'])) sendJSON(400, 'Destinataire et objet requis.');
            $payload = [
                'destinataire' => trim($input['destinataire']),
                'objet' => trim($input['objet']),
                'date_envoi' => $input['date_envoi'] ?? date('Y-m-d'),
                'mode_envoi' => in_array($input['mode_envoi'] ?? 'email', ['email', 'poste', 'main_propre'], true) ? $input['mode_envoi'] : 'email',
                'signature_responsable' => $input['signature_responsable'] ?? null,
                'created_by' => $user['id'],
            ];
            $res = supabaseRequest('/courriers_sortants', 'POST', $payload);
            if ($res['ok']) {
                $nouveau = $res['data'][0];
                logAudit($user['id'], 'creation', 'sortant', 'courrier_sortant', $nouveau['id'], ['numero' => $nouveau['numero']]);
                notifierEquipeGestion($user['id'], 'courrier_sortant', 'Nouveau courrier sortant enregistré : ' . $nouveau['numero'], 'sortant', $nouveau['id']);
            }
            sendJSON($res['code'], $res['ok'] ? 'Courrier sortant enregistré.' : getSupabaseErrorMessage($res, 'Erreur lors de l enregistrement.'), $res['data']);
        }

        if ($method === 'PATCH') {
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');
            $champsAutorises = ['destinataire', 'objet', 'date_envoi', 'mode_envoi', 'signature_responsable'];
            $payload = champsAutorisesDepuis($input, $champsAutorises);
            if (empty($payload)) sendJSON(400, 'Aucun champ modifiable fourni.');
            $payload['updated_at'] = date('c');
            $res = supabaseRequest('/courriers_sortants?id=eq.' . urlencode($id), 'PATCH', $payload);
            if ($res['ok']) logAudit($user['id'], 'modification', 'sortant', 'courrier_sortant', (int)$id, $payload);
            sendJSON($res['code'], $res['ok'] ? 'Courrier mis à jour.' : getSupabaseErrorMessage($res, 'Erreur lors de la mise à jour.'), $res['data']);
        }

        if ($method === 'DELETE') {
            exigerAdmin();
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');
            $res = supabaseRequest('/courriers_sortants?id=eq.' . urlencode($id), 'DELETE');
            if ($res['ok']) logAudit($user['id'], 'suppression', 'sortant', 'courrier_sortant', (int)$id, null);
            sendJSON($res['code'], $res['ok'] ? 'Courrier supprimé.' : 'Erreur lors de la suppression.', null);
        }
        sendJSON(405, 'Méthode non autorisée');
        break;

    // ---------------------------------------------------------------
    // IMPUTATIONS (consigne envoyée à un utilisateur, courrier joint
    // optionnel, réponse, suivi de statut)
    // ---------------------------------------------------------------
    case 'imputations':
        $user = exigerConnexion();

        if ($method === 'GET') {
            $query = '/imputations?select=*,emetteur:emetteur_id(nom_complet),destinataire:destinataire_id(nom_complet)&order=created_at.desc';
            if ($user['role'] === 'AGENT') {
                $query .= '&destinataire_id=eq.' . urlencode($user['id']);
            } elseif ($user['role'] === 'SUPER_AGENT') {
                $query .= '&emetteur_id=eq.' . urlencode($user['id']);
            }
            // ADMINISTRATEUR : voit tout, sans filtre supplémentaire
            if (!empty($_GET['statut'])) $query .= '&statut=eq.' . urlencode($_GET['statut']);
            $res = supabaseRequest($query);
            if (!$res['ok']) sendJSON($res['code'], getSupabaseErrorMessage($res, 'Impossible de charger les imputations.'));

            // Enrichit chaque imputation avec le courrier joint (entrant/sortant), s'il existe
            $imputations = $res['data'];
            $idsParModule = ['entrant' => [], 'sortant' => []];
            foreach ($imputations as $imp) {
                if (!empty($imp['courrier_module']) && !empty($imp['courrier_id'])) {
                    $idsParModule[$imp['courrier_module']][] = $imp['courrier_id'];
                }
            }
            $courriersParModuleEtId = [];
            $tables = ['entrant' => 'courriers_entrants', 'sortant' => 'courriers_sortants'];
            foreach ($tables as $module => $table) {
                $ids = array_unique($idsParModule[$module]);
                if (empty($ids)) continue;
                $idsStr = implode(',', array_map('intval', $ids));
                $r = supabaseRequest("/$table?select=id,numero,objet&id=in.($idsStr)");
                if ($r['ok']) {
                    foreach ($r['data'] as $c) $courriersParModuleEtId[$module][$c['id']] = $c;
                }
            }
            foreach ($imputations as &$imp) {
                $imp['courrier'] = (!empty($imp['courrier_module']) && !empty($imp['courrier_id']))
                    ? ($courriersParModuleEtId[$imp['courrier_module']][$imp['courrier_id']] ?? null)
                    : null;
            }
            unset($imp);
            sendJSON(200, 'OK', $imputations);
        }

        if ($method === 'POST') {
            exigerAdminOuSuperAgent();
            $destinataireId = $input['destinataire_id'] ?? '';
            $consigne = trim($input['consigne'] ?? '');
            if (!$destinataireId || !$consigne) sendJSON(400, 'Destinataire et consigne requis.');

            $courrierModule = $input['courrier_module'] ?? null;
            $courrierId = $input['courrier_id'] ?? null;
            if ($courrierModule && !in_array($courrierModule, ['entrant', 'sortant'], true)) {
                sendJSON(400, 'Module de courrier invalide.');
            }

            $payload = [
                'emetteur_id' => $user['id'],
                'destinataire_id' => $destinataireId,
                'consigne' => $consigne,
                'courrier_module' => $courrierModule ?: null,
                'courrier_id' => $courrierModule ? $courrierId : null,
                'fichier_chemin' => $input['fichier_chemin'] ?? null,
                'fichier_nom_original' => $input['fichier_nom_original'] ?? null,
                'fichier_type_mime' => $input['fichier_type_mime'] ?? null,
                'statut' => 'en_attente',
            ];
            $res = supabaseRequest('/imputations', 'POST', $payload);
            if ($res['ok']) {
                $nouvelle = $res['data'][0];
                logAudit($user['id'], 'creation', null, 'imputation', $nouvelle['id'], ['destinataire_id' => $destinataireId]);
                creerNotification($destinataireId, 'imputation', 'Nouvelle imputation reçue de ' . $user['nom_complet'], 'imputation', $nouvelle['id']);
            }
            sendJSON($res['code'], $res['ok'] ? 'Imputation envoyée.' : getSupabaseErrorMessage($res, 'Erreur lors de l envoi.'), $res['data']);
        }

        if ($method === 'PATCH') {
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');

            $existant = supabaseRequest('/imputations?id=eq.' . urlencode($id) . '&select=*');
            if (!$existant['ok'] || empty($existant['data'])) sendJSON(404, 'Imputation introuvable.');
            $imputation = $existant['data'][0];

            $estDestinataire = $imputation['destinataire_id'] === $user['id'];
            $estEmetteurOuAdmin = $imputation['emetteur_id'] === $user['id'] || $user['role'] === 'ADMINISTRATEUR';

            if (!$estDestinataire && !$estEmetteurOuAdmin) {
                sendJSON(403, 'Vous n\'êtes pas concerné par cette imputation.');
            }

            $payload = [];
            if ($estDestinataire) {
                // Le destinataire répond / met à jour le statut de traitement
                if (isset($input['statut']) && in_array($input['statut'], ['en_cours', 'traite'], true)) {
                    $payload['statut'] = $input['statut'];
                    if ($input['statut'] === 'en_cours' && empty($imputation['pris_en_charge_at'])) {
                        $payload['pris_en_charge_at'] = date('c');
                    }
                }
                if (isset($input['reponse'])) {
                    $payload['reponse'] = trim($input['reponse']);
                    $payload['statut'] = 'traite';
                    $payload['reponse_at'] = date('c');
                }
            }
            if ($estEmetteurOuAdmin) {
                // L'émetteur (ou l'admin) peut corriger la consigne / le courrier joint / réaffecter
                $champsEmetteur = champsAutorisesDepuis($input, ['consigne', 'courrier_module', 'courrier_id', 'destinataire_id', 'fichier_chemin', 'fichier_nom_original', 'fichier_type_mime']);
                $payload = array_merge($payload, $champsEmetteur);
            }
            if (empty($payload)) sendJSON(400, 'Aucun champ modifiable fourni.');
            $payload['updated_at'] = date('c');

            $res = supabaseRequest('/imputations?id=eq.' . urlencode($id), 'PATCH', $payload);
            if ($res['ok']) {
                logAudit($user['id'], 'modification', null, 'imputation', (int)$id, $payload);
                if (isset($payload['reponse']) && !empty($imputation['emetteur_id'])) {
                    creerNotification($imputation['emetteur_id'], 'reponse', 'Nouvelle réponse à votre imputation reçue de ' . $user['nom_complet'], 'imputation', (int)$id);
                }
            }
            sendJSON($res['code'], $res['ok'] ? 'Imputation mise à jour.' : getSupabaseErrorMessage($res, 'Erreur lors de la mise à jour.'), $res['data']);
        }

        if ($method === 'DELETE') {
            exigerAdmin();
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');
            $res = supabaseRequest('/imputations?id=eq.' . urlencode($id), 'DELETE');
            if ($res['ok']) logAudit($user['id'], 'suppression', null, 'imputation', (int)$id, null);
            sendJSON($res['code'], $res['ok'] ? 'Imputation supprimée.' : 'Erreur lors de la suppression.', null);
        }
        sendJSON(405, 'Méthode non autorisée');
        break;

    // ---------------------------------------------------------------
    // PIÈCES JOINTES (accès limité pour un AGENT à ce qui lui est imputé)
    // ---------------------------------------------------------------
    case 'pieces_jointes':
        $user = exigerConnexion();

        if ($method === 'GET') {
            $module = $_GET['courrier_module'] ?? '';
            $courrierId = $_GET['courrier_id'] ?? '';
            if (!$module || !$courrierId) sendJSON(400, 'Module et courrier requis.');
            if (!agentPeutAccederCourrier($user, $module, $courrierId)) {
                sendJSON(403, 'Ce courrier ne vous a pas été imputé.');
            }
            $query = '/pieces_jointes?select=*&courrier_module=eq.' . urlencode($module) . '&courrier_id=eq.' . urlencode($courrierId) . '&order=created_at.desc';
            $res = supabaseRequest($query);
            sendJSON($res['code'], $res['ok'] ? 'OK' : getSupabaseErrorMessage($res, 'Impossible de charger les pièces jointes.'), $res['data']);
        }

        if ($method === 'POST') {
            exigerAdminOuSuperAgent();
            if (empty($input['courrier_module']) || empty($input['courrier_id']) || empty($input['chemin']) || empty($input['nom_original'])) {
                sendJSON(400, 'Informations de fichier incomplètes.');
            }
            $payload = [
                'courrier_module' => $input['courrier_module'],
                'courrier_id' => $input['courrier_id'],
                'chemin' => $input['chemin'],
                'nom_original' => $input['nom_original'],
                'type_mime' => $input['type_mime'] ?? null,
                'uploaded_by' => $user['id'],
            ];
            $res = supabaseRequest('/pieces_jointes', 'POST', $payload);
            sendJSON($res['code'], $res['ok'] ? 'Pièce jointe enregistrée.' : getSupabaseErrorMessage($res, 'Erreur lors de l enregistrement.'), $res['data']);
        }

        if ($method === 'DELETE') {
            exigerAdminOuSuperAgent();
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');
            $res = supabaseRequest('/pieces_jointes?id=eq.' . urlencode($id), 'DELETE');
            sendJSON($res['code'], $res['ok'] ? 'Pièce jointe supprimée.' : 'Erreur lors de la suppression.', null);
        }
        sendJSON(405, 'Méthode non autorisée');
        break;

    // ---------------------------------------------------------------
    // NOTIFICATIONS (cloche de la barre supérieure)
    // ---------------------------------------------------------------
    case 'notifications':
        $user = exigerConnexion();

        if ($method === 'GET') {
            $query = '/notifications?select=*&user_id=eq.' . urlencode($user['id']) . '&order=created_at.desc&limit=50';
            $res = supabaseRequest($query);
            if (!$res['ok']) sendJSON($res['code'], getSupabaseErrorMessage($res, 'Impossible de charger les notifications.'));
            $nonLues = count(array_filter($res['data'], fn($n) => !$n['lu']));
            sendJSON(200, 'OK', ['notifications' => $res['data'], 'non_lues' => $nonLues]);
        }

        if ($method === 'PATCH') {
            if (!empty($_GET['all'])) {
                $res = supabaseRequest('/notifications?user_id=eq.' . urlencode($user['id']) . '&lu=eq.false', 'PATCH', ['lu' => true]);
                sendJSON($res['code'], $res['ok'] ? 'Notifications marquées comme lues.' : 'Erreur.', null);
            }
            $id = $_GET['id'] ?? null;
            if (!$id) sendJSON(400, 'Identifiant requis.');
            $res = supabaseRequest('/notifications?id=eq.' . urlencode($id) . '&user_id=eq.' . urlencode($user['id']), 'PATCH', ['lu' => true]);
            sendJSON($res['code'], $res['ok'] ? 'Notification marquée comme lue.' : 'Erreur.', $res['data']);
        }
        sendJSON(405, 'Méthode non autorisée');
        break;

    // ---------------------------------------------------------------
    // HISTORIQUE (journal d'audit — Administrateur / Super Agent)
    // ---------------------------------------------------------------
    case 'historique':
        exigerAdminOuSuperAgent();
        $module = $_GET['courrier_module'] ?? '';
        $entityId = $_GET['entity_id'] ?? '';
        $query = '/audit_logs?select=*,utilisateurs(nom_complet)&order=created_at.desc';
        if ($module) $query .= '&courrier_module=eq.' . urlencode($module);
        if ($entityId) $query .= '&entity_id=eq.' . urlencode($entityId);
        if (!$module && !$entityId) $query .= '&limit=200';
        $res = supabaseRequest($query);
        sendJSON($res['code'], $res['ok'] ? 'OK' : getSupabaseErrorMessage($res, 'Impossible de charger l historique.'), $res['data']);
        break;

    // ---------------------------------------------------------------
    // TABLEAU DE BORD
    // ---------------------------------------------------------------
    case 'dashboard_stats':
        $user = exigerConnexion();

        if ($user['role'] === 'AGENT') {
            // Tableau de bord personnel : uniquement ses imputations
            $res = supabaseRequest('/imputations?select=id,statut,created_at&destinataire_id=eq.' . urlencode($user['id']));
            if (!$res['ok']) sendJSON(500, 'Impossible de calculer les statistiques.');
            $mesImputations = $res['data'];
            $enAttente = count(array_filter($mesImputations, fn($i) => $i['statut'] === 'en_attente'));
            $enCours = count(array_filter($mesImputations, fn($i) => $i['statut'] === 'en_cours'));
            $traitees = count(array_filter($mesImputations, fn($i) => $i['statut'] === 'traite'));
            sendJSON(200, 'OK', [
                'personnel' => true,
                'total_imputations' => count($mesImputations),
                'en_attente' => $enAttente,
                'en_cours' => $enCours,
                'traitees' => $traitees,
            ]);
        }

        // Tableau de bord complet (ADMINISTRATEUR / SUPER_AGENT)
        $entrantsRes = supabaseRequest('/courriers_entrants?select=id,date_reception,priorite,statut,created_at');
        $sortantsRes = supabaseRequest('/courriers_sortants?select=id,date_envoi,created_at');
        $imputationsRes = supabaseRequest('/imputations?select=id,statut,created_at');

        if (!$entrantsRes['ok'] || !$sortantsRes['ok'] || !$imputationsRes['ok']) {
            sendJSON(500, 'Impossible de calculer les statistiques.');
        }

        $entrants = $entrantsRes['data'];
        $sortants = $sortantsRes['data'];
        $imputations = $imputationsRes['data'];
        $aujourdhui = date('Y-m-d');

        $recusAujourdhui = 0;
        $enAttente = 0;
        $traites = 0;
        $urgents = 0;
        $parMoisEntrants = [];
        $urgentsParMois = [];

        foreach ($entrants as $c) {
            if (($c['date_reception'] ?? '') === $aujourdhui) $recusAujourdhui++;
            if ($c['statut'] === 'en_attente') $enAttente++;
            if ($c['statut'] === 'traite') $traites++;
            if (in_array($c['priorite'], ['urgent', 'tres_urgent'], true)) $urgents++;

            $mois = substr($c['date_reception'] ?? $c['created_at'], 0, 7);
            $parMoisEntrants[$mois] = ($parMoisEntrants[$mois] ?? 0) + 1;
            if (in_array($c['priorite'], ['urgent', 'tres_urgent'], true)) {
                $urgentsParMois[$mois] = ($urgentsParMois[$mois] ?? 0) + 1;
            }
        }

        $parMoisSortants = [];
        foreach ($sortants as $c) {
            $mois = substr($c['date_envoi'] ?? $c['created_at'], 0, 7);
            $parMoisSortants[$mois] = ($parMoisSortants[$mois] ?? 0) + 1;
        }

        $imputationsEnAttente = count(array_filter($imputations, fn($i) => $i['statut'] === 'en_attente'));
        $imputationsEnCours = count(array_filter($imputations, fn($i) => $i['statut'] === 'en_cours'));
        $imputationsTraitees = count(array_filter($imputations, fn($i) => $i['statut'] === 'traite'));

        ksort($parMoisEntrants);
        ksort($parMoisSortants);
        ksort($urgentsParMois);

        sendJSON(200, 'OK', [
            'personnel' => false,
            'recus_aujourdhui' => $recusAujourdhui,
            'en_attente' => $enAttente,
            'traites' => $traites,
            'urgents' => $urgents,
            'total_entrants' => count($entrants),
            'total_sortants' => count($sortants),
            'total_imputations' => count($imputations),
            'imputations_en_attente' => $imputationsEnAttente,
            'imputations_en_cours' => $imputationsEnCours,
            'imputations_traitees' => $imputationsTraitees,
            'par_mois_entrants' => $parMoisEntrants,
            'par_mois_sortants' => $parMoisSortants,
            'urgents_par_mois' => $urgentsParMois,
        ]);
        break;

    default:
        sendJSON(404, 'Route inconnue : ' . $route);
}
