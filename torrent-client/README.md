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

## Fixtures

- `test/fixtures/1953_movie_trailers_starting_monday.archive.org.torrent` — vrai `.torrent`
  archive.org, ~3,8 Ko. Item choisi pour sa petite taille (§ testing-torrent-sources.md).
- `test/fixtures/reference-video/*.reference.mp4` — le `.mp4` listé dans ce torrent,
  téléchargé en HTTP direct (pas via BitTorrent — ça n'existe pas encore côté client).
  Sert de vérité terrain.
- `test/fixtures/reference-video/*.reference.signature.json` — signature de ce fichier
  (`computeVideoSignature`) committée à part. Le but : une fois #9/#10 capables de
  télécharger via peer-wire, calculer la signature du fichier obtenu par le vrai client
  torrent et la diff contre ce JSON pour confirmer que les deux chemins produisent des
  octets identiques.

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
- Une fois #9 posé (vérification de hash par pièce), un test d'intégration qui compare la
  signature du fichier assemblé par le client torrent réel au JSON de
  `reference-video/` — c'est la vraie validation croisée que `referenceVideo.test.js` ne
  fait qu'anticiper pour l'instant (voir note ci-dessous).

### Tests à supprimer/réviser lors des prochaines évolutions

- **`test/referenceVideo.test.js`** : aujourd'hui, ce test compare le fichier vidéo au JSON
  généré *depuis ce même fichier* — c'est surtout un garde-fou anti-corruption de fixture,
  pas encore une vraie validation croisée. À remplacer (pas juste compléter) une fois que
  #9/#10 permettent de télécharger le même fichier via BitTorrent : à ce moment, le test
  utile est « signature(fichier téléchargé par le vrai client) == JSON de référence », et
  celui-ci devient redondant.
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
