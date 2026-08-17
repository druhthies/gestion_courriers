// ===================================================================
// CLIENT API - Plateforme Courriers (v3)
// ===================================================================

const API_URL = 'api/index.php';
const UPLOAD_URL = 'api/upload-scan.php';
const VOIR_SCAN_URL = 'api/voir-scan.php';

const CourrierAPI = {
  async _appel(route, { method = 'GET', params = {}, body = null } = {}, attempt = 1) {
    const query = new URLSearchParams({ route, method, ...params });
    const options = {
      method: method === 'GET' ? 'GET' : 'POST', // PHP lit $_GET['method'] pour le vrai verbe
      credentials: 'include',
      headers: body ? { 'Content-Type': 'application/json' } : {},
      body: body ? JSON.stringify(body) : undefined,
    };

    const url = `${API_URL}?${query.toString()}`;
    try {
      const res = await fetch(url, options);
      if ([502, 503, 504].includes(res.status) && attempt < 3) {
        await new Promise((resolve) => setTimeout(resolve, 250 * attempt));
        return this._appel(route, { method, params, body }, attempt + 1);
      }
      const json = await res.json();
      if (json.status < 200 || json.status >= 300) {
        throw new Error(json.message || 'Erreur inconnue.');
      }
      return json.data;
    } catch (err) {
      if (attempt < 3 && !(err instanceof Error && err.message && err.message !== 'Failed to fetch')) {
        await new Promise((resolve) => setTimeout(resolve, 250 * attempt));
        return this._appel(route, { method, params, body }, attempt + 1);
      }
      throw new Error(
        err instanceof Error && err.message
          ? err.message
          : 'Impossible de contacter le serveur. Réessayez dans quelques instants.'
      );
    }
  },

  // --- Authentification ---
  login(username, password) {
    return this._appel('login', { method: 'POST', body: { username, password } });
  },
  logout() {
    return this._appel('logout', { method: 'POST' });
  },
  moi() {
    return this._appel('me');
  },

  // --- Types de document ---
  listerTypesDocument() {
    return this._appel('types_document');
  },
  ajouterTypeDocument(nom) {
    return this._appel('types_document', { method: 'POST', body: { nom } });
  },
  supprimerTypeDocument(id) {
    return this._appel('types_document', { method: 'DELETE', params: { id } });
  },

  // --- Utilisateurs ---
  listerUtilisateurs() {
    return this._appel('utilisateurs');
  },
  creerUtilisateur(u) {
    return this._appel('utilisateurs', { method: 'POST', body: u });
  },
  modifierUtilisateur(id, champs) {
    return this._appel('utilisateurs', { method: 'PATCH', params: { id }, body: champs });
  },
  supprimerUtilisateur(id) {
    return this._appel('utilisateurs', { method: 'DELETE', params: { id } });
  },

  // --- Courriers entrants ---
  listerEntrants(filtres = {}) {
    return this._appel('courriers_entrants', { method: 'GET', params: filtres });
  },
  creerEntrant(data) {
    return this._appel('courriers_entrants', { method: 'POST', body: data });
  },
  modifierEntrant(id, champs) {
    return this._appel('courriers_entrants', { method: 'PATCH', params: { id }, body: champs });
  },
  supprimerEntrant(id) {
    return this._appel('courriers_entrants', { method: 'DELETE', params: { id } });
  },

  // --- Courriers sortants ---
  listerSortants(filtres = {}) {
    return this._appel('courriers_sortants', { method: 'GET', params: filtres });
  },
  creerSortant(data) {
    return this._appel('courriers_sortants', { method: 'POST', body: data });
  },
  modifierSortant(id, champs) {
    return this._appel('courriers_sortants', { method: 'PATCH', params: { id }, body: champs });
  },
  supprimerSortant(id) {
    return this._appel('courriers_sortants', { method: 'DELETE', params: { id } });
  },

  // --- Imputations ---
  listerImputations(filtres = {}) {
    return this._appel('imputations', { method: 'GET', params: filtres });
  },
  creerImputation(data) {
    return this._appel('imputations', { method: 'POST', body: data });
  },
  modifierImputation(id, champs) {
    return this._appel('imputations', { method: 'PATCH', params: { id }, body: champs });
  },
  supprimerImputation(id) {
    return this._appel('imputations', { method: 'DELETE', params: { id } });
  },

  // --- Pièces jointes ---
  listerPiecesJointes(courrierModule, courrierId) {
    return this._appel('pieces_jointes', { method: 'GET', params: { courrier_module: courrierModule, courrier_id: courrierId } });
  },
  async televerserFichier(courrierModule, numero, fichier) {
    const formData = new FormData();
    formData.append('courrier_module', courrierModule);
    formData.append('numero', numero);
    formData.append('fichier', fichier);
    const res = await fetch(UPLOAD_URL, { method: 'POST', credentials: 'include', body: formData });
    const json = await res.json();
    if (json.status < 200 || json.status >= 300) throw new Error(json.message || 'Échec du téléversement.');
    return json.data;
  },
  enregistrerPieceJointe(data) {
    return this._appel('pieces_jointes', { method: 'POST', body: data });
  },
  async televerserFichierImputation(fichier) {
    const formData = new FormData();
    formData.append('courrier_module', 'imputation');
    formData.append('fichier', fichier);
    const res = await fetch(UPLOAD_URL, { method: 'POST', credentials: 'include', body: formData });
    const json = await res.json();
    if (json.status < 200 || json.status >= 300) throw new Error(json.message || 'Échec du téléversement.');
    return json.data;
  },
  supprimerPieceJointe(id) {
    return this._appel('pieces_jointes', { method: 'DELETE', params: { id } });
  },
  urlScan(chemin) {
    return `${VOIR_SCAN_URL}?chemin=${encodeURIComponent(chemin)}`;
  },

  // --- Notifications ---
  listerNotifications() {
    return this._appel('notifications');
  },
  marquerNotificationLue(id) {
    return this._appel('notifications', { method: 'PATCH', params: { id } });
  },
  marquerToutesNotificationsLues() {
    return this._appel('notifications', { method: 'PATCH', params: { all: 1 } });
  },

  // --- Historique ---
  historique(filtres = {}) {
    return this._appel('historique', { method: 'GET', params: filtres });
  },

  // --- Tableau de bord ---
  dashboardStats() {
    return this._appel('dashboard_stats');
  },
};
