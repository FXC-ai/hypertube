# API du Client Torrent

Service HTTP séparé (voir [docs/architecture.md](../docs/architecture.md) et [issue #6](https://github.com/FXC-ai/hypertube/issues/6)). Ce document décrit le contrat HTTP pour l'équipe qui l'appelle depuis Laravel (voir issue #13) - pour l'implémentation interne et les tests, voir [README.md](README.md).

## Où ça tourne

- En local : `npm start` (ou `node src/index.js`) - port `7881` par défaut, override via `PORT`.
- En Docker : service `client-torrent` dans `docker-compose.yml` racine, joignable depuis `app` à `http://client-torrent:7881` sur le réseau Docker interne (voir #17).

Pas d'authentification - le service n'est censé être joignable que depuis le réseau Docker interne, jamais exposé publiquement.

## Évolutions en cours

Les tickets A, C et B sont implémentés : tout ce document décrit le comportement actuel. Conception complète dans [overview.md](overview.md#conception-sélection-de-fichiers-robustesse-et-streaming-tickets-a-c-b).

| Ticket | Ce qui change dans l'API |
|---|---|
| **A - Sélection de fichiers** ✅ implémenté ([#26](https://github.com/FXC-ai/hypertube/issues/26)) | Nouveau `POST /torrents/inspect`. `POST /downloads` accepte `fileIndexes` et `expectedInfoHash`. Détail par fichier dans `GET /downloads/:id`. |
| **C - Robustesse des sources + reprise** ✅ implémenté ([#27](https://github.com/FXC-ai/hypertube/issues/27)) | Nouvel état `"checking"` (vérification des fichiers déjà sur disque, [ADR-0008](../docs/adr/0008-client-state-in-memory-with-disk-recheck.md)). Nouveau champ `sources`. |
| **B - Streaming** ✅ implémenté ([#28](https://github.com/FXC-ai/hypertube/issues/28), [ADR-0007](../docs/adr/0007-stream-partial-files-over-http.md)) | Nouveau `GET /downloads/:id/files/:index` avec support `Range`. Champs `contiguousBytesFromStart`, `availableRanges`, `detectedContainer` et `pieces` dans `GET /downloads/:id`. |

## `POST /torrents/inspect`

Lit un `.torrent` et renvoie la liste de ses fichiers, **sans rien télécharger** d'autre que le `.torrent` lui-même. Sert à Laravel pour choisir quels fichiers télécharger avant d'appeler `POST /downloads`. Contrairement à `POST /downloads`, la réponse est synchrone.

```
POST /torrents/inspect
Content-Type: application/json
```

Corps JSON : `torrentUrl` **ou** `torrentBase64`, mêmes règles que pour `POST /downloads`.

**200** :
```json
{
  "infoHash": "3f4c8a...e1",
  "name": "some_item",
  "pieceLength": 2097152,
  "totalPieces": 1421,
  "totalLength": 2980052992,
  "mainVideoIndex": 0,
  "files": [
    { "index": 0, "path": "Movie/Movie.mp4", "fileName": "Movie.mp4", "length": 2961234567, "kind": "video", "container": "mp4", "suggested": true },
    { "index": 1, "path": "Movie/Subs/Movie.en.srt", "fileName": "Movie.en.srt", "length": 81234, "kind": "subtitle", "container": null, "suggested": true },
    { "index": 2, "path": "some_item_meta.sqlite", "fileName": "some_item_meta.sqlite", "length": 20480, "kind": "other", "container": null, "suggested": false },
    { "index": 3, "path": "Movie/Movie.ogv", "fileName": "Movie.ogv", "length": 18716711, "kind": "video", "container": "ogg", "suggested": false }
  ]
}
```

| Champ | Description |
|---|---|
| `infoHash` | À renvoyer tel quel dans `expectedInfoHash` au `POST /downloads`, voir plus bas. |
| `files[].index` | Identifiant du fichier pour `fileIndexes` et pour `GET /downloads/:id/files/:index`. C'est sa position dans la liste `files` du `.torrent`. |
| `files[].path` | Chemin du fichier **dans le torrent**, sous-dossiers compris. |
| `files[].fileName` | Nom du fichier **sur disque**, directement dans `outputDir` : tous les fichiers sont écrits à plat, sans les sous-dossiers du torrent. Deux fichiers de même nom (casse ignorée) deviennent `nom.ext` et `nom (2).ext`, dans l'ordre du torrent ; un nom vide, `.` ou `..` devient `file-<index>`. C'est la valeur à stocker côté Laravel (`movies/{id}/{fileName}`). |
| `files[].kind` | `"video"` \| `"subtitle"` \| `"other"`, déduit de l'extension uniquement (aucun octet du film n'est lu à ce stade). Vidéo : `mp4`, `m4v`, `mov`, `mkv`, `webm`, `avi`, `ogv`. Sous-titre : `srt`, `vtt`, `ass`, `ssa`, `sub`. |
| `files[].container` | `"mp4"` \| `"matroska"` \| `"ogg"` \| `"avi"` \| `null`, déduit de l'extension. |
| `mainVideoIndex` | Le plus gros fichier `"video"`, ou `null` s'il n'y en a aucun. |
| `files[].suggested` | Proposition du client : la vidéo principale et tous les fichiers de sous-titres non vides (archive.org publie parfois des sous-titres de 0 octet). **C'est une suggestion** : Laravel reste libre de choisir d'autres index. |

**400** - JSON invalide, ni `torrentUrl` ni `torrentBase64`, ou `.torrent` malformé.
**502** - `torrentUrl` injoignable ou réponse HTTP non-2xx.

Attention aux sources qui régénèrent leur `.torrent` (archive.org, voir [docs/testing-torrent-sources.md](../docs/testing-torrent-sources.md)) : entre l'inspection et le téléchargement, le `.torrent` servi à la même URL peut avoir changé, et les index ne plus désigner les mêmes fichiers. D'où `expectedInfoHash`.

## `GET /` - page de test

Ouvrir `http://localhost:7881/` dans un navigateur : un formulaire pré-rempli (torrent archive.org court, `outputDir` par défaut, ou un `.torrent` local envoyé en `torrentBase64`) pour inspecter, choisir les fichiers, lancer un téléchargement, suivre la progression et l'annuler. La page affiche aussi la réponse complète de l'inspection, l'historique des téléchargements lancés dans l'onglet, et une référence de chaque route (corps, codes, exemples) avec un bouton pour l'essayer. Cette référence vient d'un objet `ROUTES` dans `src/server/ui.html`, à tenir à jour avec ce document. Hors Docker, `outputDir` vaut un dossier temporaire ; dans Docker, le chemin du volume partagé avec `app`. Cette page n'est pas destinée à Laravel, elle sert à tester à la main.

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

Démarre un téléchargement en arrière-plan et répond immédiatement - le parsing du `.torrent`, l'annonce aux trackers et le téléchargement des pièces se font après, de façon asynchrone. Le client doit ensuite interroger `GET /downloads/:id` pour suivre la progression.

```
POST /downloads
Content-Type: application/json
```

Corps JSON - deux façons de fournir le torrent, une seule à la fois :

| Champ | Type | Description |
|---|---|---|
| `outputDir` | string | **Obligatoire.** Chemin absolu où écrire les fichiers du torrent, à l'intérieur du volume partagé. Pour un film, utiliser exactement `storage/app/public/movies/{movieId}` (vu depuis `app`, donc `/var/www/html/storage/app/public/movies/{movieId}` côté conteneur) - c'est le chemin que `Storage::disk('public')->path("movies/{id}/{filename}")` va relire côté Laravel. Les fichiers y sont écrits à plat, sous leur `fileName` (voir `POST /torrents/inspect`). |
| `torrentUrl` | string | URL d'un `.torrent` que le service télécharge lui-même avant de démarrer. |
| `torrentBase64` | string | Contenu brut du `.torrent`, encodé en base64, si vous l'avez déjà en mémoire côté Laravel plutôt qu'une URL à fetch. |

| `fileIndexes` | int[] | *Optionnel.* Index des fichiers à télécharger (voir `POST /torrents/inspect`). Absent : les fichiers `suggested` de l'inspection. Pas un tableau, liste vide, doublon, ou autre chose que des entiers positifs : `400`. Index hors bornes : le job passe en `"failed"` (le `.torrent` n'est récupéré qu'après la réponse `202`, le nombre de fichiers n'est donc pas encore connu). Les octets des autres fichiers ne sont jamais écrits sur disque, et ces fichiers ne sont pas créés. |
| `expectedInfoHash` | string | *Optionnel, recommandé dès qu'on passe `fileIndexes`.* L'`infoHash` renvoyé par l'inspection (40 caractères hexadécimaux, sinon `400`). Si le `.torrent` récupéré a un autre infohash, le job passe en `"failed"` avec une erreur explicite au lieu de télécharger des fichiers qui ne sont peut-être plus les bons. |

Fournir `torrentUrl` **ou** `torrentBase64`, pas les deux (si les deux sont présents, `torrentUrl` est ignoré - `torrentBase64` prend le dessus). Ni l'un ni l'autre : `400`.

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

**400 Bad Request** - `outputDir` manquant, ni `torrentUrl` ni `torrentBase64` fournis, ou JSON invalide :
```json
{ "error": "outputDir is required" }
```

Garder l'`id` retourné : c'est la seule façon de récupérer le statut ou d'annuler ensuite, rien n'est indexé par `outputDir` ni par une notion de film.

**Reprise** : si `outputDir` contient déjà des fichiers de ce torrent (téléchargement précédent échoué, annulé, ou interrompu par un redémarrage du conteneur), le job commence par les revérifier pièce par pièce (état `"checking"`) et ne retélécharge que les pièces manquantes ou corrompues. Pour reprendre, il suffit donc de relancer `POST /downloads` avec le **même** `outputDir`. Voir [ADR-0008](../docs/adr/0008-client-state-in-memory-with-disk-recheck.md).

Ne pas lancer deux jobs sur le même `outputDir` en même temps : ils écriraient dans les mêmes fichiers. Le client ne le détecte pas.

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
| `error` | `null` sauf si `status` est `"failed"` - message expliquant l'échec (tracker sans pairs ni web-seed, torrent malformé, aucune pièce n'a abouti pendant 2 min, etc.). Pour un blocage, le message liste les pièces bloquées avec chaque cause distincte, et les sources écartées avec leur dernière erreur. |

### Champs ajoutés (tickets A, B, C)

Tous ces champs sont implémentés.

```json
{
  "id": "2ce4f502-b325-444f-9053-da3174fb94b5",
  "status": "downloading",
  "infoHash": "3f4c8a...e1",
  "pieceLength": 2097152,
  "downloadedBytes": 1048576,
  "totalBytes": 2961315801,
  "piecesCompleted": 2,
  "totalPieces": 1413,
  "files": [
    {
      "index": 0,
      "path": "Movie/Movie.mp4",
      "fileName": "Movie.mp4",
      "length": 2961234567,
      "downloadedBytes": 6291456,
      "contiguousBytesFromStart": 4194304,
      "availableRanges": [[0, 4194304], [2959137415, 2961234567]],
      "complete": false
    },
    {
      "index": 1,
      "path": "Movie/Subs/Movie.en.srt",
      "fileName": "Movie.en.srt",
      "length": 81234,
      "downloadedBytes": 81234,
      "contiguousBytesFromStart": 81234,
      "availableRanges": [[0, 81234]],
      "complete": true
    }
  ],
  "pieces": "wAAAAAAAAAA...AAE=",
  "sources": { "active": 3, "dropped": 2 },
  "error": null,
  "outputDir": "/var/www/html/storage/app/public/movies/42"
}
```

| Champ | Ticket | Description |
|---|---|---|
| `status` | C | Nouvelle valeur `"checking"`, **état initial** d'un job : récupération du `.torrent` puis vérification des fichiers déjà présents dans `outputDir`, avant `"downloading"`. `piecesCompleted` et `downloadedBytes` y progressent au fil des pièces valides trouvées. Si tout est déjà sur disque, le job passe directement à `"completed"` sans contacter de tracker. `DELETE` annule aussi un job en `"checking"`. |
| `downloadedBytes`, `totalBytes`, `piecesCompleted`, `totalPieces` | A | **Changement de sens** : ne comptent plus que les fichiers choisis (et les pièces qui les couvrent), plus tout le torrent. `totalBytes` est la somme des `files[].length`. |
| `infoHash`, `pieceLength` | A | Info-hash et taille de pièce du torrent, `null` tant que le `.torrent` n'a pas été parsé. |
| `files[]` | A | Un élément par fichier **choisi**, dans l'ordre des index : `index`, `path`, `fileName`, `length`, `downloadedBytes`, `complete`. Vide tant que le `.torrent` n'a pas été parsé. |
| `files[].contiguousBytesFromStart` | B | Octets disponibles d'un seul tenant depuis le début du fichier. Indicateur pour "prêt à regarder" côté UI. |
| `files[].detectedContainer` | B | Format lu dans les premiers octets du fichier, sans ffmpeg : `"mp4"` (boîte `ftyp` aux octets 4 à 8), `"matroska"` (en-tête EBML `1A 45 DF A3`, MKV et WebM), `"unknown"`, ou `null` tant que la première pièce du fichier n'est pas arrivée. Permet de repérer un faux fichier (un `.mp4` qui n'en est pas un) sans attendre la fin du téléchargement. Le client ne décide rien : c'est à Laravel d'annuler s'il le veut. Les pistes et codecs restent l'affaire de ffprobe. |
| `files[].availableRanges` | B | Plages d'octets disponibles, `[début, fin exclue]`, fusionnées et triées, relatives au fichier. |
| `pieces` | B | Bitfield des pièces vérifiées, en base64, au format du message `bitfield` de BitTorrent (bit de poids fort du premier octet = pièce 0). Pour le débogage et la page de test, pas besoin de le décoder côté Laravel. |
| `sources` | C | Sources utilisables (`active`) et écartées (`dropped`), pairs et web-seeds confondus. `null` avant le début du téléchargement. Une source injoignable 3 fois de suite est écartée 30 s puis retentée ; une source qui envoie 2 pièces corrompues l'est pour de bon. |
| `error` | C | Inclut désormais la cause réseau précise quand il y en a une (ex. `fetch failed (ECONNRESET)` au lieu de `fetch failed`). |

**404** si l'`id` est inconnu :
```json
{ "error": "Unknown download id" }
```

Pas de webhook / notification - c'est à l'appelant de sonder cette route (ex. toutes les 1-2 secondes) tant que `status` reste `"checking"` ou `"downloading"`.

## `DELETE /downloads/:id`

Annule un téléchargement en cours.

```
DELETE /downloads/2ce4f502-b325-444f-9053-da3174fb94b5
```

**200** - renvoie le statut au moment de l'appel. Comme l'arrêt des connexions en vol n'est pas instantané, la réponse peut encore afficher `"downloading"` juste après l'appel ; un `GET` normalement quelques centaines de ms plus tard confirme `"cancelled"`. Appeler `DELETE` sur un téléchargement déjà terminé (`completed`/`failed`/`cancelled`) est un no-op sans erreur - la réponse renvoie simplement son statut final inchangé.

**404** si l'`id` est inconnu.

Les fichiers déjà écrits avant l'annulation restent sur disque (partiels, pas de nettoyage automatique) - à supprimer côté appelant si besoin.

## `GET /downloads/:id/files/:index`

Sert un fichier choisi d'un téléchargement, **pendant** qu'il se télécharge, avec support des requêtes `Range`. C'est l'entrée que ffprobe et ffmpeg lisent à la place du chemin disque tant que le téléchargement n'est pas terminé. Pourquoi ce choix plutôt que lire le fichier qui grossit : [ADR-0007](../docs/adr/0007-stream-partial-files-over-http.md).

```
GET /downloads/2ce4f502-b325-444f-9053-da3174fb94b5/files/0
Range: bytes=2959137415-
```

### Comportement

1. La requête enregistre la plage demandée comme **prioritaire** : les pièces qui la couvrent, plus une fenêtre d'avance (`STREAM_READAHEAD_BYTES`, 8 Mo par défaut), passent devant toutes les autres. La fenêtre avance au fil de la lecture.
2. Si les premiers octets de la plage sont déjà sur disque, la réponse part tout de suite. Sinon, le client **attend** qu'ils arrivent avant d'envoyer les en-têtes.
3. Les octets sont ensuite envoyés au fur et à mesure que les pièces arrivent. Le reste du fichier continue de se télécharger en arrière-plan.
4. Si aucune nouvelle pièce de la plage n'arrive pendant `STREAM_STALL_TIMEOUT_MS` (60 s par défaut, compteur remis à zéro à chaque pièce reçue), c'est une situation **temporaire** : le téléchargement continue, la pièce peut encore arriver.
   - avant l'envoi des en-têtes : réponse **503** + `Retry-After: 5` ;
   - après l'envoi des en-têtes : la connexion est coupée (on ne peut plus changer le code). Le lecteur voit une réponse plus courte que son `Content-Length` ; ffmpeg se reconnecte alors à l'octet exact, et c'est cette nouvelle requête qui reçoit le 503.
5. Si le job est `"failed"` ou `"cancelled"`, c'est **définitif** : les octets déjà présents restent servis, mais une requête sur des octets manquants reçoit tout de suite **410 Gone** avec la cause connue dans le corps (une réponse déjà en cours est coupée, et la reconnexion reçoit le 410).

```json
{ "status": "failed", "error": "No piece completed for 120s (812/1413 downloaded, 0 active source(s), 5 dropped). Stuck: piece 12 ..." }
```

6. Les pièces prioritaires (plages demandées, et dès le démarrage la première et la dernière pièce de la vidéo principale) partent vers les sources qui ont déjà livré des pièces, ou vers un web-seed tant qu'aucune ne l'a fait, plutôt que vers le prochain pair pas encore testé. Mesuré sur un film archive.org de 575 Mo : ffprobe répond en 5 s au lieu de 10 à 25 s sans cette règle.
7. Juste après `POST /downloads`, tant que le `.torrent` n'est pas encore parsé, la requête attend (au plus `STREAM_STALL_TIMEOUT_MS`, puis 503).

Sans en-tête `Range`, la réponse est un `200` avec le fichier entier, servi de la même façon (en attendant les pièces au fil de l'eau).

### Réponses

| Code | Cas |
|---|---|
| **206** | Plage valide. En-têtes `Content-Range: bytes début-fin/taille`, `Content-Length`, `Accept-Ranges: bytes`, `Content-Type` déduit de l'extension (`video/mp4`, `video/x-matroska`, `video/webm`, `text/vtt`, `application/x-subrip`, sinon `application/octet-stream`). |
| **200** | Pas d'en-tête `Range` : fichier entier. |
| **404** | `id` inconnu (y compris après un redémarrage du conteneur, l'état étant en mémoire), ou `index` qui n'est pas un fichier choisi de ce téléchargement. |
| **416** | Plage hors du fichier. |
| **410** | Le job est `"failed"` ou `"cancelled"` et ces octets n'arriveront jamais. Corps JSON `{ "status", "error" }` avec la cause connue. **Ne pas réessayer.** |
| **503** | Délai d'attente dépassé, téléchargement toujours en cours : **réessayer** (`Retry-After: 5`). |

Formes de `Range` supportées : `bytes=début-fin`, `bytes=début-` et `bytes=-N` (les N derniers octets). Une seule plage par requête (pas de `multipart/byteranges`).

### Côté ffmpeg (Laravel)

- Passer l'URL `http://client-torrent:7881/downloads/{id}/files/{index}` comme entrée de `ffprobe` et `ffmpeg` tant que `GET /downloads/:id` ne renvoie pas `"completed"`, puis le chemin disque habituel.
- Options d'entrée à placer avant `-i` (et avant l'URL pour ffprobe), vérifiées avec le prototype (voir le [guide d'intégration](../docs/torrent-streaming-laravel-integration.md)) :

  ```
  -rw_timeout 90000000 -reconnect 1 -reconnect_on_network_error 1 -reconnect_on_http_error 5xx -reconnect_delay_max 5
  ```

  - `-rw_timeout` (microsecondes) **au-dessus** de `STREAM_STALL_TIMEOUT_MS`, sinon ffmpeg abandonne pendant que le client attend encore une pièce.
  - `-reconnect_on_http_error 5xx` : à l'ouverture du flux, un 503 est retenté, un 410 arrête net. Sans cette option, ffprobe abandonne au premier 503.
  - `-reconnect_delay_max 5` : en cours de lecture, ffmpeg retente sur toute erreur jusqu'à ce délai. C'est le client qui attend les pièces lentes, donc un délai court suffit et un job en échec est détecté en quelques secondes (39 s avec 30, 18 s avec 5 dans le prototype).
- **Ajouter `-xerror` à la commande ffmpeg** : sans lui, une coupure définitive donne un HLS tronqué avec un code de sortie 0.
- Un seul lecteur par film est prévu : le ffmpeg de `ConvertMovie`. Les spectateurs lisent le HLS produit, jamais cet endpoint.

### Variables d'environnement

| Variable | Défaut | Rôle |
|---|---|---|
| `STREAM_READAHEAD_BYTES` | `8388608` (8 Mo) | Taille de la fenêtre d'avance priorisée après la position lue. |
| `STREAM_STALL_TIMEOUT_MS` | `60000` | Délai sans nouvelle pièce avant d'abandonner une requête. |

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

### Avec sélection de fichiers et streaming

```bash
# 1. Inspecter le .torrent et garder les fichiers suggérés
inspect=$(curl -s -X POST http://client-torrent:7881/torrents/inspect \
  -H "Content-Type: application/json" \
  -d '{"torrentUrl":"https://example.org/movie.torrent"}')
hash=$(echo "$inspect" | jq -r .infoHash)
indexes=$(echo "$inspect" | jq -c '[.files[] | select(.suggested) | .index]')
main=$(echo "$inspect" | jq -r .mainVideoIndex)

# 2. Démarrer seulement ces fichiers
id=$(curl -s -X POST http://client-torrent:7881/downloads \
  -H "Content-Type: application/json" \
  -d "{\"torrentUrl\":\"https://example.org/movie.torrent\",\"outputDir\":\"/var/www/html/storage/app/public/movies/42\",\"fileIndexes\":$indexes,\"expectedInfoHash\":\"$hash\"}" \
  | jq -r .id)

# 3. ffprobe lit le film pendant le téléchargement : le client priorise tout seul la fin du fichier (moov)
ffprobe -v error -rw_timeout 90000000 -show_streams -of json \
  "http://client-torrent:7881/downloads/$id/files/$main"
```

## Ce que l'API ne fait pas

- Pas de recherche de films / résolution de source - l'appelant fournit déjà une URL ou un contenu `.torrent` concret (voir issue #13 côté Laravel pour la résolution archive.org/publicdomaintorrents.info).
- Pas de connexion base de données, pas de notion de `Movie` ou d'utilisateur - le service ne connaît que des jobs de téléchargement identifiés par un UUID généré à la volée.
- Pas de persistance : un redémarrage du conteneur perd l'état de tous les téléchargements en cours (voir la note correspondante dans le README). Relancer `POST /downloads` sur le même `outputDir` reprend là où les fichiers en étaient ([ADR-0008](../docs/adr/0008-client-state-in-memory-with-disk-recheck.md)).
- Pas d'analyse des formats vidéo : le client ne parse ni MP4 ni MKV. Il sert des plages d'octets, et c'est ffprobe/ffmpeg qui savent où chercher `moov` ou `Cues` ([ADR-0007](../docs/adr/0007-stream-partial-files-over-http.md)).
