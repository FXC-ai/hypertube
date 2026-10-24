# Migrer Hypertube de SQLite vers PostgreSQL

Ce guide remplace SQLite par PostgreSQL pour l'application, les queues, les
sessions et le cache Laravel. Redis n'est pas nécessaire pour corriger le
problème rencontré : PostgreSQL sait gérer plusieurs écritures concurrentes et
Laravel utilise `FOR UPDATE SKIP LOCKED` lorsqu'il réserve des jobs sur
PostgreSQL.

Cette migration répond aussi à un risque de la grille 42 : une erreur HTTP 5xx
ou une erreur serveur pendant la soutenance est considérée comme un crash. Elle
ne modifie aucune exigence fonctionnelle du sujet, mais fiabilise le pipeline de
téléchargement et de conversion décrit dans la section « Video Part ».

## Architecture visée

```text
Navigateur ──> Nginx ──> Laravel ──> PostgreSQL
                              │          ├── données applicatives
                              │          ├── jobs
                              │          ├── sessions
                              │          └── cache
                              │
                              └──> Client Torrent ──> fichiers vidéo
```

Les tests peuvent continuer à utiliser SQLite en mémoire grâce à
`phpunit.xml`. Il faut donc conserver `pdo_sqlite` dans l'image PHP, même si
l'application locale utilise PostgreSQL.

## 1. Sauvegarder l'état actuel

Arrêter les écritures avant de copier la base :

```bash
docker compose down
cp database/database.sqlite database/database.sqlite.backup
```

`docker compose down` ne supprime pas les volumes nommés. Ne pas ajouter `-v`,
car cette option supprimerait le volume PostgreSQL après sa création.

Les téléchargements actifs du client torrent sont conservés sur disque, mais
son état en mémoire est perdu lorsque le conteneur s'arrête. Il vaut mieux
terminer ou annuler proprement un téléchargement avant la migration.

## 2. Ajouter le pilote PostgreSQL à PHP

Dans `Dockerfile`, ajouter `libpq-dev` aux paquets APT et `pdo_pgsql` aux
extensions PHP. Conserver les dépendances SQLite utilisées par les tests :

```dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        ffmpeg \
        git \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        exif \
        gd \
        mbstring \
        pcntl \
        pdo \
        pdo_pgsql \
        pdo_sqlite \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
```

Sans `pdo_pgsql`, Laravel échouera avec `could not find driver`.

## 3. Ajouter PostgreSQL à Docker Compose

Ajouter ce service dans `docker-compose.yml` :

```yaml
  postgres:
    image: postgres:17-alpine
    environment:
      POSTGRES_DB: "${DB_DATABASE:-hypertube}"
      POSTGRES_USER: "${DB_USERNAME:-hypertube}"
      POSTGRES_PASSWORD: "${DB_PASSWORD}"
    volumes:
      - postgres-data:/var/lib/postgresql/data
    healthcheck:
      test:
        [
          "CMD-SHELL",
          "pg_isready -U ${DB_USERNAME:-hypertube} -d ${DB_DATABASE:-hypertube}",
        ]
      interval: 5s
      timeout: 5s
      retries: 10
```

Faire attendre PostgreSQL au service `app` :

```yaml
  app:
    # configuration existante...
    depends_on:
      postgres:
        condition: service_healthy
```

Déclarer enfin le volume à la racine du fichier :

```yaml
volumes:
  postgres-data:
```

Il n'est pas nécessaire de publier le port `5432` pour Laravel : les services
communiquent sur le réseau interne Docker. Pour utiliser un client PostgreSQL
depuis la machine hôte, ajouter temporairement au service :

```yaml
    ports:
      - "5432:5432"
```

Ne pas exposer ce port en production sans filtrage réseau.

## 4. Configurer l'environnement Laravel

Remplacer la configuration SQLite de `.env` :

```dotenv
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=hypertube
DB_USERNAME=hypertube
DB_PASSWORD=choisir-un-secret-long-et-unique

DB_QUEUE_CONNECTION=pgsql
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

Dans un conteneur, `DB_HOST` doit être le nom du service `postgres`, jamais
`127.0.0.1` ni `localhost`.

Appliquer les mêmes clés, avec des valeurs factices sûres, dans `.env.example`
afin que l'installation soit reproductible. Ne jamais versionner le vrai mot de
passe de `.env`.

La configuration `pgsql` existe déjà dans `config/database.php`. Les connexions
`monitoring`, `conversions` et `subtitles` de `config/queue.php` utilisent
`DB_QUEUE_CONNECTION`; elles pointeront donc toutes vers PostgreSQL.

## 5. Construire les conteneurs

Le pilote PHP est installé pendant la construction de l'image. Une simple
relance ne suffit pas après la modification du `Dockerfile` :

```bash
docker compose build --no-cache app
docker compose up -d postgres
docker compose ps
```

Attendre que le service `postgres` soit `healthy`, puis vérifier la connexion :

```bash
docker compose exec postgres \
    pg_isready -U hypertube -d hypertube
