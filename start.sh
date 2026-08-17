#!/bin/bash
set -e

# ---------------------------------------------------------------------------
# start.sh — script de démarrage du conteneur (PHP + Apache) pour Render
#
# Rôle :
#   1. Configurer Apache pour écouter sur le port fourni par Render (variable
#      d'environnement $PORT, différente à chaque déploiement). Le Dockerfile
#      ne peut pas faire ça au moment du build car $PORT n'existe qu'à
#      l'exécution du conteneur — c'est pour ça que ça doit être fait ici.
#   2. Démarrer Apache au premier plan (requis par Docker).
# ---------------------------------------------------------------------------

PORT="${PORT:-10000}"
echo "[start.sh] Configuration d'Apache pour écouter sur le port $PORT"

sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf

echo "[start.sh] Démarrage d'Apache..."
exec apache2-foreground
