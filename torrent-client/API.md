# API du Client Torrent

Service HTTP séparé (voir [docs/architecture.md](../docs/architecture.md) et
[issue #6](https://github.com/FXC-ai/hypertube/issues/6)). Ce document décrit le contrat
HTTP pour l'équipe qui l'appelle depuis Laravel (voir issue #13) - pour l'implémentation
interne et les tests, voir [README.md](README.md).

## Où ça tourne

- En local : `npm start` (ou `node src/index.js`) - port `7881` par défaut, override via
  `PORT`.
- En Docker : service `client-torrent` dans `docker-compose.yml` racine, joignable depuis
  `app` à `http://client-torrent:7881` sur le réseau Docker interne (voir #17).

Pas d'authentification - le service n'est censé être joignable que depuis le réseau Docker
interne, jamais exposé publiquement.

## `GET /health`

Vérification de vie, utilisée par le healthcheck Docker.

```
GET /health
```

**200** :
```json
{ "status": "ok" }
```

## `POST /downloads`

Démarre un téléchargement en arrière-plan et répond immédiatement - le parsing du
`.torrent`, l'annonce aux trackers et le téléchargement des pièces se font après, de façon
asynchrone. Le client doit ensuite interroger `GET /downloads/:id` pour suivre la
progression.

```
POST /downloads
Content-Type: application/json
```

Corps JSON - deux façons de fournir le torrent, une seule à la fois :

| Champ | Type | Description |
|---|---|---|
| `outputDir` | string | **Obligatoire.** Chemin absolu où écrire les fichiers du torrent, à l'intérieur du volume partagé. Pour un film, utiliser exactement `storage/app/public/movies/{movieId}` (vu depuis `app`, donc `/var/www/html/storage/app/public/movies/{movieId}` côté conteneur) - c'est le chemin que `Storage::disk('public')->path("movies/{id}/{filename}")` va relire côté Laravel. |
| `torrentUrl` | string | URL d'un `.torrent` que le service télécharge lui-même avant de démarrer. |
| `torrentBase64` | string | Contenu brut du `.torrent`, encodé en base64, si vous l'avez déjà en mémoire côté Laravel plutôt qu'une URL à fetch. |

Fournir `torrentUrl` **ou** `torrentBase64`, pas les deux (si les deux sont présents,
`torrentUrl` est ignoré - `torrentBase64` prend le dessus). Ni l'un ni l'autre : `400`.

**Exemple** :
```bash
curl -X POST http://client-torrent:7881/downloads \
  -H "Content-Type: application/json" \
  -d '{
    "torrentUrl": "https://archive.org/download/some_item/some_item_archive.torrent",
    "outputDir": "/var/www/html/storage/app/public/movies/42"
  }'
```

**202 Accepted** :
```json
{ "id": "2ce4f502-b325-444f-9053-da3174fb94b5" }
```

**400 Bad Request** - `outputDir` manquant, ni `torrentUrl` ni `torrentBase64` fournis, ou
JSON invalide :
```json
{ "error": "outputDir is required" }
```

Garder l'`id` retourné : c'est la seule façon de récupérer le statut ou d'annuler ensuite,
rien n'est indexé par `outputDir` ni par une notion de film.

## `GET /downloads/:id`

État courant d'un téléchargement.

```
GET /downloads/2ce4f502-b325-444f-9053-da3174fb94b5
```

**200** :
```json
{
  "id": "2ce4f502-b325-444f-9053-da3174fb94b5",
  "status": "downloading",
  "downloadedBytes": 1048576,
  "totalBytes": 3020385,
  "piecesCompleted": 2,
  "totalPieces": 6,
  "error": null,
  "outputDir": "/var/www/html/storage/app/public/movies/42"
}
```

| Champ | Description |
|---|---|
| `status` | `"downloading"` \| `"completed"` \| `"failed"` \| `"cancelled"` |
| `downloadedBytes`, `totalBytes` | Progression en octets. `totalBytes` est `null` tant que le `.torrent` n'a pas encore été parsé (juste après le `POST`, très bref). |
| `piecesCompleted`, `totalPieces` | Progression en pièces BitTorrent - plus fin que les octets pour un affichage de progression. |
| `error` | `null` sauf si `status` est `"failed"` - message expliquant l'échec (tracker sans pairs ni web-seed, torrent malformé, toutes les sources ont échoué sur une pièce, etc.). |

**404** si l'`id` est inconnu :
```json
{ "error": "Unknown download id" }
```

Pas de webhook / notification - c'est à l'appelant de sonder cette route (ex. toutes les
1-2 secondes) tant que `status` reste `"downloading"`.

## `DELETE /downloads/:id`

Annule un téléchargement en cours.

```
DELETE /downloads/2ce4f502-b325-444f-9053-da3174fb94b5
```

**200** - renvoie le statut au moment de l'appel. Comme l'arrêt des connexions en vol
n'est pas instantané, la réponse peut encore afficher `"downloading"` juste après l'appel ;
un `GET` normalement quelques centaines de ms plus tard confirme `"cancelled"`. Appeler
`DELETE` sur un téléchargement déjà terminé (`completed`/`failed`/`cancelled`) est un
no-op sans erreur - la réponse renvoie simplement son statut final inchangé.

**404** si l'`id` est inconnu.

Les fichiers déjà écrits avant l'annulation restent sur disque (partiels, pas de nettoyage
automatique) - à supprimer côté appelant si besoin.

## Exemple de flux complet

```bash
# 1. Démarrer
id=$(curl -s -X POST http://client-torrent:7881/downloads \
  -H "Content-Type: application/json" \
  -d '{"torrentUrl":"https://example.org/movie.torrent","outputDir":"/var/www/html/storage/app/public/movies/42"}' \
  | jq -r .id)

# 2. Sonder jusqu'à complétion
while true; do
  status=$(curl -s http://client-torrent:7881/downloads/$id)
  echo "$status"
  echo "$status" | jq -e '.status == "completed" or .status == "failed"' > /dev/null && break
  sleep 2
done

# 3. Annuler si besoin (avant complétion)
curl -X DELETE http://client-torrent:7881/downloads/$id
```

## Ce que l'API ne fait pas

- Pas de recherche de films / résolution de source - l'appelant fournit déjà une URL ou un
  contenu `.torrent` concret (voir issue #13 côté Laravel pour la résolution
  archive.org/publicdomaintorrents.info).
- Pas de connexion base de données, pas de notion de `Movie` ou d'utilisateur - le service
  ne connaît que des jobs de téléchargement identifiés par un UUID généré à la volée.
- Pas de persistance : un redémarrage du conteneur perd l'état de tous les téléchargements
  en cours (voir la note correspondante dans le README).