```

## 6. Choisir la stratégie de données

### Option A — Repartir d'une base propre (recommandé en développement)

Cette option est la plus fiable, car PostgreSQL obtient exactement le schéma
décrit par les migrations Laravel.

Démarrer l'application :

```bash
docker compose up -d app nginx client-torrent
```

L'entrypoint exécute déjà `php artisan migrate --force`. Pour reconstruire la
base et charger les données de démonstration :

```bash
docker compose exec app php artisan migrate:fresh --seed --no-interaction
```

Attention : `migrate:fresh` supprime toutes les tables de la base PostgreSQL
ciblée. Vérifier impérativement `DB_CONNECTION`, `DB_HOST` et `DB_DATABASE`
avant de l'exécuter.


## 7. Vider les anciennes configurations Laravel

Après toute modification de `.env` :

```bash
docker compose exec app php artisan optimize:clear
```

Confirmer ensuite que Laravel utilise PostgreSQL :

```bash
docker compose exec app php artisan config:show database.default
docker compose exec app php artisan db:show --database=pgsql
docker compose exec app php -m | grep -i pgsql
```

Le premier résultat doit contenir `pgsql`, et la liste des modules doit contenir
`pdo_pgsql`.

## 8. Lancer et vérifier les workers

Le `docker-compose.yml` actuel ne démarre pas automatiquement les workers. Pour
tester le pipeline dans des terminaux séparés :

```bash
docker compose exec app \
    php artisan queue:work monitoring --queue=monitoring --tries=1 --timeout=0
```

```bash
docker compose exec app \
    php artisan queue:work conversions --queue=conversions --tries=1 --timeout=0
```

Si les sous-titres externes sont traités par une queue dédiée :

```bash
docker compose exec app \
    php artisan queue:work subtitles --queue=subtitles --tries=1 --timeout=120
```

Lancer ensuite simultanément un téléchargement et une conversion. Vérifier que
les jobs progressent et qu'aucune erreur `database is locked` n'apparaît :

```bash
docker compose logs -f app client-torrent
```

## 9. Exécuter les tests

La suite de tests reste volontairement configurée avec SQLite en mémoire. Elle
vérifie la portabilité des migrations tout en restant rapide :

```bash
docker compose exec app php artisan test --compact
```

Une vérification PostgreSQL est également nécessaire, car un test SQLite ne
peut pas détecter toutes les différences entre moteurs :

```bash
docker compose exec app php artisan migrate:fresh --seed --no-interaction
docker compose exec app php artisan migrate:status
```

Cette commande détruit les données PostgreSQL de développement. Ne jamais la
lancer sur une base contenant des données à conserver.

Checklist fonctionnelle minimale :

- inscription et connexion ;
- modification du profil ;
- consultation et ajout de commentaires ;
- lancement d'un torrent ;
- progression de `MonitorDownload` ;
- conversion HLS simultanée ;
- lecture du film avant la fin du téléchargement ;
- redémarrage des workers sans job bloqué ;
- absence d'erreur serveur et d'erreur dans la console du navigateur.

## 10. Dépannage

### `could not find driver`

`pdo_pgsql` manque dans l'image PHP. Vérifier le `Dockerfile`, puis reconstruire
l'image avec `docker compose build --no-cache app`.

### `connection refused`

Vérifier que `DB_HOST=postgres`, que le conteneur est `healthy` et que Laravel
n'utilise pas une ancienne configuration mise en cache.

### `password authentication failed`

Les variables `POSTGRES_*` ne sont appliquées que lors de l'initialisation d'un
volume vide. Changer `DB_PASSWORD` après la création du volume ne change pas
automatiquement le mot de passe du rôle PostgreSQL. Il faut soit modifier le
rôle avec `ALTER ROLE`, soit recréer volontairement le volume après sauvegarde.

### Les migrations semblent déjà exécutées

Contrôler la connexion réellement utilisée :

```bash
docker compose exec app php artisan config:show database.default
docker compose exec app php artisan migrate:status
```

Puis exécuter `php artisan optimize:clear` si l'ancienne configuration SQLite
est encore affichée.

### Les tests échouent alors que l'application fonctionne

Ne pas retirer `pdo_sqlite` : `phpunit.xml` utilise `sqlite` avec
`DB_DATABASE=:memory:`. L'application locale et les tests peuvent employer deux
moteurs différents.

## 11. Retour temporaire à SQLite

Arrêter les services, restaurer la configuration SQLite dans `.env`, puis
remettre la sauvegarde en place si nécessaire :

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/database/database.sqlite
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

```bash
cp database/database.sqlite.backup database/database.sqlite
docker compose up -d
docker compose exec app php artisan optimize:clear
```

Le volume PostgreSQL peut rester présent pendant ce retour arrière. Ne le
supprimer qu'après avoir validé définitivement la migration et créé une
sauvegarde PostgreSQL.

## Résultat attendu

Après la migration, les workers peuvent réserver, mettre à jour et supprimer
des jobs en parallèle avec les requêtes HTTP. Les queues restent stockées en
base, mais elles ne sont plus limitées par le verrou d'écriture global du
fichier SQLite. Redis pourra être ajouté plus tard pour les performances ; il
n'est pas requis pour résoudre l'incident actuel.
