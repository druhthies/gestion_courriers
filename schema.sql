-- ===================================================================
-- BASE DE DONNÉES - GESTION DES COURRIERS (v3 — refonte complète)
-- Accueil / Entrants / Sortants / Imputations / Notifications
-- PostgreSQL / Supabase
-- ===================================================================

CREATE TYPE role_enum AS ENUM ('ADMINISTRATEUR', 'SUPER_AGENT', 'AGENT');
CREATE TYPE priorite_enum AS ENUM ('normal', 'urgent', 'tres_urgent');
CREATE TYPE statut_entrant_enum AS ENUM ('en_attente', 'en_cours', 'traite');
CREATE TYPE mode_envoi_enum AS ENUM ('email', 'poste', 'main_propre');
CREATE TYPE courrier_module_enum AS ENUM ('entrant', 'sortant');
CREATE TYPE statut_imputation_enum AS ENUM ('en_attente', 'en_cours', 'traite');

-- ===================================================================
-- UTILISATEURS (3 rôles uniquement)
-- ===================================================================
CREATE TABLE IF NOT EXISTS utilisateurs (
  id VARCHAR(50) PRIMARY KEY,
  nom_complet VARCHAR(150) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role role_enum NOT NULL DEFAULT 'AGENT',
  actif BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_utilisateurs_username ON utilisateurs(username);

-- ===================================================================
-- TYPES DE DOCUMENT (paramétrable, pour les courriers entrants)
-- ===================================================================
CREATE TABLE IF NOT EXISTS types_document (
  id SERIAL PRIMARY KEY,
  nom VARCHAR(150) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO types_document (nom) VALUES
  ('Lettre officielle'), ('Facture'), ('Rapport'), ('Note de service'), ('Plan / Document technique')
ON CONFLICT (nom) DO NOTHING;

-- ===================================================================
-- COMPTEUR DE NUMÉROS (ARR-2026-00001, DEP-2026-00001, ...)
-- ===================================================================
CREATE TABLE IF NOT EXISTS compteurs (
  prefixe VARCHAR(10) NOT NULL,
  annee INT NOT NULL,
  dernier_numero INT NOT NULL DEFAULT 0,
  PRIMARY KEY (prefixe, annee)
);

CREATE OR REPLACE FUNCTION generate_numero(prefixe_in VARCHAR)
RETURNS text AS $$
DECLARE
  annee_courante INT := EXTRACT(YEAR FROM NOW());
  nouveau_numero INT;
BEGIN
  INSERT INTO compteurs (prefixe, annee, dernier_numero)
  VALUES (prefixe_in, annee_courante, 1)
  ON CONFLICT (prefixe, annee) DO UPDATE SET dernier_numero = compteurs.dernier_numero + 1
  RETURNING dernier_numero INTO nouveau_numero;

  RETURN prefixe_in || '-' || annee_courante || '-' || LPAD(nouveau_numero::text, 5, '0');
END;
$$ LANGUAGE plpgsql;

-- ===================================================================
-- COURRIERS ENTRANTS (créés par ADMINISTRATEUR / SUPER_AGENT)
-- ===================================================================
CREATE TABLE IF NOT EXISTS courriers_entrants (
  id SERIAL PRIMARY KEY,
  numero VARCHAR(30) NOT NULL UNIQUE DEFAULT generate_numero('ARR'),
  date_reception DATE NOT NULL DEFAULT CURRENT_DATE,
  expediteur VARCHAR(200) NOT NULL,
  objet TEXT NOT NULL,
  type_document_id INT REFERENCES types_document(id),
  priorite priorite_enum NOT NULL DEFAULT 'normal',
  accuse_reception BOOLEAN NOT NULL DEFAULT FALSE,
  statut statut_entrant_enum NOT NULL DEFAULT 'en_attente',
  created_by VARCHAR(50) REFERENCES utilisateurs(id),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_entrants_date ON courriers_entrants(date_reception);
CREATE INDEX IF NOT EXISTS idx_entrants_statut ON courriers_entrants(statut);
CREATE INDEX IF NOT EXISTS idx_entrants_priorite ON courriers_entrants(priorite);

-- ===================================================================
-- COURRIERS SORTANTS (créés par ADMINISTRATEUR / SUPER_AGENT)
-- ===================================================================
CREATE TABLE IF NOT EXISTS courriers_sortants (
  id SERIAL PRIMARY KEY,
  numero VARCHAR(30) NOT NULL UNIQUE DEFAULT generate_numero('DEP'),
  destinataire VARCHAR(200) NOT NULL,
  objet TEXT NOT NULL,
  date_envoi DATE NOT NULL DEFAULT CURRENT_DATE,
  mode_envoi mode_envoi_enum NOT NULL DEFAULT 'email',
  signature_responsable VARCHAR(150),
  created_by VARCHAR(50) REFERENCES utilisateurs(id),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sortants_date ON courriers_sortants(date_envoi);

-- ===================================================================
-- IMPUTATIONS (remplace "courriers internes" + "affectation")
-- Une imputation = une consigne envoyée par un ADMINISTRATEUR/SUPER_AGENT
-- à un utilisateur, avec en option un courrier entrant/sortant joint.
-- ===================================================================
CREATE TABLE IF NOT EXISTS imputations (
  id SERIAL PRIMARY KEY,
  emetteur_id VARCHAR(50) REFERENCES utilisateurs(id),
  destinataire_id VARCHAR(50) NOT NULL REFERENCES utilisateurs(id),
  consigne TEXT NOT NULL,
  courrier_module courrier_module_enum,
  courrier_id INT,
  fichier_chemin TEXT,
  fichier_nom_original VARCHAR(255),
  fichier_type_mime VARCHAR(100),
  statut statut_imputation_enum NOT NULL DEFAULT 'en_attente',
  reponse TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  pris_en_charge_at TIMESTAMP,
  reponse_at TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_imputations_destinataire ON imputations(destinataire_id);
CREATE INDEX IF NOT EXISTS idx_imputations_emetteur ON imputations(emetteur_id);
CREATE INDEX IF NOT EXISTS idx_imputations_courrier ON imputations(courrier_module, courrier_id);

-- ===================================================================
-- PIÈCES JOINTES (polymorphe : entrant / sortant)
-- ===================================================================
CREATE TABLE IF NOT EXISTS pieces_jointes (
  id SERIAL PRIMARY KEY,
  courrier_module courrier_module_enum NOT NULL,
  courrier_id INT NOT NULL,
  chemin TEXT NOT NULL,
  nom_original VARCHAR(255) NOT NULL,
  type_mime VARCHAR(100),
  uploaded_by VARCHAR(50) REFERENCES utilisateurs(id),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pj_courrier ON pieces_jointes(courrier_module, courrier_id);

-- ===================================================================
-- NOTIFICATIONS (cloche + badge dans la barre supérieure)
-- ===================================================================
CREATE TABLE IF NOT EXISTS notifications (
  id SERIAL PRIMARY KEY,
  user_id VARCHAR(50) NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
  type VARCHAR(50) NOT NULL,          -- 'courrier_entrant' | 'courrier_sortant' | 'imputation' | 'reponse'
  message TEXT NOT NULL,
  lien_module VARCHAR(30),            -- 'entrant' | 'sortant' | 'imputation'
  lien_id INT,
  lu BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, lu);

-- ===================================================================
-- HISTORIQUE / JOURNAL D'AUDIT
-- ===================================================================
CREATE TABLE IF NOT EXISTS audit_logs (
  id SERIAL PRIMARY KEY,
  user_id VARCHAR(50) REFERENCES utilisateurs(id) ON DELETE SET NULL,
  action VARCHAR(100) NOT NULL,
  courrier_module VARCHAR(30),
  entity VARCHAR(100) NOT NULL,
  entity_id INT,
  details TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_logs(courrier_module, entity_id);

-- ===================================================================
-- MIGRATION : fichier libre (PDF/image) joint directement à une
-- imputation, indépendamment d'un courrier entrant/sortant existant.
-- Sans effet si les colonnes existent déjà (base neuve créée ci-dessus).
-- ===================================================================
ALTER TABLE imputations ADD COLUMN IF NOT EXISTS fichier_chemin TEXT;
ALTER TABLE imputations ADD COLUMN IF NOT EXISTS fichier_nom_original VARCHAR(255);
ALTER TABLE imputations ADD COLUMN IF NOT EXISTS fichier_type_mime VARCHAR(100);

-- ===================================================================
-- Premier compte administrateur : voir api/creer-admin.php (README)
-- ===================================================================
