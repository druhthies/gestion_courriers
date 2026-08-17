# Image PHP officielle avec Apache (dernière version stable)
FROM php:8.3-apache

# Répertoire de travail = racine servie par Apache
WORKDIR /var/www/html

# Dépendances de compilation + extension curl (seule extension requise :
# tous les échanges de données passent par l'API REST Supabase via cURL)
RUN apt-get update && apt-get upgrade -y && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    && docker-php-ext-install curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# mod_rewrite pour le routage de index.html, mod_headers pour les en-têtes CORS
RUN a2enmod rewrite headers

# Copier le code de l'application
COPY . /var/www/html/

# Script de démarrage (configure le port Render puis lance Apache)
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Dossier de stockage local des scans (utilisé hors NAS, ex: sur Render)
# Doit être accessible en écriture par l'utilisateur du serveur web
RUN mkdir -p /var/www/html/scans_local \
    && chown -R www-data:www-data /var/www/html/

# Autoriser le .htaccess du projet (sinon Apache renvoie 403 ou ignore les règles)
COPY apache-override.conf /etc/apache2/conf-available/override.conf
RUN a2enconf override

# Port par défaut documenté (Render fournit le vrai port via $PORT au démarrage)
EXPOSE 10000

CMD ["/usr/local/bin/start.sh"]
