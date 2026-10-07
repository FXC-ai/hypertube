# Hypertube

Plateforme de streaming de films en pair-a-pair. Laravel/Inertia/React sert l'app et pilote la conversion ; un service separe (le Client Torrent) telecharge les films via BitTorrent.

## Language

**Client Torrent**:
Service Node.js separe, sans dependance npm, qui parle le protocole BitTorrent nativement (bencode, trackers HTTP/UDP, peer wire, BEP19 web-seed). Stateless : ne connait ni film, ni utilisateur, ni tentative - seulement un job identifie par un UUID genere a chaque appel `POST /downloads`.
_Avoid_: torrent service, downloader

**Piece retry**:
Dans le Client Torrent, une piece individuelle qui echoue contre une source attend un delai croissant (backoff) puis est retentee contre la **Source active** qui l'a le moins ratee. Pas de budget fixe par piece : le telechargement n'echoue que si aucune piece n'aboutit pendant 2 min (voir [overview.md](torrent-client/overview.md#ticket-c---robustesse-des-sources-et-reprise)). Interne au Client Torrent - ne concerne pas les tentatives de telechargement complet.
_Avoid_: retry (seul, sans preciser le niveau)

**Source active**:
Pair ou web-seed que le Client Torrent utilise encore pour telecharger des pieces. Une source est ecartee ("dropped") 30 s apres 3 echecs de connexion d'affilee, ou pour de bon apres 2 pieces rejetees au hash. Echouer une piece pour une autre raison (pair qui ne l'a pas, HTTP 404) ne compte pas contre la source.
_Avoid_: peer (un web-seed est aussi une source)

**Fichiers choisis**:
Sous-ensemble des fichiers d'un torrent que le Client Torrent telecharge reellement, designes par leur index dans la liste `files` du `.torrent` (`fileIndexes` de `POST /downloads`). Les octets des autres fichiers ne sont jamais ecrits (ticket A, propose).
_Avoid_: selected pieces (on choisit des fichiers, les pieces en decoulent)

**Video principale**:
Le plus gros fichier d'extension video d'un torrent (`mainVideoIndex` de `POST /torrents/inspect`). Suggeree par defaut avec les sous-titres, et prioritaire au debut et a la fin pour que ffprobe trouve `moov`/`Cues` vite.
_Avoid_: film (un torrent peut contenir plusieurs videos)

**Recheck**:
Reverification SHA-1 des pieces deja presentes dans `outputDir` au demarrage d'un job (etat `"checking"`), pour ne retelecharger que ce qui manque. C'est le seul mecanisme de reprise du Client Torrent, qui ne persiste aucun etat - voir [ADR-0008](docs/adr/0008-client-state-in-memory-with-disk-recheck.md).
_Avoid_: resume state, checkpoint (rien n'est stocke a part les fichiers eux-memes)

**Lecture en streaming**:
Lecture d'un fichier choisi pendant son telechargement via `GET /downloads/:id/files/:index` (requetes `Range`). Une plage manquante est priorisee et la reponse attend qu'elle arrive. Seul ffmpeg/ffprobe de `ConvertMovie` l'utilise ; les spectateurs lisent le HLS - voir [ADR-0007](docs/adr/0007-stream-partial-files-over-http.md).
_Avoid_: streaming (seul : ambigu avec le streaming HLS vers le navigateur)

**Download attempt**:
Une tentative complete de telechargement d'un film, identifiee cote Laravel par un UUID genere a chaque nouvelle tentative, en miroir de `conversion_attempt`. Quand un download attempt echoue, Laravel en demarre un nouveau avec un nouvel identifiant plutot que de reutiliser l'ancien.
_Avoid_: retry, reenqueue (ce sont les mecanismes qui produisent un nouveau download attempt, pas le concept lui-meme)

**Reenqueue**:
Le mecanisme cote Laravel (queue Job + tries/backoff) qui demarre un nouveau download attempt apres un echec, en rappelant `POST /downloads` avec un `outputDir` namespace par le nouvel identifiant. Vit entierement cote Laravel ; le Client Torrent n'a aucune notion de reenqueue. Deliberement different du retry de conversion (manuel, sans compteur) - voir [ADR-0006](docs/adr/0006-download-retry-diverges-from-conversion-retry.md).
_Avoid_: retry seul (terme trop generique, confondu avec piece retry)

**Exhausted**:
Etat terminal d'un film dont le download attempt a echoue 3 fois de suite malgre le reenqueue automatique. Plus aucun reenqueue automatique ne se declenche - seule une action manuelle (equivalente au retry de conversion) peut relancer un nouveau download attempt. Distinct d'un simple echec d'attempt (qui, lui, declenche encore un reenqueue tant que le compteur le permet).
_Avoid_: failed (trop proche de l'etat d'un attempt individuel, ambigu avec Failed de ConversionStatus)
