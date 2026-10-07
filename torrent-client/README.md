# Client Torrent - Guide de test

Service Node.js séparé (voir [docs/architecture.md](../docs/architecture.md)) entre Laravel et **Transmission** ([ADR-0009](../docs/adr/0009-transmission-as-torrent-engine.md)). Transmission télécharge (trackers, pairs, web-seeds, vérification des pièces, écriture sur disque) ; ce service garde l'API HTTP de Laravel ([API.md](API.md)) :

- `POST /torrents/inspect` : lit le `.torrent` lui-même et propose les fichiers à télécharger ;
- `POST /downloads` : confie le `.torrent` à Transmission dans `outputDir`, fichiers non choisis exclus, téléchargement séquentiel ;
- `GET /downloads/:id` : progression déduite du bitfield des pièces vérifiées que donne Transmission ;
- `GET /downloads/:id/files/:index` : sert un fichier pendant son téléchargement (HTTP `Range`), uniquement les octets des pièces vérifiées ([ADR-0007](../docs/adr/0007-stream-partial-files-over-http.md)) ;
- à la fin, chaque fichier choisi reçoit un lien physique à plat dans `outputDir` (`movies/{id}/{fileName}`), Transmission gardant ses dossiers.

Zéro dépendance npm. Le code BitTorrent écrit de zéro vit sur la branche `feature/torrent-performance`.

## Lancer les tests

```bash
cd torrent-client
npm run test:unit
```

Test runner intégré de Node (`node --test`), Node ≥ 20, sans réseau ni Transmission (client RPC et Transmission remplacés par des faux).

| Fichier | Ce qui est testé |
|---|---|
| `test/fileSelection.test.js` | Type et conteneur déduits de l'extension, suggestion (plus grosse vidéo + sous-titres non vides, ou tout si aucune vidéo), validation de `fileIndexes` |
| `test/fixtures.test.js` | Le parser (`src/torrentFile.js`) contre un **vrai** `.torrent` archive.org, info-hash comparé au `btih` publié par archive.org |
| `test/torrentLayout.test.js` | `computeOverlaps` (plage à cheval sur deux fichiers), `computeWantedPieces` (pièces de bord comprises), `computeFileNames` (noms à plat, doublons numérotés, `..` neutralisé) |
| `test/transmission/transmissionClient.test.js` | Poignée de main `409` / `X-Transmission-Session-Id`, corps exacts de `torrent-add` et `torrent-set`, erreurs explicites (RPC en échec, `403` whitelist, démon injoignable), décodage du bitfield |
| `test/server/downloadManager.test.js` | Ajout à l'arrêt, sélection, démarrage, progression par fichier, liens à plat à la fin, reprise d'un torrent déjà connu, refus d'un autre dossier, annulation et blocage qui retirent le torrent de Transmission, erreur locale, index hors bornes et infohash changé |
| `test/stream/streamEndpoint.test.js` | `Range` (trois formes, `416`), octets manquants attendus puis servis, jamais un octet non vérifié, `503` + `Retry-After`, `410` quand le job est fini, `404`, carte des pièces à cheval sur deux fichiers |
| `test/server/httpServer.test.js` | Sans réseau : `POST /torrents/inspect` sur le vrai `.torrent` Sintel, `400`/`502`, `GET /` sert la page de test |

Vérifié à la main dans Docker (08/10) : bandes-annonces 1953 terminées en 12 s, fichier lu par le streaming pendant le téléchargement identique (SHA-1) à la référence. Limite connue : *M* (1931) bloque sur ses pièces 0 et 1 (voir l'ADR-0009).

## Docker

```bash
docker compose up -d --build transmission client-torrent
```

- `transmission` : `docker/transmission/` (image linuxserver 4.1.3 + `write-settings.sh` qui force à chaque démarrage : pas de dossier temporaire, pas de suffixe `.part`, pas de file d'attente). RPC sans mot de passe pour les réseaux privés, publié sur `127.0.0.1:9091` seulement (interface web pour déboguer). Port BitTorrent `51413`.
- `client-torrent` : `TRANSMISSION_URL=http://transmission:9091`, port `7881`, page de test sur `http://localhost:7881/`.
- Les trois conteneurs (`app`, `client-torrent`, `transmission`) montent `./storage` au même chemin, `/var/www/html/storage` : un `outputDir` désigne le même dossier partout.

## Pour le pipeline encodage/transcodage/streaming

Deux choses ici sont probablement utiles pour ce qui touche à `ConvertMovie` / `HlsConverter` / la lecture progressive :

- **`src/videoSignature.js`** expose `detectContainerFormat(buffer)` (détection mp4 via la box `ftyp`, webm/mkv via l'en-tête EBML) et `computeVideoSignature(buffer)` (taille, SHA-256, 64 premiers octets en hex, format). Réutilisable pour valider côté conversion qu'un fichier reçu est bien du format attendu avant de le passer à ffmpeg.
- **Constat fait en construisant la fixture** (pertinent pour [issue #14](https://github.com/FXC-ai/hypertube/issues/14), le risque `moov atom`/`Cues` en fin de fichier) : le fichier de référence actuel (`test/fixtures/reference-video/1953_movie_trailers_starting_monday.reference.mp4`) a sa box `moov` **avant** `mdat` (layout fast-start) - inspecté via :

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

  Ce fichier ne permet donc **pas** de tester le cas à risque (`moov` en fin de fichier, lecture impossible tant que le téléchargement n'est pas terminé). Pour valider #14, il faudra une fixture différente - un fichier encodé sans `-movflags +faststart` côté ffmpeg reproduit facilement ce cas si aucune source réelle n'en fournit un.

## Fixtures

- `test/fixtures/1953_movie_trailers_starting_monday.archive.org.torrent` - vrai `.torrent` archive.org, ~3,8 Ko. Item choisi pour sa petite taille (§ testing-torrent-sources.md).
- `test/fixtures/sintel.webtorrent.io.torrent` - vrai `.torrent` Sintel (Blender Foundation, webtorrent.io/free-torrents), ~20 Ko. Swarm massivement actif (>100 seeders observés via `tracker.opentrackr.org`) - utilisé spécifiquement pour prouver qu'on récupère une vraie liste de pairs non vide, ce que le fixture archive.org seul ne permet pas de garantir.
- `test/fixtures/reference-video/*.reference.mp4` - le `.mp4` listé dans ce torrent, téléchargé en HTTP direct (pas via BitTorrent - ça n'existe pas encore côté client). Sert de vérité terrain.
- `test/fixtures/reference-video/*.reference.signature.json` - signature de ce fichier (`computeVideoSignature`) committée à part. Le but : une fois #10 capable d'assembler un fichier complet (pas juste une pièce, ce que fait déjà #9), calculer la signature du fichier obtenu par le vrai client torrent et la diff contre ce JSON pour confirmer que les deux chemins produisent des octets identiques.

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
