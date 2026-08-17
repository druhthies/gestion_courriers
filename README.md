# Plateforme de gestion des courriers — v3 (refonte complète)

Reconstruction complète de la plateforme : page d'accueil, courriers
entrants/sortants, module **Imputations** (remplace les anciens
"courriers internes" et "affectation"), 3 rôles utilisateurs, et
système de notifications avec cloche + badge dans la barre supérieure.

## Ce qui a changé par rapport à la v2

- **Page d'accueil** (`accueil.html`) : première page après connexion,
  avec des raccourcis vers les modules disponibles selon le rôle.
- **Imputations** (`imputations.html`) : remplace entièrement les
  anciens modules "Courriers internes" et "Affectation". Une imputation
  est une consigne envoyée par un Administrateur ou un Super Agent à un
  utilisateur, avec en option un courrier entrant/sortant joint. Le
  destinataire peut prendre en charge la consigne ("en cours") puis
  répondre (passage automatique à "traité").
- **3 rôles seulement** :
  - **Administrateur** : tous les droits, y compris Paramétrage.
  - **Super Agent** : mêmes droits qu'Administrateur sauf le
    Paramétrage.
  - **Agent** : ne crée aucun courrier ; reçoit des imputations,
    consulte uniquement ce qui lui est affecté, et répond.
  - La notion de "service"/"bureau" est abandonnée dans cette refonte.
- **Notifications** : une notification est créée automatiquement à
  chaque nouveau courrier entrant/sortant (pour l'équipe de gestion),
  nouvelle imputation (pour le destinataire) et nouvelle réponse (pour
  l'émetteur). La cloche 🔔 dans la barre supérieure affiche un badge
  rouge avec le nombre de notifications non lues ; cliquer dessus
  ouvre la liste et marque les notifications comme lues.
- **Tableau de bord** : vue complète (Admin/Super Agent) avec
  statistiques et graphiques, ou vue personnelle (Agent) limitée à ses
  propres imputations.
- **Paramètres** (admin uniquement) : types de document + gestion des
  comptes utilisateurs (3 rôles). La gestion des services a été
  retirée (notion abandonnée dans cette refonte).

## Déploiement

1. **Base de données** : ouvrez le SQL Editor de votre projet Supabase
   et exécutez tout le contenu de [`schema.sql`](schema.sql). Comme les
   noms/valeurs ont changé (rôles, table `imputations`, table
   `notifications`, plus de table `services`/`affectations`/
   `courriers_internes`), utilisez un **nouveau projet Supabase** (ou
   repartez d'une base vide) plutôt que de réutiliser celui de la v2.
2. **Configuration** : `config.php` contient les identifiants Supabase.
   Vérifiez/adaptez le chemin NAS `$nasPath` si besoin.
3. **Déploiement sur le NAS** : copiez tout le dossier dans votre Web
   Station.
4. **Premier compte administrateur** : ouvrez une seule fois
   `api/creer-admin.php?nom=Votre+Nom&username=admin&password=VotreMotDePasse`,
   puis supprimez ce fichier.
5. Connectez-vous sur `index.html`. Vous arrivez sur la page d'accueil.

## Fichiers

```
schema.sql                    Schéma complet v3 (à exécuter dans Supabase)
config.php                    Configuration Supabase + chemin NAS
api/index.php                 Routeur principal (tous les modules)
api/upload-scan.php            Réception d'un fichier (scan/pièce jointe) → NAS
api/voir-scan.php              Diffusion d'un fichier pour affichage
api/creer-admin.php            Script à usage unique (premier compte admin)
js/api-client.js               Client JS (tous les appels API)
js/navbar.js                   Barre de navigation + cloche de notifications
css/style.css                  Feuille de style (charte + ajouts v3)
index.html                     Connexion
accueil.html                   Page d'accueil (raccourcis selon le rôle)
tableau-de-bord.html           Tableau de bord (vue complète ou personnelle)
courriers-entrants.html        Module courriers entrants (Admin/Super Agent)
courriers-sortants.html        Module courriers sortants (Admin/Super Agent)
imputations.html               Module imputations (consignes, réponses, suivi)
parametres.html                 Paramètres (admin uniquement)
```

## Points volontairement laissés hors de cette refonte

- **Signature électronique** et **archivage** (prévus dans une version
  antérieure du cahier des charges) ne font pas partie de cette
  refonte — à réintégrer plus tard si toujours souhaités.
- Les notifications sont **in-app uniquement** (pas d'envoi par email).

## Prochaines évolutions possibles

- Export PDF/Excel des registres
- Recherche avancée par plage de dates
- Notifications par email en plus du in-app
