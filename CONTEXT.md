# Hypertube

Plateforme de streaming de films en pair-a-pair. Laravel/Inertia/React sert l'app et pilote la conversion ; un service separe (le Client Torrent) telecharge les films via BitTorrent.

## Language

**Client Torrent**:
Service Node.js separe, sans dependance npm, qui parle le protocole BitTorrent nativement (bencode, trackers HTTP/UDP, peer wire, BEP19 web-seed). Stateless : ne connait ni film, ni utilisateur, ni tentative - seulement un job identifie par un UUID genere a chaque appel `POST /downloads`.
_Avoid_: torrent service, downloader

**Piece retry**:
Dans le Client Torrent, une piece individuelle qui echoue contre une source est retentee contre une autre source (pair ou web-seed), jusqu'a `maxAttemptsPerPiece`. Deja implemente, interne au Client Torrent - ne concerne pas les tentatives de telechargement complet.
_Avoid_: retry (seul, sans preciser le niveau)

**Download attempt**:
Une tentative complete de telechargement d'un film, identifiee cote Laravel par un UUID genere a chaque nouvelle tentative, en miroir de `conversion_attempt`. Quand un download attempt echoue, Laravel en demarre un nouveau avec un nouvel identifiant plutot que de reutiliser l'ancien.
_Avoid_: retry, reenqueue (ce sont les mecanismes qui produisent un nouveau download attempt, pas le concept lui-meme)

**Reenqueue**:
Le mecanisme cote Laravel (queue Job + tries/backoff) qui demarre un nouveau download attempt apres un echec, en rappelant `POST /downloads` avec un `outputDir` namespace par le nouvel identifiant. Vit entierement cote Laravel ; le Client Torrent n'a aucune notion de reenqueue. Deliberement different du retry de conversion (manuel, sans compteur) - voir [ADR-0006](docs/adr/0006-download-retry-diverges-from-conversion-retry.md).
_Avoid_: retry seul (terme trop generique, confondu avec piece retry)

**Exhausted**:
Etat terminal d'un film dont le download attempt a echoue 3 fois de suite malgre le reenqueue automatique. Plus aucun reenqueue automatique ne se declenche - seule une action manuelle (equivalente au retry de conversion) peut relancer un nouveau download attempt. Distinct d'un simple echec d'attempt (qui, lui, declenche encore un reenqueue tant que le compteur le permet).
_Avoid_: failed (trop proche de l'etat d'un attempt individuel, ambigu avec Failed de ConversionStatus)
