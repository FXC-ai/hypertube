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

# Installer les dépendances PHP si vendor/ est manquant
if [ ! -d "vendor" ]; then
    echo "📦 Installation des dépendances PHP..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Installer les dépendances Node.js si node_modules/ est manquant
if [ ! -d "node_modules" ]; then
    echo "📦 Installation des dépendances npm..."
    npm install
fi

# Générer la clé de l'application si elle n'existe pas
if [ -z "$APP_KEY" ]; then
    echo "⚠️  Clé APP_KEY non définie, génération automatique..."
    php artisan key:generate
fi

# Exécuter les migrations
echo "📦 Migration de la base de données..."
php artisan migrate --force

# Compiler les assets
npm run build

# Redémarrer Vite et queue worker s'ils sont déjà en cours
pkill -f "vite" 2>/dev/null || true
pkill -f "queue:listen" 2>/dev/null || true

# Lancer PHP-FPM en arrière-plan
echo "🐘 Démarrage PHP-FPM..."
php-fpm &
PHP_FPM_PID=$!

# Exécuter un script shell en arrière-plan pour démarrer Vite et le queue worker
(
    echo "🌐 Démarrage du serveur Vite..."
    npm run dev &
    VITE_PID=$!

    echo "📬 Démarrage du queue worker..."
    php artisan queue:listen --tries=1 --timeout=0 &
    QUEUE_PID=$!

    # Surveiller les signaux
    trap "kill $VITE_PID $QUEUE_PID 2>/dev/null; exit 0" SIGINT SIGTERM

    # Attendre que l'un des processus se termine
    wait
) &

# Surveiller PHP-FPM
wait $PHP_FPM_PID
