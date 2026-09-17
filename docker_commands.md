# 🐳 Commandes Docker - Projet Laravel

Ce fichier contient toutes les commandes Docker nécessaires pour gérer ce projet.

## 🚀 Démarrage

```bash
# 1. Construire et lancer les containers en arrière-plan
docker compose up -d --build

# 2. (Optionnel) Générer la clé de l'application
docker compose exec app php artisan key:generate

# 3. (Optionnel) Exécuter les migrations de la base de données
docker compose exec app php artisan migrate

# 4. Ouvrir dans votre navigateur
# http://localhost:8000
```

## 🛠️ Commandes courantes

### Gestion des containers

```bash
# Arrêter tous les containers
docker compose down

# Voir les logs en temps réel
docker compose logs -f

# Redémarrer un service spécifique
docker compose restart nginx
```

### Installation et dépendances

```bash
# Installer les dépendances PHP (Composer)
docker compose exec app composer install

# Installer les dépendances Node.js (npm)
docker compose exec app npm install

# Mettre à jour les dépendances
docker compose exec app composer update
```

### Développement

```bash
# Lancer Vite (frontend) en mode développement
docker compose exec app npm run dev

# Lancer le queue worker
docker compose exec app php artisan queue:listen

# Lancer les deux simultanément
docker compose exec app sh -c "npm run dev & php artisan queue:listen"
```

### Artisan & Base de données

```bash
# Entrer dans le container (shell)
docker compose exec app bash

# Exécuter une commande Tinker
docker compose exec app php artisan tinker

# Créer une migration
docker compose exec app php artisan make:migration create_table_name

# Créer un modèle
docker compose exec app php artisan make:model ModelName

# Créer un contrôleur
docker compose exec app php artisan make:controller ControllerName

# Créer un test
docker compose exec app php artisan make:test TestName --pest

# Exécuter les tests
docker compose exec app php artisan test

# Créer une factory
docker compose exec app php artisan make:factory FactoryName
```

## 🔍 Dépannage

```bash
# Vérifier l'état des containers
docker compose ps

# Vider le cache de configuration
docker compose exec app php artisan config:clear

# Vider le cache des routes
docker compose exec app php artisan route:clear

# Vider le cache des vues
docker compose exec app php artisan view:clear

# Rebuild sans cache
docker compose up -d --build --no-cache
```

## 📁 Structure Docker

```
├── Dockerfile              # Image avec PHP 8.3 + Node.js 20
├── docker-compose.yml      # Nginx + PHP-FPM
├── .dockerignore           # Fichiers exclus du build
├── .env                    # Configuration de l'environnement
└── docker/
    ├── nginx/
    │   └── conf.d/
    │       └── default.conf  # Configuration Nginx
    └── entrypoint.sh         # Script de démarrage
```

## 💡 Astuces

- **Volume monté** : Votre code local est synchronisé en temps réel avec le container. Les modifications sont immédiates.
- **Base de données** : SQLite est utilisé par défaut, les données sont stockées dans votre projet local.
- **Assets** : Après modification de fichiers frontend (`resources/js`), exécutez `npm run build` pour les recompiler.
