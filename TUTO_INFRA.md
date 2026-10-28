# Lancer automatiquement les workers Laravel avec Docker Compose

Ce tutoriel remplace le lancement manuel des workers dans plusieurs terminaux par des services Docker Compose.

L'architecture obtenue est la suivante :

| Service Compose | Nombre de conteneurs | File Laravel traitée |
| --- | ---: | --- |
| `worker-monitoring` | 1 | `monitoring` |
| `worker-subtitles` | 1 | `subtitles` |
| `worker-conversions` | 3 | `conversions` |

Chaque conteneur exécute un seul processus `php artisan queue:work`. Les trois conteneurs de conversion proviennent du même service Compose et de la même image : il n'est pas nécessaire de déclarer trois services différents.

## 1. Ajouter les services workers

Ouvrir `docker-compose.yml` et ajouter les trois services suivants sous `services:`, au même niveau que `app`, `scheduler`, `client-torrent` et `postgres` :

```yaml
  worker-monitoring:
    build:
      context: .
      dockerfile: Dockerfile
      args:
        WWW_USER: "${HOST_UID:-1000}"
        WWW_GROUP: "${HOST_GID:-1000}"
    volumes:
      - .:/var/www/html
    command:
      - php
      - artisan
      - queue:work
      - monitoring
      - --queue=monitoring
      - --tries=1
    restart: unless-stopped
    depends_on:
      app:
        condition: service_healthy
      postgres:
        condition: service_healthy

  worker-subtitles:
    build:
      context: .
      dockerfile: Dockerfile
      args:
        WWW_USER: "${HOST_UID:-1000}"
        WWW_GROUP: "${HOST_GID:-1000}"
    volumes:
      - .:/var/www/html
    command:
      - php
      - artisan
      - queue:work
      - subtitles
      - --queue=subtitles
      - --tries=1
      - --timeout=120
    restart: unless-stopped
    depends_on:
      app:
        condition: service_healthy
      postgres:
        condition: service_healthy

  worker-conversions:
    build:
      context: .
      dockerfile: Dockerfile
      args:
        WWW_USER: "${HOST_UID:-1000}"
        WWW_GROUP: "${HOST_GID:-1000}"
    volumes:
      - .:/var/www/html
    command:
      - php
      - artisan
      - queue:work
      - conversions
      - --queue=conversions
      - --tries=1
      - --timeout=21540
    restart: unless-stopped
    depends_on:
      app:
        condition: service_healthy
      postgres:
        condition: service_healthy
```

Ne pas ajouter `container_name` à ces services. Docker Compose doit pouvoir choisir un nom différent pour chaque réplique de `worker-conversions`.

### Pourquoi attendre `app` ?

L'entrypoint actuel de `app` installe les dépendances, crée éventuellement le fichier `.env`, génère la clé de l'application et exécute les migrations. Son healthcheck devient valide une fois cette préparation terminée.

Les workers attendent donc que `app` soit sain avant de démarrer. Ils partagent ensuite le dépôt grâce au volume `.:/var/www/html` et accèdent à la même base PostgreSQL.

### Pourquoi ne pas utiliser `docker/entrypoint.sh` ?

Les services workers ne déclarent pas cet entrypoint. Leur processus principal est directement `php artisan queue:work`. Docker peut ainsi détecter sa terminaison et redémarrer le conteneur grâce à `restart: unless-stopped`.

## 2. Vérifier la configuration Compose

Avant de démarrer les conteneurs, demander à Docker Compose de résoudre et valider le fichier :

```bash
docker compose config --quiet
```

Cette commande ne doit produire aucune erreur.

Pour afficher la configuration résolue :

```bash
docker compose config
```

## 3. Démarrer l'application et les workers

Démarrer l'ensemble du projet en demandant trois répliques du service de conversion :

```bash
docker compose up -d --build --scale worker-conversions=3
```

Cette commande démarre notamment :

```text
1 × worker-monitoring
1 × worker-subtitles
3 × worker-conversions
```

