# Client Torrent — Guide de test

Service Node.js séparé (voir [docs/architecture.md](../docs/architecture.md) et
[issue #6](https://github.com/FXC-ai/hypertube/issues/6)) qui implémente le protocole
BitTorrent from scratch. Zéro dépendance npm pour l'instant.

## Lancer les tests

```bash
cd torrent-client
npm test
```

Utilise le test runner intégré de Node (`node --test`), aucune installation nécessaire
(Node ≥ 20 suffit). Tous les fichiers sous `test/*.test.js` sont exécutés.

## Ce qui est couvert aujourd'hui

| Fichier | Ce qui est testé |
|---|---|
| `test/bencode.test.js` | Décodeur bencode (`src/bencode.js`) : entiers, byte strings, listes, dictionnaires, cas malformés |
| `test/torrentFile.test.js` | Parsing `.torrent` + calcul d'info-hash (`src/torrentFile.js`), contre des fixtures construites à la main |
| `test/fixtures.test.js` | Le parser contre un **vrai** `.torrent` archive.org, info-hash comparé au `btih` publié par archive.org lui-même |
| `test/videoSignature.test.js` | Détection de format conteneur + calcul de signature (`src/videoSignature.js`) sur des buffers synthétiques |
| `test/referenceVideo.test.js` | La signature du fichier vidéo de référence reste synchronisée avec le JSON de comparaison committé |
| `test/trackers/compactPeers.test.js`, `peerId.test.js` | Primitives partagées HTTP/UDP (format de pair compact BEP23, génération de peer id) |
| `test/trackers/httpTracker.test.js` | Annonce HTTP (BEP3) : construction de la query, parsing bencode de la réponse, `failure reason`, erreurs HTTP — `fetch` injecté, pas de réseau réel |
| `test/trackers/udpTracker.test.js` | Annonce UDP (BEP15) : handshake connect+announce contre un **faux tracker UDP local** (`dgram` dans le test), format des paquets vérifié octet par octet, timeout |
| `test/trackers/announce.test.js` | Orchestrateur multi-tracker (`src/trackers/announce.js`) : ordre d'essai, agrégation des échecs, schémas non supportés (`wss://`) ignorés — annonceurs injectés, pas de réseau réel |
| `test/trackers/announce.integration.test.js` | **Réseau réel** : annonce contre `tracker.opentrackr.org` (Sintel, swarm réellement peuplé), contre le tracker HTTP archive.org, fallback multi-tracker, timeouts bornés contre une adresse injoignable |
| `test/peer/handshake.test.js` | Construction/parsing de la poignée de main peer wire (BEP3), y compris la détection de la longueur exacte consommée (un pair envoie souvent son premier message juste après, dans le même paquet TCP) |
| `test/peer/messages.test.js` | Framing des messages peer wire (préfixe de longueur + id), y compris message coupé en deux morceaux TCP puis recollé |
| `test/peer/downloadPiece.test.js` | Téléchargement d'une pièce complète contre un **faux pair TCP local** scriptable : cas nominal, pièce corrompue rejetée et re-téléchargée (pas acceptée silencieusement), pair qui ne débloque jamais, handshake avec mauvais info-hash, port fermé, keep-alives ignorés |
| `test/peer/downloadPiece.integration.test.js` | **Réseau réel** : télécharge une vraie pièce de 128 Ko depuis un vrai pair du swarm Sintel et vérifie son SHA-1. Essaie ~30 pairs en parallèle (`Promise.any`) et garde le premier qui répond — la plupart des pairs annoncés par un tracker sont injoignables à un instant donné (NAT/hors ligne), c'est normal en P2P |
| `test/swarm/downloadTorrent.test.js` | Orchestration multi-pairs (`src/swarm/downloadTorrent.js`) contre des **faux pairs TCP locaux** : plusieurs pairs en parallèle, retry sur un autre pair si l'un échoue, pairs qui refusent la connexion ignorés, échec définitif si personne ne sert jamais une pièce, callback de progression — et surtout **pièce qui chevauche deux fichiers** dans un torrent multi-fichiers |
| `test/swarm/downloadTorrent.integration.test.js` | **Réseau réel, téléchargement complet** : télécharge l'intégralité du torrent Sintel (~129 Mo, 987 pièces, 11 fichiers) via le vrai swarm, vérifie la taille finale et que `Sintel.mp4` est ouvrable par `ffprobe` avec un vrai flux vidéo. ~47s en pratique |
| `test/server/downloadManager.test.js` | Cycle de vie d'un job (`src/server/downloadManager.js`) : succès, échec de parsing/annonce/téléchargement, annulation, id inconnu — dépendances (parse/announce/download) injectées, pas de réseau réel |
| `test/server/httpServer.test.js` | Contrat HTTP (`src/server/httpServer.js`) : codes de statut, routing, décodage `torrentBase64`, JSON invalide — manager injecté (faux), pas de vrai téléchargement |
| `test/server/httpServer.integration.test.js` | **Réseau réel, bout en bout via HTTP** : POST démarre un vrai téléchargement (fetch du `.torrent` Sintel depuis webtorrent.io, annonce tracker réelle), poll jusqu'à observer une vraie progression, DELETE annule, vérifie qu'aucune pièce ne progresse plus ensuite. ~19s en pratique |

## Pour le pipeline encodage/transcodage/streaming

Deux choses ici sont probablement utiles pour ce qui touche à `ConvertMovie` /
`HlsConverter` / la lecture progressive :

- **`src/videoSignature.js`** expose `detectContainerFormat(buffer)` (détection mp4 via
  la box `ftyp`, webm/mkv via l'en-tête EBML) et `computeVideoSignature(buffer)` (taille,
  SHA-256, 64 premiers octets en hex, format). Réutilisable pour valider côté
  conversion qu'un fichier reçu est bien du format attendu avant de le passer à ffmpeg.
- **Constat fait en construisant la fixture** (pertinent pour
  [issue #14](https://github.com/FXC-ai/hypertube/issues/14), le risque `moov atom`/`Cues`
  en fin de fichier) : le fichier de référence actuel
  (`test/fixtures/reference-video/1953_movie_trailers_starting_monday.reference.mp4`) a sa
  box `moov` **avant** `mdat` (layout fast-start) — inspecté via :

  ```bash
  python3 -c "
  data = open('test/fixtures/reference-video/1953_movie_trailers_starting_monday.reference.mp4','rb').read()
  pos = 0
  while pos < len(data) - 8:
      size = int.from_bytes(data[pos:pos+4], 'big')
      kind = data[pos+4:pos+8].decode('ascii', errors='replace')
      print(f'offset={pos} size={size} type={kind}')
      if size in (0, 1): break
      pos += size
  "
  ```

  Ce fichier ne permet donc **pas** de tester le cas à risque (`moov` en fin de fichier,
  lecture impossible tant que le téléchargement n'est pas terminé). Pour valider #14, il
  faudra une fixture différente — un fichier encodé sans `-movflags +faststart` côté
  ffmpeg reproduit facilement ce cas si aucune source réelle n'en fournit un.

## Trackers HTTP et UDP (#8)

`src/trackers/` implémente les deux protocoles, choisis par schéma d'URL dans
`announce()` :

- **HTTP (BEP3)** — `src/trackers/httpTracker.js`. Nécessaire pour archive.org, dont
  les deux trackers (`bt1`/`bt2.archive.org:6969`) sont HTTP uniquement.
- **UDP (BEP15)** — `src/trackers/udpTracker.js`, via `node:dgram`. Nécessaire parce
  que **tous** les trackers publics bien peuplés (ceux des torrents webtorrent.io type
  Sintel) sont UDP ou WebSocket, jamais HTTP — sans UDP, impossible d'obtenir une vraie
  liste de pairs non vide pour valider le client contre un swarm actif.
- Les trackers `wss://`/`ws://` (WebSocket, pour swarms navigateur-à-navigateur) sont
  délibérément ignorés par `announce()` — non pertinents pour un client serveur, déjà
  noté dans `docs/testing-torrent-sources.md`.

Point notable découvert en testant contre archive.org en réel : le tracker répond bien
(bencode valide, `complete`/`incomplete`/`peers`), mais le pair renvoyé est en pratique
notre propre annonce échoée par le tracker, pas un second client — cohérent avec le
swarm P2P quasi vide déjà documenté pour cette source. La vraie récupération de contenu
pour archive.org passera par le web-seeding (#12), pas ce tracker.

## Protocole peer wire (#9)

`src/peer/` implémente la poignée de main (`handshake.js`), le framing des messages
(`messages.js`) et le téléchargement d'une pièce depuis un seul pair
(`downloadPiece.js`, `node:net`) : intéressé → attente d'unchoke → requêtes pipelinées
par blocs de 16 Ko → assemblage → vérification SHA-1 contre le hash de la pièce dans le
`.torrent`. Une pièce dont le hash ne correspond pas n'est jamais acceptée : elle est
effacée et re-demandée au même pair (jusqu'à `maxAttempts`), testé explicitement dans
`downloadPiece.test.js` avec un faux pair qui envoie d'abord des données corrompues puis
correctes.

Point pratique découvert en testant en réel : sur ~130 pairs annoncés par le tracker pour
Sintel, seuls 2 acceptaient une connexion TCP dans un essai typique (les autres : NAT,
hors ligne, ou refusent la connexion). C'est le comportement normal d'un swarm BitTorrent,
pas un problème d'environnement — un vrai client tourne plusieurs tentatives en parallèle
et garde la première qui aboutit, exactement ce que fait
`downloadPiece.integration.test.js` avec `Promise.any()`. Prévoir la même stratégie pour
#10 (assemblage multi-pairs), pas une boucle séquentielle pair par pair.

## Assemblage multi-pairs (#10)

`src/swarm/downloadTorrent.js` orchestre le téléchargement complet : un pool de workers
concurrents (`concurrency`) tire les pièces manquantes d'une file partagée, chacune
demandée à un pair différent (rotation simple sur la liste de candidats). Une pièce qui
échoue est remise en file pour retry contre un autre pair, jusqu'à `maxAttemptsPerPiece` —
au-delà, tout le téléchargement échoue avec `SwarmDownloadError` plutôt que de produire un
fichier silencieusement incomplet.

**Piège réel rencontré en écrivant le test d'intégration** : Sintel est un torrent
**multi-fichiers** (5 sous-titres + `Sintel.mp4` + poster), et la pièce 0 contenait en fait
du texte de sous-titre allemand, pas des octets vidéo — BitTorrent concatène tous les
fichiers d'un torrent dans l'ordre avant de découper en pièces, donc une pièce peut
chevaucher la frontière entre deux fichiers. La première version écrivait tout dans un seul
blob plat ; `ffprobe` ne pouvait évidemment pas y trouver un flux vidéo propre. Corrigé :
`downloadTorrent()` calcule le layout des fichiers (`computeFileLayout`) et, pour chaque
pièce téléchargée, écrit le bon segment dans le ou les fichiers qu'elle recouvre
(`piecesToFileWrites`) — testé explicitement dans `downloadTorrent.test.js` avec un
découpage volontairement pas aligné sur une frontière de pièce.

**Deuxième piège, trouvé par les tests eux-mêmes** : deux workers demandant le handle du
même fichier au même tick pouvaient chacun lancer leur propre `open(path, 'w')` — la
seconde ouverture tronque le fichier et efface ce que la première venait d'écrire. Corrigé
en mémorisant une **promesse** d'ouverture par fichier (pas le handle résolu), pour que les
appels concurrents attendent la même ouverture au lieu d'en déclencher une chacun.

Le téléchargement complet et réel du torrent Sintel (129 Mo, 987 pièces, 11 fichiers) prend
~47s en pratique dans `downloadTorrent.integration.test.js`, `Sintel.mp4` extrait
correctement et validé par `ffprobe`.

## Enveloppe HTTP start/status/cancel (#11)

Le service s'expose en HTTP via `node:http` (`src/server/httpServer.js`, zéro framework) :
lancer `npm start` (ou `node src/index.js`, port `7881` par défaut, override via `PORT`).

| Route | Rôle |
|---|---|
| `POST /downloads` | Démarre un téléchargement en arrière-plan. Corps JSON : `{ "outputDir": "...", "torrentUrl": "https://..." }` (le service télécharge le `.torrent` lui-même) **ou** `{ "outputDir": "...", "torrentBase64": "..." }` (octets du `.torrent` envoyés directement). Répond `202` + `{ "id": "..." }` immédiatement — le parsing, l'annonce tracker et le téléchargement se font après, en tâche de fond |
| `GET /downloads/:id` | `{ "status": "downloading"\|"completed"\|"failed"\|"cancelled", "downloadedBytes", "totalBytes", "piecesCompleted", "totalPieces", "error" }`. `404` si l'id est inconnu |
| `DELETE /downloads/:id` | Déclenche l'arrêt (`AbortController`) et renvoie le statut courant — qui peut encore afficher `downloading` un court instant, le temps que les opérations en vol se terminent ; `GET` ensuite confirme `cancelled`. `404` si l'id est inconnu |

`src/server/downloadManager.js` fait le lien entre les 3 couches déjà construites : parse le
`.torrent` (`torrentFile.js`) → résout les trackers (`flattenTrackerUrls` + `announce()`) →
lance `downloadTorrent()`. Toutes les dépendances réelles (`parseTorrentFileFn`,
`announceFn`, `downloadTorrentFn`, `fetchImpl`) sont injectables, même pattern que le reste
du repo — les tests unitaires du manager et du serveur HTTP n'ouvrent aucune connexion
réseau, seul `httpServer.integration.test.js` le fait, en vrai, de bout en bout.

**Annulation propagée sur toute la chaîne** (nécessaire pour que `DELETE` arrête vraiment
quelque chose) : `downloadPieceFromPeer` (#9) et `downloadTorrent` (#10) n'avaient aucun
mécanisme d'arrêt avant #11 — ajouté a posteriori via un `AbortSignal` standard traversant
les trois couches, avec une nouvelle classe partagée `CancelledError`
(`src/cancelledError.js`) pour que le manager distingue « annulé » de « a échoué pour de
vrai » sans avoir à inspecter des messages d'erreur.

Aucune connexion base de données nulle part dans ce service (exigence de l'issue #6) — il
ne connaît même pas l'existence de SQLite.

## Fixtures

- `test/fixtures/1953_movie_trailers_starting_monday.archive.org.torrent` — vrai `.torrent`
  archive.org, ~3,8 Ko. Item choisi pour sa petite taille (§ testing-torrent-sources.md).
- `test/fixtures/sintel.webtorrent.io.torrent` — vrai `.torrent` Sintel (Blender
  Foundation, webtorrent.io/free-torrents), ~20 Ko. Swarm massivement actif (>100
  seeders observés via `tracker.opentrackr.org`) — utilisé spécifiquement pour prouver
  qu'on récupère une vraie liste de pairs non vide, ce que le fixture archive.org seul
  ne permet pas de garantir.
- `test/fixtures/reference-video/*.reference.mp4` — le `.mp4` listé dans ce torrent,
  téléchargé en HTTP direct (pas via BitTorrent — ça n'existe pas encore côté client).
  Sert de vérité terrain.
- `test/fixtures/reference-video/*.reference.signature.json` — signature de ce fichier
  (`computeVideoSignature`) committée à part. Le but : une fois #10 capable d'assembler
  un fichier complet (pas juste une pièce, ce que fait déjà #9), calculer la signature du
  fichier obtenu par le vrai client torrent et la diff contre ce JSON pour confirmer que
  les deux chemins produisent des octets identiques.

Pour ajouter une nouvelle fixture torrent depuis archive.org :

```bash
curl -s "https://archive.org/metadata/<identifier>" | python3 -c "
import json, sys
d = json.load(sys.stdin)
for f in d['files']:
    if f.get('format') == 'Archive BitTorrent':
        print(f['name'], f['size'])
"
curl -sL -o test/fixtures/<nom>.torrent "https://archive.org/download/<identifier>/<nom>.torrent"
```

## Notes pour la suite

### Tests intéressants à ajouter

- **Fixture MKV/webm** pour couvrir `detectContainerFormat` sur un vrai fichier (aujourd'hui
  testé uniquement sur un buffer synthétique avec l'en-tête EBML).
- **Fixture mp4 sans fast-start** (`moov` après `mdat`) pour #14 — voir constat ci-dessus.
- **`.torrent` multi-fichiers avec sous-dossiers** réel (le fixture actuel a des `path` à un
  seul segment ; le cas multi-segments n'est testé qu'avec des données construites à la
  main dans `torrentFile.test.js`).
- **Maintenant que #10 sait assembler un fichier complet réel** : comparer la signature de
  `Sintel.mp4` obtenu via `downloadTorrent()` à une signature de référence calculée
  indépendamment (même principe que `reference-video/`, jamais fait pour Sintel jusqu'ici
  — seul l'archive.org fixture a une signature JSON committée). C'est la vraie validation
  croisée que `referenceVideo.test.js` n'anticipe encore que pour l'archive.org fixture
  (voir note ci-dessous).
- **Tracker qui répond mais avec un `failure reason` légitime** (mauvais info_hash,
  tracker privé qui refuse) — aujourd'hui `httpTracker.test.js` le couvre en unitaire
  avec une réponse construite à la main, mais pas contre un vrai tracker qui refuse pour
  de vraies raisons.
- **`announce()` avec agrégation de plusieurs vrais pairs** (fusionner les résultats de
  plusieurs trackers au lieu de s'arrêter au premier succès) — utile pour agrandir le pool
  de candidats que `downloadTorrent()` reçoit, plutôt que de dépendre d'un seul tracker.
- **Téléchargement de plusieurs pièces d'affilée depuis le même pair** (réutiliser la
  connexion TCP déjà établie au lieu d'en ouvrir une par pièce) — `downloadPieceFromPeer`
  ferme la connexion après chaque pièce, et `downloadTorrent()` en hérite (une connexion
  par tentative de pièce, même vers le même pair). Fonctionne (47s pour 129 Mo sur le vrai
  swarm Sintel) mais gaspille des handshakes ; une vraie session de pair persistante serait
  plus efficace à plus grande échelle.
- **Peer choke après avoir déjà unchoke** en cours de téléchargement (le code gère l'état
  mais ce n'est testé qu'implicitement) — un faux pair qui unchoke, envoie un bloc, puis
  rechoke avant la fin de la pièce, pour vérifier qu'on arrête proprement les requêtes au
  lieu de continuer à en empiler.
- **Sélection de pièces "rarest first"** — `downloadTorrent()` prend les pièces dans l'ordre
  (0, 1, 2…) et les pairs en rotation simple, pas la stratégie "pièce la plus rare
  d'abord" des vrais clients BitTorrent (qui maximise la disponibilité globale du swarm).
  Suffisant pour un swarm de la taille testée ici, mais à reconsidérer si la volumétrie
  réelle du produit final le justifie.
- **Plusieurs jobs simultanés dans `downloadManager`** — testé aujourd'hui un à la fois ;
  vérifier qu'un `outputDir` par job reste isolé et qu'annuler l'un n'affecte pas les
  autres serait rassurant avant que #13 déclenche plusieurs films en parallèle.
- **Persistance des jobs** — `downloadManager` garde tout en mémoire (`Map`) ; un redémarrage
  du conteneur perd l'état de tout téléchargement en cours. Pas un problème pour #11 en
  tant que tel, mais #13/#14 (Laravel qui interroge `GET /downloads/:id`) devront décider
  quoi faire si le Client Torrent redémarre pendant qu'un film télécharge.
- **`torrentUrl` invalide ou inaccessible** — `httpServer.test.js` couvre le contrat HTTP
  avec des fakes, mais pas le vrai comportement de `fetchImpl` contre une URL qui 404 ou
  timeout (couvert indirectement par `downloadManager.test.js` avec un fake, jamais en
  réel).

### Tests à supprimer/réviser lors des prochaines évolutions

- **`test/referenceVideo.test.js`** : aujourd'hui, ce test compare le fichier vidéo au JSON
  généré *depuis ce même fichier* — c'est surtout un garde-fou anti-corruption de fixture,
  pas encore une vraie validation croisée. #10 rend ce remplacement possible (le client
  sait maintenant télécharger un fichier complet réel) mais ce n'est pas encore fait : le
  test utile serait « signature(fichier téléchargé par le vrai client) == JSON de
  référence », qui rendrait celui-ci redondant.
- **`test/helpers/bencodeEncode.js`** : encodeur bencode écrit uniquement pour construire
  des fixtures de test, séparé exprès de `src/bencode.js` pour ne pas tester le décodeur
  contre lui-même. Si un encodeur bencode de production apparaît un jour dans `src/`
  (par ex. pour construire une requête d'annonce tracker), supprimer ce helper et faire
  pointer les tests dessus à la place — pas deux encodeurs à maintenir en parallèle.
- Les assertions par octets exacts dans `referenceVideo.test.js` (taille/SHA-256/hex en
  dur) sont fragiles si archive.org régénère un jour ce dérivé (l'ADR-0005 et
  `docs/testing-torrent-sources.md` documentent déjà que les torrents archive.org sont
  régénérés). Si ce test casse un jour sans changement de code, régénérer le JSON de
  comparaison plutôt que de chercher un bug.
- **`test/trackers/announce.integration.test.js`** dépend d'infrastructure externe qu'on
  ne contrôle pas (`tracker.opentrackr.org` up, Sintel toujours bien seedé, trackers
  archive.org toujours en HTTP). C'est assumé et voulu pour #8 (l'acceptance criteria
  demande explicitement un vrai tracker), mais si ce fichier devient une source
  d'instabilité en CI, le séparer du run par défaut (`npm test`) plutôt que le supprimer
  — les tests unitaires avec annonceurs/fetch injectés (`httpTracker.test.js`,
  `udpTracker.test.js` avec son faux tracker local, `announce.test.js`) couvrent déjà la
  correction du protocole indépendamment du réseau.
