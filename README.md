# Hypertube

Application web (projet 42) permettant de chercher et regarder des vidéos : la recherche
est faite sur des catalogues externes libres de droits, le fichier est téléchargé sur le
serveur via le protocole **BitTorrent**, puis streamé pendant le téléchargement dès que
l'image est fluide.

## Stack

| Composant | Rôle |
| --- | --- |
| **Laravel 13 + Inertia + React** | L'application, l'API, la file de jobs, la conversion. |
| **Client Torrent** (`torrent-client/`) | Service Node.js séparé, sans dépendance npm, qui parle BitTorrent nativement (bencode, trackers, peer wire). Il ne connaît que des jobs identifiés par un UUID. |
| **ffmpeg / ffprobe** | Conversion « on the fly » des formats non lisibles par le navigateur (MKV au minimum) et lecture des fichiers pendant le téléchargement. |
| **PostgreSQL 17** | Base de données (sessions, queue, films, commentaires). |
| **Nginx** | Front HTTP sur `:8000`. |

Le dépôt est containerisé : `docker-compose.yml` démarre `nginx`, `app` (PHP-FPM + Node),
`postgres`, `client-torrent` (`:7881`) et `scheduler` (nettoyage quotidien des films).

Toutes les commandes de développement peuvent s'executer directement **dans le conteneur** via :

```bash
docker compose exec app <commande>
```

## Installation

```bash
cp .env.example .env      # puis renseigner les clés d'API (voir plus bas)
docker compose up -d --build
```

Un seul lancement suffit : `docker/entrypoint.sh` prépare l'application toute seule —
dossiers de `storage/`, copie de `.env` si absent, `composer install`, `npm install`,
`key:generate`, `migrate --force`, clés OAuth2, **client de l'API créé et écrit dans
`.env`**, génération de la documentation Swagger, puis Vite, le worker de file et PHP-FPM.

Le site est sur http://localhost:8000. Vite (assets) écoute sur `:5173`, le Client
Torrent sur `:7881`.

## Développement

Rien à lancer à la main en Docker : l'entrypoint tient déjà Vite et le worker de file.
Pour relancer après une modification de `.env` :

```bash
docker compose restart app
```

La **file de jobs est obligatoire** : les téléchargements et les conversions sont des jobs
en arrière-plan, sans worker aucune vidéo ne démarre.

Sans Docker, les trois processus se lancent avec :

```bash
composer run setup   # première fois seulement
composer run dev     # serveur + worker + Vite
```

`composer run setup` génère les clés OAuth2 (`storage/oauth-*.key`) si elles n'existent pas
encore : il peut être relancé sans les écraser. Le client de l'API se crée ensuite avec
`php artisan api:client`.

```bash
docker compose up -d --build          # démarrer la stack
docker compose down                   # la stopper (volumes conservés)
docker compose logs -f app            # logs d'un service
docker compose ps                     # état des conteneurs
docker compose exec app bash          # shell dans le conteneur applicatif
```

## Commandes métier

```bash
docker compose exec app php artisan import:wikidata-movies   # importer les films du domaine public Wikidata
docker compose exec app php artisan archive:fetch-popularity # récupérer les comptages de téléchargements archive.org (tri par popularité)
docker compose exec app php artisan movies:purge-stale       # supprimer les fichiers non vus depuis un mois
```

La purge est aussi exécutée automatiquement chaque jour par le service `scheduler`, à
l'heure fixée par `MOVIE_CLEANUP_TIME`.

## Qualité et tests

```bash
docker compose exec app php artisan test --compact
docker compose exec app php artisan test --compact --filter=MovieControllerTest
docker compose exec app composer run lint          # Pint (formate le PHP)
docker compose exec app composer run lint:check    # Pint en vérification seule
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app npm run lint:check         # ESLint
docker compose exec app npm run format:check       # Prettier
docker compose exec app npm run types:check        # tsc
docker compose exec app composer run ci:check      # tout ce qui précède + les tests
```

Les tests utilisent Pest et une base SQLite en mémoire ; ils ne touchent pas la base de
développement.

## Authentification

- Inscription et connexion par identifiant + mot de passe (mot de passe haché en base).
- Connexion via OAuth externe : **42** et **GitHub**.
- Réinitialisation du mot de passe par e-mail.
- L'e-mail reste privé : il n'est jamais exposé sur le profil d'un autre utilisateur.

## API RESTful (OAuth2)

