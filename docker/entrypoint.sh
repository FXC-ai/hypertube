#!/bin/bash
set -e

echo "🚀 Démarrage du serveur de développement..."

# Créer les répertoires nécessaires (volume mount peut écraser ceux de l'image)
mkdir -p storage/framework/{views,cache,sessions,testing}
mkdir -p storage/logs
mkdir -p bootstrap/cache
chmod -R 777 storage bootstrap/cache

# Rendre la base SQLite accessible en écriture
if [ -f "database/database.sqlite" ]; then
    chmod 666 database/database.sqlite
fi


echo "📦 Installation des dépendances PHP..."
composer install --no-interaction --prefer-dist --optimize-autoloader

# Générer la clé de l'application si elle n'existe pas
if [ -z "$APP_KEY" ]; then
    echo "⚠️  Clé APP_KEY non définie, génération automatique..."
    php artisan key:generate
fi

echo "📦 Installation des dépendances npm..."
npm install

# Exécuter les migrations
echo "📦 Migration de la base de données..."
php artisan migrate --force

# Lancer PHP-FPM en arrière-plan
echo "🐘 Démarrage PHP-FPM..."
composer dev
