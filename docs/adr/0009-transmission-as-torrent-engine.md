# Transmission télécharge, le Client Torrent garde l'API et fabrique le flux

**Statut : proposé, alternative à `feature/torrent-performance`, à trancher en équipe.** Ne change pas le contrat HTTP du Client Torrent ([torrent-client/API.md](../../torrent-client/API.md)) ni les décisions [ADR-0007](0007-stream-partial-files-over-http.md) et [ADR-0008](0008-client-state-in-memory-with-disk-recheck.md).

Le service `client-torrent` garde ses routes (`POST /torrents/inspect`, `POST`/`GET`/`DELETE /downloads`, `GET /downloads/:id/files/:index`), mais ne parle plus BitTorrent lui-même : il confie le `.torrent` à un démon **Transmission 4.1** (service `transmission`, image `lscr.io/linuxserver/transmission`) via son API RPC. Laravel ne voit aucune différence.

## Contexte

FX a proposé le 05/10 sur [#30](https://github.com/FXC-ai/hypertube/pull/30) d'abandonner notre client torrent écrit de zéro : le sujet ne l'exige pas, et d'autres projets utilisent Transmission. Notre client marche (voir `feature/torrent-performance`), mais il représente du code à maintenir et à défendre en soutenance.

## Ce que dit le sujet

> All frameworks, micro-frameworks, libraries, etc. are allowed, except for those that are used to create a video stream from a torrent. [...] libraries such as webtorrent, pulsar and peerflix are not permitted.

L'interdiction porte sur la **fonction** de l'outil, pas sur sa forme (bibliothèque, framework ou programme) :

| Outil | Ce qu'il rend | Conforme |
|---|---|---|
| webtorrent, peerflix, pulsar, torrent-stream | un flux lisible directement à partir du torrent | non |
| Transmission, qBittorrent (`qbittorrent-nox`), libtorrent, bittorrent-protocol | des fichiers (ou des pièces) sur disque | oui |
| Notre client écrit de zéro | des fichiers sur disque | oui, sans discussion possible |

Avec Transmission, **le flux reste notre code** : le Client Torrent sert un fichier en cours de téléchargement en HTTP `Range`, uniquement les octets des pièces vérifiées, et attend les autres (ADR-0007) ; ffmpeg le convertit en HLS. Transmission ne fait que télécharger. Le téléchargement séquentiel de Transmission ne crée pas de flux : il change seulement l'ordre des pièces.

Le seul risque est d'interprétation : un correcteur pourrait estimer qu'un client externe vide la partie BitTorrent du projet. Notre propre client est la seule option sans ce risque.

## Décision

- **Ajout** : torrent ajouté à l'arrêt (`torrent-add`, `paused`), dans `download-dir` = `outputDir`. Fichiers non choisis en `files-unwanted`, téléchargement séquentiel (`sequential_download`, Transmission ≥ 4.1), puis démarrage.
- **Suivi** : toutes les secondes, `torrent-get` donne le bitfield des pièces vérifiées. On en déduit `piecesCompleted`, `contiguousBytesFromStart`, `availableRanges` et `pieces`, comme avant.
- **Streaming** : lit le fichier là où Transmission l'écrit (`<outputDir>/<nom du torrent>/<chemin>`), sans suffixe `.part` ni dossier temporaire (réglages forcés au démarrage du conteneur, `docker/transmission/write-settings.sh`).
- **Fichiers à plat** : Transmission ne sait pas sortir un fichier de ses dossiers. À la fin, chaque fichier choisi reçoit un **lien physique** `outputDir/<fileName>` : même fichier sur disque, sans copie, et Transmission continue de partager depuis son chemin. Laravel lit `movies/{id}/{fileName}` comme avant.
- **Reprise (ADR-0008)** : en cas d'échec ou d'annulation, le torrent est retiré de Transmission, fichiers gardés. Un nouveau `POST /downloads` sur le même `outputDir` le rajoute, Transmission revérifie les fichiers présents (état `checking`) et télécharge le reste.
- **Sécurité** : RPC sans mot de passe, limité aux réseaux privés (`WHITELIST`) et publié sur `127.0.0.1` seulement.

## Mesures (08/10, même machine, Colima)

| Torrent | Notre client (`feature/torrent-performance`) | Transmission 4.1.3 | qBittorrent 5.2.4 |
|---|---|---|---|
| Bandes-annonces 1953 (2,3 Mo, pièce partagée par 6 fichiers) | 6 s | 12 s, SHA-1 identique à la référence | bloqué à 4/5 pièces |
| *M* (1931), 929 Mo, 2 web-seeds sur 3 morts | 34 s | 1331/1773 pièces en 6 min, pièces 0 et 1 jamais obtenues | 1772/1773 en 3 min |

Sur *M*, le `_meta.xml` d'archive.org a été régénéré après la création du torrent : la pièce 0, qu'il partage avec le début du film, ne peut venir que d'un pair, et Transmission n'en trouvait qu'un. Résultat : le début du film n'arrive jamais, donc rien n'est lisible, alors que le reste se télécharge. Notre client contourne ce cas (pairs gardés ouverts, web-seeds par plages).

## Conséquences

- **En moins** : tout le code BitTorrent du Client Torrent (pairs, trackers, web-seeds, essaim, `PROTOCOLS.md`) et ses tests réseau. Restent le parsing du `.torrent` (pour `inspect` et l'infohash), le calcul pièces/fichiers, le streaming et le pilotage de Transmission.
- **En plus** : un conteneur (`transmission`), et un chemin à partager à l'identique entre `app`, `client-torrent` et `transmission` (`./storage`).
- **Plus de priorité par plage** : Transmission n'expose pas de priorité par pièce. Lire vers l'avant est rapide (séquentiel) ; un saut loin devant, ou un `moov` en fin de fichier, attend que le téléchargement y arrive.
- **Débit** plus faible que notre client sur archive.org, et des torrents archive.org au début de fichier bloqué (cas *M*).
- **Après un redémarrage du Client Torrent** : Transmission continue, mais les id de job sont perdus (404), comme avant.

## Alternatives

| Option | Pourquoi pas |
|---|---|
| Garder notre client (`feature/torrent-performance`) | Reste la meilleure option technique (débit, cas *M*) et la seule sans risque d'interprétation ; coût : code à maintenir. |
| qBittorrent (`qbittorrent-nox`) | Même statut que Transmission vis-à-vis du sujet. Essayé : bloqué sur les deux torrents de test. |
| Une bibliothèque (libtorrent, bittorrent-protocol) | Autorisée elle aussi, mais à intégrer et piloter nous-mêmes : plus de travail que Transmission, moins de maîtrise que notre client. |