Documentation interactive : **http://localhost:8000/api/documentation**
(spécification brute sur http://localhost:8000/docs).

| Méthode | Endpoint | Accès |
| --- | --- | --- |
| `POST` | `/oauth/token` | client + secret (et identifiant/mot de passe) |
| `GET` | `/api` | public, décrit l'API |
| `GET` | `/api/movies` | public, la « front page » triée par popularité |
| `GET` | `/api/movies/{id}` | authentifié |
| `GET` | `/api/users` | authentifié |
| `GET` | `/api/users/{id}` | authentifié ; l'e-mail uniquement pour le propriétaire |
| `PATCH` | `/api/users/{id}` | authentifié ; son propre profil seulement, sinon 403 |
| `GET` | `/api/comments` | authentifié, les plus récents |
| `GET` | `/api/comments/{id}` | authentifié |
| `POST` | `/api/comments` | authentifié, payload `comment` + `movie_id` |
| `GET` | `/api/movies/{id}/comments` | authentifié |
| `POST` | `/api/movies/{id}/comments` | authentifié |
| `PATCH` | `/api/comments/{id}` | authentifié ; auteur uniquement |
| `DELETE` | `/api/comments/{id}` | authentifié ; auteur uniquement, répond 204 |

Une route inconnue répond 404, un verbe non supporté répond 405.

### Le client OAuth2

Au premier `docker compose up`, un client « password grant » nommé *Hypertube API* est
créé et ses credentials écrits dans `.env` (`PASSPORT_API_CLIENT_ID`,
`PASSPORT_API_CLIENT_SECRET`) : rien à faire. `migrate:fresh --seed` et chaque démarrage du
conteneur `app` le recréent avec les mêmes credentials s'il a disparu de la base. Après un
`migrate:fresh` sans `--seed` :

```bash
make oauth-client   # ou : docker compose exec app php artisan api:client
```

Seule la route `POST /oauth/token` de Passport est exposée (grants `password` et
`refresh_token`) ; les autres (`/oauth/authorize`, `/oauth/device`...) répondent 404.

Les credentials ne doivent jamais être commités : `.env` est ignoré par git.

### Obtenir un token et appeler l'API

```bash
eval "$(grep -E '^PASSPORT_API_CLIENT_(ID|SECRET)=' .env)"

curl -s -X POST http://localhost:8000/oauth/token \
  -H 'Accept: application/json' \
  -d "grant_type=password&client_id=$PASSPORT_API_CLIENT_ID&client_secret=$PASSPORT_API_CLIENT_SECRET&username=<email ou identifiant>&password=<mot de passe>"

curl -s http://localhost:8000/api/movies/1 \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer <access_token>"
```

Le token se renouvelle sur la même route avec
`grant_type=refresh_token&refresh_token=<refresh_token>&client_id=...&client_secret=...`.
Un id qui n'est pas un entier (`/api/movies/abc`) répond 404.

### Régénérer la documentation

Les annotations sont dans `app/Http/Controllers/Api` ; la spec générée est ignorée par
git et produite par le script `setup` :

```bash
docker compose exec app php artisan l5-swagger:generate
```

## Variables d'environnement

Tout est dans `.env` (ignoré par git), les clés à renseigner :

| Variable | Usage |
| --- | --- |
| `APP_URL` | URL de l'application, sert aux liens (avatars, HLS) et aux redirects OAuth. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL. |
| `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` | Envoi des e-mails (réinitialisation de mot de passe). |
| `OMDB_API_KEY` | Note IMDb, année, durée, casting, synopsis. |
| `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `GITHUB_REDIRECT_URI` | Stratégie OAuth GitHub. |
| `FORTY_TWO_CLIENT_ID`, `FORTY_TWO_CLIENT_SECRET`, `FORTY_TWO_REDIRECT_URI` | Stratégie OAuth 42. |
| `PASSPORT_API_CLIENT_ID`, `PASSPORT_API_CLIENT_SECRET` | Client confidentiel de l'API, rempli tout seul au premier lancement. |
| `SOURCE_WIKIDATA_ENABLED`, `SOURCE_WHITELIST_ENABLED` | Cataloges interrogés par la recherche. |
| `MOVIE_CLEANUP_TIME` | Heure quotidienne de la purge des films non vus. |
| `L5_SWAGGER_GENERATE_ALWAYS` | Régénère la spec Swagger à chaque requête (dev uniquement). |

## Contraintes du sujet

- Sources strictement libres de droits ou légalement distribuables : **Wikidata (domaine
  public)** et **archive.org** pour les torrents, OMDb uniquement pour les métadonnées.
- Interdit : les bibliothèques faisant du streaming directement depuis un torrent
  (WebTorrent, Pulsar, Peerflix). Le téléchargement se fait côté serveur, en arrière-plan.
- Un film déjà téléchargé est conservé sur le serveur ; il est supprimé s'il n'a pas été
  vu depuis un mois.
- Toute faille de sécurité est éliminatoire : mots de passe hachés, validation de tous les
  formulaires et uploads, aucune injection SQL, credentials hors du dépôt.

## Documentation du projet

- `docs/42/subject.md` et `docs/42/evaluation.md` : sujet et grille d'évaluation.
- `docs/architecture.md` : architecture réelle du code.
- `docs/adr/` : décisions techniques conservées.
- `torrent-client/overview.md` : protocole interne du Client Torrent.