Les noms exacts dépendent du nom du projet Compose, mais ils ressembleront à :

```text
hypertube-worker-monitoring-1
hypertube-worker-subtitles-1
hypertube-worker-conversions-1
hypertube-worker-conversions-2
hypertube-worker-conversions-3
```

Il n'est plus nécessaire de conserver des terminaux ouverts. Le mode détaché `-d` laisse les conteneurs fonctionner en arrière-plan.

## 4. Vérifier que les cinq workers fonctionnent

Afficher les conteneurs du projet :

```bash
docker compose ps
```

Afficher les logs de tous les workers :

```bash
docker compose logs -f \
    worker-monitoring \
    worker-subtitles \
    worker-conversions
```

Quitter l'affichage des logs avec `Ctrl+C` ne coupe pas les workers.

Pour ne suivre que les conversions :

```bash
docker compose logs -f worker-conversions
```

Les trois répliques consomment la même file `conversions`. PostgreSQL réserve chaque job pour un seul worker disponible ; trois films au maximum peuvent donc être convertis simultanément.

## 5. Modifier le nombre de conversions simultanées

Passer de trois à cinq workers de conversion :

```bash
docker compose up -d --scale worker-conversions=5
```

Revenir à deux workers :

```bash
docker compose up -d --scale worker-conversions=2
```

Revenir à la configuration prévue de trois workers :

```bash
docker compose up -d --scale worker-conversions=3
```

Le nombre de répliques n'est pas écrit en dur dans la définition du service. Il est choisi au lancement avec `--scale`.

## 6. Redémarrer les workers après une modification PHP

`queue:work` conserve l'application Laravel en mémoire. Après une modification du code PHP utilisé par les jobs, redémarrer les services workers :

```bash
docker compose restart \
    worker-monitoring \
    worker-subtitles \
    worker-conversions
```

Docker Compose redémarre toutes les répliques appartenant au service `worker-conversions`.

Pour demander à Laravel d'arrêter gracieusement les workers après leur job en cours :

```bash
docker compose exec app php artisan queue:restart
```

Avec `restart: unless-stopped`, les conteneurs dont le worker termine seront redémarrés automatiquement. Pour une conversion longue, attendre sa fin avant de forcer l'arrêt du conteneur.

## 7. Arrêter les workers ou le projet

Arrêter seulement les workers sans supprimer leurs conteneurs :

```bash
docker compose stop \
    worker-monitoring \
    worker-subtitles \
    worker-conversions
```

Les redémarrer avec trois conversions simultanées :

```bash
docker compose up -d --scale worker-conversions=3 \
    worker-monitoring \
    worker-subtitles \
    worker-conversions
```

Arrêter et supprimer tous les conteneurs du projet :

```bash
docker compose down
```

Cette dernière commande conserve le volume PostgreSQL. Ne pas ajouter `--volumes` si les données de la base doivent être conservées.

## 8. Commandes de diagnostic utiles

Afficher les jobs ayant définitivement échoué :

```bash
docker compose exec app php artisan queue:failed
```

Afficher les dernières lignes des logs Laravel :

```bash
docker compose exec app php artisan pail
```

Vérifier le nombre de conteneurs de conversion actifs :

```bash
docker compose ps worker-conversions
```

Si aucun job n'est traité, vérifier dans cet ordre :

1. que les workers apparaissent comme actifs dans `docker compose ps` ;
2. que leurs logs ne contiennent pas d'erreur de connexion à PostgreSQL ;
3. que `.env` contient bien `QUEUE_CONNECTION=database` et `DB_QUEUE_CONNECTION=pgsql` ;
4. que le job est envoyé sur la connexion et la file attendues ;
5. que le conteneur `app` est sain.

## Résultat attendu

Après installation, la commande habituelle de démarrage est :

```bash
docker compose up -d --build --scale worker-conversions=3
```

Les workers de monitoring et de sous-titres démarrent une fois chacun. Le worker de conversion démarre en trois exemplaires et permet de traiter jusqu'à trois conversions en parallèle, sans ouvrir cinq terminaux.
