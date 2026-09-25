# Protocoles et chemin du code - Client Torrent

Ce document explique comment le Client Torrent télécharge un film, protocole par protocole, avec les extraits de code qui les implémentent. Il sert à présenter le code à l'équipe pour la PR. Pour le contrat HTTP, voir [API.md](API.md). Pour les tests, voir [README.md](README.md). Pour la vue d'ensemble et la conception du retry, voir [overview.md](overview.md).

Les extraits sont copiés depuis `src/`, parfois abrégés (le signalé par "abrégé"). Les numéros de ligne changent, les noms de fonctions sont la référence.

Sommaire :

1. [Chemin du code d'un téléchargement](#1-chemin-du-code-dun-téléchargement)
2. [Bencode](#2-bencode)
3. [Trackers](#3-trackers) : rôle, HTTP (BEP3), UDP (BEP15)
4. [Peer wire](#4-peer-wire)
5. [Web-seed (BEP19)](#5-web-seed-bep19)
6. [Limites connues](#6-limites-connues)

## 1. Chemin du code d'un téléchargement

```
curl POST /downloads
  -> server/httpServer.js         handleStart()
  -> server/downloadManager.js    startDownload() puis run() en arrière-plan
  -> torrentFile.js               parseTorrentFile()    (bencode + info-hash)
  -> trackers/announce.js         announce()            (liste de pairs)
  -> swarm/downloadTorrent.js     downloadTorrent()     (pool de workers)
       -> peer/downloadPiece.js               une pièce depuis un pair
       -> webseed/downloadPieceFromWebSeed.js une pièce depuis un serveur HTTP
  -> torrentLayout.js             computeOverlaps()     (quel fichier, quel offset)
  -> disque                       un fichier par entrée du torrent
```

### 1.1 Le routage : une regex pour l'identifiant

```js
const DOWNLOAD_ID_PATTERN = /^\/downloads\/([^/]+)$/;
// ...
const idMatch = req.url?.match(DOWNLOAD_ID_PATTERN);
```

La regex `/^\/downloads\/([^/]+)$/` se lit ainsi :

| Morceau | Sens |
|---|---|
| `^` et `$` | l'URL doit correspondre en entier, du début à la fin |
| `\/downloads\/` | le texte littéral `/downloads/` (les `/` sont échappés dans une regex littérale) |
| `(...)` | groupe de capture : ce qu'il contient devient `idMatch[1]` |
| `[^/]+` | un ou plusieurs caractères qui ne sont pas `/` |

Elle accepte `/downloads/2ce4f502-...` et capture l'identifiant. Elle refuse `/downloads/` (rien à capturer) et `/downloads/a/b` (un `/` de trop). Point à connaître : `req.url` contient aussi la query string, donc `/downloads/abc?x=1` capturerait `abc?x=1` comme identifiant et répondrait 404 "Unknown download id".

### 1.2 Le démarrage : répondre tout de suite, travailler après

```js
// httpServer.js - handleStart (abrégé)
const { torrentUrl, torrentBase64, outputDir } = body ?? {};
let torrentBytes;

if (torrentBase64 !== undefined) {
  torrentBytes = Buffer.from(torrentBase64, 'base64');
}

const id = await manager.startDownload({ torrentBytes, torrentUrl, outputDir });
sendJson(res, 202, { id });
```

Le corps JSON donne soit une URL de `.torrent`, soit le fichier lui-même en base64. Si les deux sont présents, `torrentBase64` gagne. La réponse est `202 Accepted` avec l'identifiant du job.

```js
// downloadManager.js - startDownload (abrégé)
const id = randomUUID();
const job = {
  id, status: 'downloading',
  downloadedBytes: 0, totalBytes: null, piecesCompleted: 0, totalPieces: null,
  error: null, outputDir, controller: new AbortController(),
};
jobs.set(id, job);

run(job, { torrentBytes, torrentUrl }).catch(() => {});

return id;
```

Le job est rangé dans une `Map` en mémoire. `run(...)` est lancé **sans `await`** : la fonction retourne l'identifiant avant que le téléchargement commence. Le `.catch(() => {})` évite qu'une erreur inattendue devienne un rejet de promesse non géré, car `run()` note déjà lui-même l'échec dans `job.status`. L'`AbortController` sert à l'annulation (`DELETE`).

### 1.3 Les sources : pairs et web-seeds dans la même liste

```js
// downloadTorrent.js
const sources = [
  ...peers.map((peer) => ({ kind: 'peer', peer })),
  ...webSeedUrls.map((baseUrl) => ({ kind: 'webseed', baseUrl })),
];
```

Les deux types de sources sont mis dans un seul tableau. Il n'y a pas de logique "d'abord les pairs, sinon HTTP" : chaque worker prend la source suivante en rotation.

```js
// downloadTorrent.js
function nextSource() {
  const source = sources[sourceCursor % sources.length];
  sourceCursor += 1;

  return source;
}
```

Le modulo `%` fait boucler le curseur : après la dernière source, on repart de la première.

### 1.4 Le worker : une pièce, une source, une vérification

```js
// downloadTorrent.js - worker (abrégé)
const pieceIndex = queue.shift();
const source = nextSource();

const buffer =
  source.kind === 'webseed'
    ? await downloadPieceFromWebSeed(source.baseUrl, torrent, fileLayout, pieceIndex, offset, length, { pieceHash, timeoutMs, signal })
    : await downloadPieceFromPeer(source.peer, { infoHash, peerId, pieceIndex, pieceLength: length, pieceHash, /* ... */ signal });
```

`concurrency` vaut 10 par défaut : dix workers tournent en parallèle et tirent leurs pièces dans la même file `queue`. En cas d'échec :

```js
attempts[pieceIndex] += 1;

if (attempts[pieceIndex] >= maxAttemptsPerPiece) {
  failure = new SwarmDownloadError(`Piece ${pieceIndex} failed after ...`);

  return;
}

queue.push(pieceIndex);
```

La pièce est remise en file et sera prise par un worker sur la source suivante. Au-delà de `maxAttemptsPerPiece` (`max(4, nombre de sources * 2)` par défaut), tout le téléchargement échoue, plutôt que de produire un fichier incomplet. Une annulation (`CancelledError`) sort avant, sans compter comme un échec.

### 1.5 L'écriture : une pièce peut chevaucher deux fichiers

BitTorrent met bout à bout tous les fichiers du torrent, puis découpe le flux en pièces de taille fixe. Une pièce peut donc contenir la fin d'un fichier et le début du suivant (la pièce 0 de Sintel contient un sous-titre, pas de la vidéo).

```js
// torrentLayout.js
export function computeOverlaps(fileLayout, rangeStart, rangeLength) {
  const rangeEnd = rangeStart + rangeLength;
  const overlaps = [];

  for (const file of fileLayout) {
    const fileEnd = file.torrentOffset + file.length;
    const overlapStart = Math.max(rangeStart, file.torrentOffset);
    const overlapEnd = Math.min(rangeEnd, fileEnd);

    if (overlapStart >= overlapEnd) {
      continue;
    }

    overlaps.push({
      file,
      fileOffset: overlapStart - file.torrentOffset,
      length: overlapEnd - overlapStart,
      rangeOffset: overlapStart - rangeStart,
    });
  }

  return overlaps;
}
```

Pour chaque fichier, on calcule l'intersection entre la plage demandée et l'étendue du fichier. `Math.max` des débuts et `Math.min` des fins donnent l'intersection. Si elle est vide (`overlapStart >= overlapEnd`), le fichier n'est pas concerné. Sinon on obtient : à quel offset écrire dans le fichier (`fileOffset`), combien d'octets (`length`), et où ils commencent dans la pièce (`rangeOffset`). Cette fonction sert aussi au web-seed (section 5), pour savoir quelles plages demander.

```js
// downloadTorrent.js - après une pièce valide
for (const overlap of computeOverlaps(fileLayout, offset, buffer.length)) {
  const handle = await handleFor(overlap.file);
  const data = buffer.subarray(overlap.rangeOffset, overlap.rangeOffset + overlap.length);
  await handle.write(data, 0, data.length, overlap.fileOffset);
}
```

`handle.write(data, 0, data.length, fileOffset)` écrit à un offset précis, donc les pièces peuvent arriver dans le désordre.

### 1.6 Deux pièges déjà rencontrés

```js
// downloadTorrent.js - handleFor
const fileHandlePromises = new Map();
function handleFor(file) {
  let promise = fileHandlePromises.get(file.path);

  if (!promise) {
    promise = (async () => {
      const fullPath = join(outputDir, file.path);
      await mkdir(dirname(fullPath), { recursive: true });

      return open(fullPath, 'w');
    })();
    fileHandlePromises.set(file.path, promise);
  }

  return promise;
}
```

On mémorise la **promesse** d'ouverture, pas le handle. Sinon, deux workers qui demandent le même fichier au même instant lançaient chacun `open(path, 'w')`, et la seconde ouverture vidait le fichier, effaçant ce que la première venait d'écrire.

```js
// downloadTorrent.js - à la fin
await Promise.all(fileLayout.map((file) => handleFor(file)));
```

Un fichier de taille zéro (archive.org en contient) ne chevauche aucune pièce, donc il n'était jamais créé. Cette ligne ouvre explicitement tous les fichiers du torrent pour qu'ils existent tous sur disque.

## 2. Bencode

Format de sérialisation de BitTorrent, utilisé pour les `.torrent` et les réponses des trackers HTTP. Code : [src/bencode.js](src/bencode.js).

### 2.1 Les 4 types

| Type | Syntaxe | Exemple | Décodé |
|---|---|---|---|
| Entier | `i<nombre>e` | `i42e` | `42` |
| Chaîne d'octets | `<longueur>:<octets>` | `4:spam` | les 4 octets de "spam" |
| Liste | `l<éléments>e` | `l4:spami42ee` | `[spam, 42]` |
| Dictionnaire | `d<clé><valeur>...e` | `d3:bar4:spam3:fooi42ee` | `{bar: spam, foo: 42}` |

Le premier octet donne le type : `i`, `l`, `d`, ou un chiffre pour une chaîne. Une chaîne n'a pas de terminateur, sa longueur dit où elle s'arrête, donc elle peut contenir n'importe quels octets (les hashes SHA-1 des pièces, par exemple).

### 2.2 Le décodeur : une fonction qui renvoie la valeur et la position suivante

```js
export function decodeAt(buffer, pos) {
  const marker = buffer[pos];

  if (marker === INTEGER) { return decodeInteger(buffer, pos); }
  if (marker === LIST) { return decodeList(buffer, pos); }
  if (marker === DICTIONARY) { return decodeDictionary(buffer, pos); }
  if (marker >= 0x30 && marker <= 0x39) { return decodeByteString(buffer, pos); }

  throw new BencodeError(`Unexpected byte 0x${marker.toString(16)}`, pos);
}
```

(abrégé) `INTEGER`, `LIST`, `DICTIONARY` valent les codes ASCII de `i`, `l`, `d` (`0x69`, `0x6c`, `0x64`). `0x30` à `0x39` sont les codes des chiffres `0` à `9`. Chaque fonction renvoie `[valeur, position suivante]`. Une liste ou un dictionnaire rappelle `decodeAt` pour chaque élément jusqu'à trouver le `e` : c'est récursif.

```js
export function decode(buffer) {
  const [value, endPos] = decodeAt(buffer, 0);

  if (endPos !== buffer.length) {
    throw new BencodeError('Trailing data after top-level value', endPos);
  }

  return value;
}
```

`decode` exige que tout le buffer soit consommé, sinon il y a des octets en trop.

### 2.3 Les entiers et la première regex

```js
const raw = buffer.toString('ascii', pos + 1, end);

if (
  !/^-?\d+$/.test(raw) ||
  raw === '-0' ||
  (raw.length > 1 && raw.startsWith('0')) ||
  raw.startsWith('-0')
) {
  throw new BencodeError(`Invalid integer literal "${raw}"`, pos);
}

return [Number(raw), end + 1];
```

`raw` est le texte entre `i` et `e`. La regex `/^-?\d+$/` :

| Morceau | Sens |
|---|---|
| `^` et `$` | tout le texte doit correspondre, sans caractère en trop |
| `-?` | un signe moins, optionnel |
| `\d+` | un ou plusieurs chiffres (`\d` = un chiffre de 0 à 9) |

Elle accepte `42`, `-7`, `0`. Elle refuse `` (vide), `4.5`, `+3`, `12a`.

Les trois conditions suivantes appliquent des règles plus strictes que la regex, car le format doit être canonique (une seule écriture par nombre) :

- `raw === '-0'` : moins zéro est interdit.
- `raw.length > 1 && raw.startsWith('0')` : pas de zéro de tête, donc `03` est refusé mais `0` seul est accepté.
- `raw.startsWith('-0')` : couvre aussi `-03`. Cette condition inclut le cas `-0` de la ligne précédente, qui est donc redondante.

Résultats observés en lançant le décodeur : `i03e` donne `Invalid integer literal "03"`, `i-0e` donne `Invalid integer literal "-0"`, `ie` donne `Invalid integer literal ""`.

### 2.4 Les chaînes et la seconde regex

```js
const colon = buffer.indexOf(COLON, pos);
const lengthRaw = buffer.toString('ascii', pos, colon);

if (!/^\d+$/.test(lengthRaw)) {
  throw new BencodeError(`Invalid byte string length "${lengthRaw}"`, pos);
}

const length = Number(lengthRaw);
const start = colon + 1;
const end = start + length;

if (end > buffer.length) {
  throw new BencodeError('Byte string length exceeds remaining buffer', pos);
}

return [buffer.subarray(start, end), end];
```

On cherche les deux-points, le texte avant est la longueur. `/^\d+$/` : un ou plusieurs chiffres, et rien d'autre. Elle refuse une longueur négative (`-4:spam`) ou vide (`:spam`). Le contrôle `end > buffer.length` évite de lire au-delà du fichier si la longueur annoncée est fausse (`4:ab` donne `Byte string length exceeds remaining buffer`). On renvoie un `subarray`, une vue sur le même buffer, sans copie.

### 2.5 Pourquoi des `Map` et des `Buffer`

```js
const dict = new Map();
// ...
dict.set(keyBuf.toString('utf8'), value);
```

Deux choix volontaires :

- **Les dictionnaires sont des `Map`, pas des objets.** Un `.torrent` est une entrée non fiable. Une clé `__proto__` dans un objet JS classique pollue `Object.prototype` pour tout le programme. Une `Map` n'a pas ce risque.
- **Les chaînes restent des `Buffer`.** Les hashes de pièces ne sont pas de l'UTF-8. On convertit en texte seulement quand on sait que c'en est (clés, nom du fichier).

### 2.6 Un exemple exécuté

Entrée (sans retours à la ligne dans le vrai fichier) :

```
d8:announce26:udp://tracker.example:13374:infod6:lengthi3020385e4:name8:film.mp4
12:piece lengthi262144e6:pieces20:AAAAAAAAAAAAAAAAAAAAee
```

Sortie de `decode()` (les chaînes s'affichent en octets) :

```
Map { 'announce' => <26 octets>,
      'info' => Map { 'length' => 3020385, 'name' => <8 octets: film.mp4>,
                      'piece length' => 262144, 'pieces' => <20 octets> } }
```

### 2.7 L'info-hash : pourquoi on garde les octets bruts

L'info-hash identifie le torrent auprès des trackers et des pairs. C'est le SHA-1 des octets **exacts** du dictionnaire `info`, tels qu'ils sont dans le fichier :

```js
// torrentFile.js - decodeTopLevelCapturingInfo (abrégé)
const valueStart = afterKey;
const [value, afterValue] = decodeAt(buffer, afterKey);

if (key === 'info') {
  infoRaw = buffer.subarray(valueStart, afterValue);
}
```

```js
// torrentFile.js - parseTorrentFile
infoHash: createHash('sha1').update(infoRaw).digest('hex'),
```

Pendant le décodage, on garde la tranche d'octets de la clé `info`, puis on la hache. On ne peut pas décoder puis ré-encoder `info` : le moindre octet différent (l'ordre des clés, par exemple) changerait le hash, et le tracker ne reconnaîtrait pas le torrent. C'est pourquoi `src/` n'a pas d'encodeur bencode. Seul `test/helpers/bencodeEncode.js` en a un, pour fabriquer des réponses de tracker dans les tests.

## 3. Trackers

### 3.1 Le rôle d'un tracker

Le `.torrent` contient l'info-hash, les pièces et leurs SHA-1, mais pas l'adresse de ceux qui possèdent ces pièces. Un tracker répond à la question "qui a ce torrent ?" : le client envoie son info-hash, le tracker renvoie une liste d'adresses `ip:port`. Le tracker ne transporte jamais le contenu du fichier, seulement des adresses. Le client se connecte ensuite directement aux pairs (section 4).

Notre client n'implémente ni la DHT ni le PEX, donc le tracker est son seul moyen d'obtenir des pairs. Le web-seed (section 5) est la seule source qui n'en a pas besoin.

```js
// trackers/announce.js (abrégé)
for (const trackerUrl of dedupe(trackerUrls)) {
  const announcer = pickAnnouncer(trackerUrl, options);

  if (!announcer) {
    errors.push(new TrackerError('Unsupported tracker scheme ...', { trackerUrl }));
    continue;
  }

  try {
    return await announcer(trackerUrl, params, options);
  } catch (err) {
    errors.push(err);
  }
}

throw new TrackerError(`All ${errors.length} tracker(s) failed: ...`);
```

```js
function pickAnnouncer(trackerUrl, options) {
  const scheme = trackerUrl.split(':', 1)[0];

  if (scheme === 'http' || scheme === 'https') { return announceHttpTracker; }
  if (scheme === 'udp') { return announceUdpTracker; }

  return null;
}
```

`announce()` essaie les trackers un par un, dans l'ordre (sans doublons), et renvoie le premier succès. Un tracker qui plante, qui expire ou qui a un schéma non géré (`wss://` est réservé aux clients navigateur) est simplement sauté. Le schéma de l'URL, lu avec `split(':', 1)[0]` (la partie avant le premier `:`), choisit HTTP ou UDP. Le tout ne lève une erreur que si tous les trackers ont échoué. Le tracker de la liste `announce` puis ceux de `announce-list` sont rassemblés par `flattenTrackerUrls()`.

| | HTTP (BEP3) | UDP (BEP15) |
|---|---|---|
| Transport | GET, réponse bencode | paquets binaires de taille fixe |
| Étapes | 1 requête | connect puis announce |
| Trackers concernés ici | archive.org (`bt1`/`bt2.archive.org:6969`) | trackers publics de Sintel |

### 3.2 Tracker HTTP (BEP3)

Code : [src/trackers/httpTracker.js](src/trackers/httpTracker.js). Une requête GET avec les paramètres dans l'URL, une réponse en bencode.

```js
// buildAnnounceUrl (abrégé)
const query = [
  ['info_hash', percentEncodeBytes(params.infoHash)],
  ['peer_id', percentEncodeBytes(params.peerId)],
  ['port', String(params.port)],
  ['uploaded', String(params.uploaded ?? 0)],
  ['downloaded', String(params.downloaded ?? 0)],
  ['left', String(params.left)],
  ['compact', '1'],
];

if (params.event) {
  query.push(['event', params.event]);
}
```

Résultat : `GET <announce>?info_hash=%08%ad...&peer_id=...&port=6881&uploaded=0&downloaded=0&left=<octets restants>&compact=1&event=started`.

| Paramètre | Rôle |
|---|---|
| `info_hash`, `peer_id` | 20 octets chacun : le torrent, et notre client |
| `port` | le port sur lequel on dit écouter |
| `left` | octets restants à télécharger |
| `compact=1` | demande les pairs en binaire compact (6 octets par pair) |
| `event` | `started`, `completed` ou `stopped` |

```js
function percentEncodeBytes(buffer) {
  let out = '';

  for (const byte of buffer) {
    out += '%' + byte.toString(16).padStart(2, '0');
  }

  return out;
}
```

`info_hash` et `peer_id` sont des octets bruts, pas du texte. Chaque octet est écrit en `%xx` : `toString(16)` donne l'hexadécimal, `padStart(2, '0')` ajoute un zéro devant pour avoir toujours deux chiffres (`0x08` devient `%08`, pas `%8`). On échappe même les lettres et les chiffres, pour ne pas avoir à distinguer les octets "sûrs" des autres.

```js
// réponse (abrégé)
const parsed = decode(body);

if (parsed.has('failure reason')) {
  throw new TrackerError(`Tracker refused the request: ${parsed.get('failure reason').toString('utf8')}`);
}

const peersValue = parsed.get('peers');
const peers = Buffer.isBuffer(peersValue)
  ? parseCompactPeers(peersValue)
  : parseNonCompactPeers(peersValue);

return {
  interval: parsed.get('interval'),
  seeders: parsed.get('complete'),
  leechers: parsed.get('incomplete'),
  peers,
};
```

La réponse est un dictionnaire bencode décodé par notre `decode()`. `failure reason` signale un refus. `peers` est soit une chaîne binaire compacte (si `compact=1` est accepté), soit une liste de dictionnaires `{ip, port}` (ancien format). `complete` compte les seeders, `incomplete` les leechers, `interval` donne le délai avant la prochaine annonce.

```js
// trackers/compactPeers.js
export function parseCompactPeers(buffer) {
  if (buffer.length % 6 !== 0) {
    throw new RangeError('Compact peers buffer length must be a multiple of 6');
  }

  const peers = [];

  for (let i = 0; i < buffer.length; i += 6) {
    const ip = `${buffer[i]}.${buffer[i + 1]}.${buffer[i + 2]}.${buffer[i + 3]}`;
    const port = buffer.readUInt16BE(i + 4);
    peers.push({ ip, port });
  }

  return peers;
}
```

Le format compact (BEP23) : 6 octets par pair, 4 pour l'IPv4 (un octet par nombre de l'adresse) et 2 pour le port en gros-boutiste (`readUInt16BE`, l'octet de poids fort d'abord). Les deux trackers, HTTP et UDP, utilisent ce même format.

Garde-fous : délai de 10 s (`AbortSignal.timeout`), et un statut HTTP non 2xx, du bencode invalide ou une réponse qui n'est pas un dictionnaire deviennent tous des `TrackerError`.

### 3.3 Tracker UDP (BEP15)

Code : [src/trackers/udpTracker.js](src/trackers/udpTracker.js), avec `node:dgram`. Le protocole est binaire et se fait en deux échanges. UDP n'a pas de connexion, donc n'importe qui peut usurper une adresse source. Le tracker impose une première étape où il donne un `connection_id` que seul le vrai destinataire reçoit, à renvoyer dans la vraie requête.

Échange 1, `connect` (16 octets) :

```js
const request = Buffer.alloc(16);
request.writeBigUInt64BE(PROTOCOL_ID, 0);   // 0x41727101980, constante magique du protocole
request.writeUInt32BE(ACTION_CONNECT, 8);   // 0
request.writeUInt32BE(transactionId, 12);   // 4 octets aléatoires
```

Le tracker répond avec `action` (0), le même `transaction_id`, puis le `connection_id` sur 8 octets (`response.readBigUInt64BE(8)`). `writeBigUInt64BE` écrit un entier de 64 bits ; `BE` signifie gros-boutiste, l'ordre réseau.

Échange 2, `announce` (98 octets) :

```js
const request = Buffer.alloc(98);
request.writeBigUInt64BE(connectionId, 0);
request.writeUInt32BE(ACTION_ANNOUNCE, 8);           // 1
request.writeUInt32BE(transactionId, 12);
params.infoHash.copy(request, 16);                   // 20 octets
params.peerId.copy(request, 36);                     // 20 octets
request.writeBigUInt64BE(BigInt(params.downloaded ?? 0), 56);
request.writeBigUInt64BE(BigInt(params.left), 64);
request.writeBigUInt64BE(BigInt(params.uploaded ?? 0), 72);
request.writeUInt32BE(EVENT_CODES[params.event ?? 'none'], 80);
request.writeUInt32BE(0, 84);                        // ip = 0 : le tracker utilise l'adresse source
request.writeUInt32BE(randomTransactionId(), 88);    // key
request.writeInt32BE(-1, 92);                        // num_want = -1 : valeur par défaut du tracker
request.writeUInt16BE(params.port, 96);
```

| Octets | Champ |
|---|---|
| 0-7 | `connection_id` de l'échange 1 |
| 8-11 | `action` (1 = announce) |
| 12-15 | `transaction_id` |
| 16-35 | info-hash |
| 36-55 | `peer_id` |
| 56-79 | `downloaded`, `left`, `uploaded` (8 octets chacun) |
| 80-83 | `event` (0 rien, 1 completed, 2 started, 3 stopped) |
| 84-87 | `ip` (0 = adresse source du paquet) |
| 88-91 | `key` aléatoire |
| 92-95 | `num_want` (-1 = défaut) |
| 96-97 | port |

La réponse contient `action` (1), le `transaction_id`, `interval`, `leechers`, `seeders` (4 octets chacun, aux offsets 8, 12 et 16), puis la liste compacte de pairs à partir de l'octet 20, lue par `parseCompactPeers`.

```js
// sendAndReceive (abrégé)
function handleMessage(msg) {
  result = onMessage(msg);

  if (result === undefined) {
    return;    // pas notre réponse : on continue d'écouter
  }

  cleanup();
  resolve(result);
}
```

Le `transaction_id` sert à reconnaître la bonne réponse : un paquet trop court, ou avec un autre `transaction_id`, est ignoré (`onMessage` renvoie `undefined`) et on continue d'attendre. Si `action` vaut 3, le reste du paquet est un message d'erreur en texte. Un timeout de 10 s par étape évite qu'un tracker muet bloque le client.

## 4. Peer wire

Le protocole que deux clients parlent en TCP direct, une fois l'adresse du pair connue. Code : [src/peer/](src/peer/) (`handshake.js`, `messages.js`, `downloadPiece.js`).

### 4.1 La poignée de main : 68 octets

```js
export function buildHandshake(infoHash, peerId, { reserved = Buffer.alloc(8) } = {}) {
  return Buffer.concat([
    Buffer.from([PROTOCOL_ID.length]),        // 1 octet : 19
    Buffer.from(PROTOCOL_ID, 'ascii'),        // "BitTorrent protocol"
    reserved,                                 // 8 octets, extensions (zéros chez nous)
    infoHash,                                 // 20 octets
    peerId,                                   // 20 octets
  ]);
}
```

1 + 19 + 8 + 20 + 20 = 68 octets. Le pair répond avec la même structure. Si son info-hash diffère du nôtre, on abandonne : ce pair ne partage pas notre torrent.

### 4.2 Le cadrage des messages

Après la poignée de main, chaque message est `[longueur sur 4 octets][id sur 1 octet][charge]`. Une longueur de 0 est un keep-alive, sans id.

```js
export function encodeMessage(id, payload = Buffer.alloc(0)) {
  const length = 1 + payload.length;
  const buf = Buffer.alloc(4 + length);
  buf.writeUInt32BE(length, 0);
  buf.writeUInt8(id, 4);
  payload.copy(buf, 5);

  return buf;
}
```

La longueur compte l'octet d'id plus la charge. Voici les messages que le protocole définit, et ce que notre code en fait :

| id | Message | Rôle | Dans notre code |
|---|---|---|---|
| 0 / 1 | choke / unchoke | le pair refuse / accepte de nous servir | reçu et traité |
| 2 | interested | on dit qu'on veut des pièces | envoyé |
| 4 | have | le pair annonce une pièce | ignoré |
| 5 | bitfield | la liste de toutes ses pièces | ignoré |
| 6 | request | on demande un bloc (pièce, offset, longueur) | envoyé |
| 7 | piece | le pair renvoie le bloc | reçu et traité |

```js
export function encodeRequest(index, begin, length) {
  const payload = Buffer.alloc(12);
  payload.writeUInt32BE(index, 0);    // numéro de la pièce
  payload.writeUInt32BE(begin, 4);    // offset dans la pièce
  payload.writeUInt32BE(length, 8);   // taille du bloc (16384 chez nous)

  return encodeMessage(MESSAGE_ID.REQUEST, payload);
}
```

### 4.3 TCP n'a pas de frontières de message

Un même paquet peut contenir plusieurs messages, ou un message coupé en deux.

```js
export function extractMessages(buffer) {
  const messages = [];
  let offset = 0;

  while (offset + 4 <= buffer.length) {
    const length = buffer.readUInt32BE(offset);

    if (length === 0) {
      messages.push({ id: KEEP_ALIVE, payload: Buffer.alloc(0) });
      offset += 4;
      continue;
    }

    if (offset + 4 + length > buffer.length) {
      break;                       // message incomplet : on attend la suite
    }

    const id = buffer.readUInt8(offset + 4);
    const payload = buffer.subarray(offset + 5, offset + 4 + length);
    messages.push({ id, payload });
    offset += 4 + length;
  }

  return { messages, remaining: buffer.subarray(offset) };
}
```

On lit la longueur, on vérifie que le message est complet, sinon on s'arrête. `remaining` contient le morceau incomplet, à recoller au prochain paquet reçu (`buffer = remaining` dans le gestionnaire `data`). Un pair envoie souvent son premier message (bitfield) dans le même paquet que la poignée de main : c'est pour ça qu'on coupe exactement `parsed.length` octets après la poignée de main.

### 4.4 Le déroulé pour une pièce

`downloadPieceFromPeer()` ([downloadPiece.js](src/peer/downloadPiece.js)) :

1. Connexion TCP, envoi de la poignée de main.
2. Vérification de l'info-hash reçu, puis envoi de `interested`.
3. Attente d'un `unchoke`. Un pair "choked" ne sert personne.
4. Envoi de `request`, un par bloc de 16 Ko (`BLOCK_SIZE = 16384`), avec au plus 5 requêtes en vol (`MAX_PIPELINED_REQUESTS`) pour ne pas attendre chaque réponse avant de demander la suivante.
5. Chaque `piece` reçu est rangé dans une `Map` selon son offset. Quand tous les blocs sont là, la pièce est reconstruite.
6. Vérification du SHA-1 contre celui du `.torrent`. Un hash faux n'est jamais accepté : la pièce est redemandée, jusqu'à `maxAttempts` (2 par défaut).

```js
if (message.id === MESSAGE_ID.UNCHOKE) {
  unchoked = true;
  requestMore();
} else if (message.id === MESSAGE_ID.CHOKE) {
  unchoked = false;
} else if (message.id === MESSAGE_ID.PIECE) {
  const { index, begin, block } = parsePiece(message.payload);
  // ...
}
```

Un `choke` en cours de route arrête les requêtes, le prochain `unchoke` les relance. Les délais sont de 5 s pour se connecter et 20 s en tout par pièce.

### 4.5 Un échange réel avec le swarm Sintel

Sortie d'un script jetable qui réutilise `handshake.js` et `messages.js`, exécuté contre le vrai swarm Sintel (le tracker a renvoyé 134 pairs, 80 essayés en parallèle, un seul a accepté) :

```
<- HANDSHAKE proto="BitTorrent protocol" peerId=-qB5100- reserved=0000000000180005
-> INTERESTED
<- BITFIELD [124 octets = 992 bits, 987 pièces dispo]
<- UNCHOKE
-> REQUEST piece 0, offset 0, longueur 16384
<- CHOKE
<- UNCHOKE
<- PIECE [16392 octets] (piece 0, offset 0, 16384 octets de bloc)
```

Lecture : le pair est un qBittorrent 5.1.0. Son bitfield de 124 octets a 987 bits à 1, donc il possède les 987 pièces. Il nous coupe puis nous rouvre en quelques dizaines de millisecondes (comportement normal des clients qui renégocient leurs slots). Le message `piece` fait 16 392 octets : 8 d'en-tête (index et offset) plus les 16 384 du bloc. Le bloc est du texte de sous-titre, car la pièce 0 de Sintel tombe dans un `.srt`. Ce script ne vérifiait qu'un bloc sur les 8 d'une pièce de 131 072 octets, donc pas le SHA-1 de la pièce entière : cette vérification est faite par `downloadPieceFromPeer` et le test d'intégration `downloadPiece.integration.test.js`.

### 4.6 Un identifiant de client

```js
const CLIENT_PREFIX = '-HT0001-';

export function generatePeerId() {
  return Buffer.concat([
    Buffer.from(CLIENT_PREFIX, 'ascii'),
    randomBytes(20 - CLIENT_PREFIX.length),
  ]);
}
```

Le `peer_id` fait 20 octets. Le préfixe `-HT0001-` (style Azureus : `HT` pour Hypertube, version `0001`) permet aux autres clients de nous identifier, comme `-qB5100-` désignait qBittorrent 5.1.0 ci-dessus. Les 12 octets restants sont aléatoires.

## 5. Web-seed (BEP19)

Le web-seeding permet de télécharger un torrent sans aucun pair, depuis un serveur HTTP qui héberge déjà les fichiers, avec des requêtes `Range`. Pas de handshake, pas de `choke`. C'est le chemin principal pour archive.org, dont le swarm P2P est quasi vide. Code : [src/webseed/downloadPieceFromWebSeed.js](src/webseed/downloadPieceFromWebSeed.js).

### 5.1 D'où vient l'adresse

Le `.torrent` peut contenir une clé `url-list`, une URL de base (chaîne) ou une liste d'URL. `parseUrlList()` ([torrentFile.js](src/torrentFile.js)) accepte les deux formes et expose `torrent.urlList` (tableau vide si absent). Aucun tracker n'est nécessaire.

### 5.2 L'URL d'un fichier

```js
function buildFileUrl(baseUrl, torrentName, filePath) {
  const normalizedBase = baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`;
  const segments = [torrentName, ...filePath.split('/')].map(encodeURIComponent);

  return normalizedBase + segments.join('/');
}
```

Pour un torrent multi-fichiers, l'URL est `<url-list>/<nom du torrent>/<chemin du fichier>` (convention d'archive.org et de BEP19, vérifiée contre leur vrai serveur). On garantit un `/` à la fin de la base, puis on encode chaque segment avec `encodeURIComponent` (les espaces, les accents et les caractères spéciaux d'un nom de fichier). Le chemin est découpé sur `/` pour encoder chaque dossier séparément sans encoder les `/` eux-mêmes.

### 5.3 Une pièce devient une ou plusieurs requêtes Range

```js
const overlaps = computeOverlaps(fileLayout, pieceOffset, pieceLength);

await Promise.all(
  overlaps.map(async (overlap, i) => {
    const url = buildFileUrl(baseUrl, torrent.name, overlap.file.path);
    const rangeStart = overlap.fileOffset;
    const rangeEnd = overlap.fileOffset + overlap.length - 1;

    const response = await fetchImpl(url, {
      headers: { Range: `bytes=${rangeStart}-${rangeEnd}` },
      signal: requestSignal,
    });
    // ...
    chunks[i] = buffer;
  }),
);
```

`computeOverlaps` (section 1.5) dit quels fichiers la pièce touche. On envoie **une requête GET par fichier**, en parallèle. La fin de l'en-tête `Range` est **incluse** : pour lire `n` octets à partir de `start`, on demande `bytes=start-(start+n-1)`, d'où le `- 1`. `chunks[i]` garde l'ordre des fichiers même si les réponses arrivent dans le désordre.

```js
if (response.status !== 206 && response.status !== 200) {
  throw new WebSeedError(`Web-seed responded with status ${response.status} for ${url}`);
}

const buffer = Buffer.from(await response.arrayBuffer());

if (buffer.length !== overlap.length) {
  throw new WebSeedError(`Web-seed returned ${buffer.length} bytes, expected ${overlap.length} ...`);
}
```

Le serveur doit répondre `206 Partial Content` (ou `200`). On vérifie aussi que le nombre d'octets reçus est exactement celui demandé.

```js
const pieceBuffer = Buffer.concat(chunks);

if (pieceHash) {
  const actualHash = createHash('sha1').update(pieceBuffer).digest('hex');

  if (actualHash !== pieceHash) {
    throw new WebSeedError(`Piece ${pieceIndex} hash mismatch via web-seed ...`);
  }
}
```

Les morceaux sont recollés dans l'ordre, puis le SHA-1 de la pièce est vérifié comme pour un pair. Un serveur web défaillant ou compromis ne peut donc pas nous faire écrire de faux contenu.

```js
const requestSignal = signal
  ? AbortSignal.any([signal, AbortSignal.timeout(timeoutMs)])
  : AbortSignal.timeout(timeoutMs);
```

`AbortSignal.any` combine l'annulation venue du `DELETE` et un délai maximum : la requête s'arrête dès que l'une des deux se déclenche.

### 5.4 Résultat mesuré

Sur l'item archive.org (12 fichiers dont des fichiers vides), le téléchargement complet via web-seed seul, sans aucun pair, a pris environ 3,4 s. La signature du `.mp4` obtenu est identique à celle du fichier de référence committé dans les fixtures.

## 6. Limites connues

- **Peer wire** : une connexion TCP par pièce (pas de session persistante), pas d'envoi de pièces aux autres (le client télécharge seulement), `bitfield` et `have` ignorés.
- **Trackers** : une seule annonce au démarrage, jamais de ré-annonce ni de `completed` ou `stopped` à la fin. UDP sans retransmission (BEP15 en recommande) et sans réutilisation du `connection_id`. IPv4 seulement, pas de `peers6`.
- **Web-seed** : le cas d'un torrent à fichier unique avec une URL de base pointant directement sur le fichier n'est pas géré. Plusieurs miroirs `url-list` sont juste des sources de plus dans la rotation, le cas d'un miroir mort parmi d'autres vivants n'est pas testé.
- **Regex de route** : `/downloads/abc?x=1` est lu comme l'identifiant `abc?x=1` (404).

Le reste des pistes d'amélioration est dans [README.md](README.md#notes-pour-la-suite).
