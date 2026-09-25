# Tester le client torrent contre les sources choisies

Sources retenues pour la recherche/acquisition (§III.2.1 du sujet) : `archive.org` et `publicdomaintorrents.info`. Ce document liste ce qu'il faut savoir sur chacune avant d'écrire ou de tester le client torrent maison, et les scénarios à couvrir.

## archive.org

- **Torrents générés automatiquement par item**, et **régénérés** (donc nouvel info-hash) à chaque changement du contenu ou des métadonnées de l'item. Confirme qu'il faut corréler un `Movie` à sa source via l'`identifier` archive.org (stable), jamais via l'info-hash ou un magnet link (instables) - retrouver le torrent courant en interrogeant `archive.org/metadata/{identifier}`, pas une URL de `.torrent` figée.
- **archive.org ne seed pas lui-même en pair-à-pair.** Ils s'appuient sur du webseeding (« Getright-style », cousin de BEP19) comme unique filet quand aucun pair n'est actif sur le swarm. Concrètement : pour ce genre de contenu de niche, le swarm P2P classique sera souvent vide - le webseeding n'est donc pas un simple repli optionnel, c'est le chemin à faire fonctionner **en premier**. Un client qui ne le supporte pas (ex. rTorrent, cité explicitement par la doc d'archive.org comme non-supporté) échoue purement et simplement sur une bonne partie des items.
- Source : [help.archive.org/help/archive-bittorrents](https://help.archive.org/help/archive-bittorrents/)

### Scénarios de test

1. **Item avec pairs actifs** (swarm normal) - rare pour ce type de contenu, mais à couvrir pour valider le chemin P2P classique.
2. **Item sans pairs, webseed uniquement** - le cas le plus probable en pratique, donc le plus important à valider en premier. Choisir un item peu populaire pour le reproduire facilement.
3. **Torrent régénéré entre deux appels** (item modifié côté archive.org) - vérifie que la corrélation par `identifier` survit au changement d'info-hash, et que le client ne reste pas accroché à un ancien `.torrent` devenu obsolète.

## publicdomaintorrents.info

- **Aucune API publique** (recherche + inspection directe de la page d'accueil ne montrent ni API, ni flux RSS, ni endpoint structuré). Navigation par pages HTML :
  - Pages catégorie : `nshowcat.html?category=<nom>`
  - Pages film : `nshowmovie.html?movieid=<id>`
- L'intégration demandera donc du **scraping HTML** pour retrouver les liens `.torrent`/magnet par film, contrairement à archive.org qui expose un endpoint de métadonnées propre.
- **À vérifier manuellement avant d'investir dans le scraping** :
  - Le format exact des liens de téléchargement sur une page `nshowmovie.html` (`.torrent` direct, magnet, les deux ?).
  - La stabilité de `movieid` dans le temps (pas de garantie documentée, à différencier du cas archive.org où c'est l'info-hash qui est instable et l'`identifier` qui est stable).
  - La présence ou non de compteurs de seeders/téléchargements exploitables dans le HTML - nécessaire pour le tri "pas de recherche = vidéos les plus populaires" exigé par le sujet (§III.2.2). Si absent pour cette source, le critère de tri par popularité ne pourra s'appliquer qu'aux résultats issus d'archive.org.

### Scénarios de test

1. Téléchargement direct d'un `.torrent` depuis une page film.
2. Parcours d'une page catégorie pour valider le flux de listing/pagination.

## Fichiers de référence pour développer le client torrent

Avant de brancher le client torrent sur archive.org ou publicdomaintorrents.info, le développer et le valider contre des `.torrent` de référence, stables et massivement seedés, pour isoler les bugs du client de ceux liés à une source peu fiable.

**[webtorrent.io/free-torrents](https://webtorrent.io/free-torrents)** - quatre films Blender Foundation (« open movies », licence Creative Commons/domaine public) : *Big Buck Bunny*, *Sintel*, *Tears of Steel*, *Cosmos Laundromat*, plus un sample audio (*The WIRED CD*). Chaque entrée fournit un `.torrent` téléchargeable **et** un magnet link. Ce sont parmi les torrents les plus seedés au monde (utilisés depuis des années comme jeux de test BitTorrent de référence) - swarm P2P classique quasi garanti actif, à l'inverse du cas archive.org documenté plus haut. Bon complément pour couvrir le scénario « swarm normal, beaucoup de pairs » du plan de test.

Point d'attention : ces torrents annoncent aussi des trackers WebSocket (utilisés par la lib JS `webtorrent` pour du pair-à-pair navigateur-à-navigateur). Le client maison n'a pas à les gérer - les trackers BitTorrent classiques (UDP/HTTP) et/ou le DHT suffisent pour rejoindre le même swarm côté serveur.

**Nom du fichier de test à faire passer au client** : n'importe lequel des `.torrent` ci-dessus fonctionne comme fixture de départ - le plus petit (*Sintel*, ~130 Mo) est un bon premier choix pour itérer vite.

### Alternatives documentées

- **Site officiel Blender Foundation** ([blender.org/download](https://www.blender.org/download/), sections des films) - source canonique des mêmes `.torrent`, utile si webtorrent.io devient indisponible.
- **Academic Torrents** ([academictorrents.com](https://academictorrents.com)) - contenu légal plus varié (datasets, quelques vidéos), swarms généralement moins actifs que les open movies Blender.
- **Images ISO Ubuntu/Debian** - torrents parmi les plus seedés qui existent, utiles pour tester le protocole BitTorrent brut (téléchargement, vérification de pièces) indépendamment de toute logique vidéo, mais hors-sujet pour valider le pipeline film de bout en bout (pas du contenu vidéo).

## Web-seeding générique (BEP19), au-delà d'archive.org

Le sujet interdit les libs torrent « tout-en-un » (`webtorrent`, `pulsar`, `peerflix`) mais n'interdit pas d'implémenter soi-même le protocole de web-seeding (BEP19) dans le client torrent maison - c'est exactement ce que documente le mécanisme de webseeding d'archive.org ci-dessus. Prévoir un scénario de test dédié avec un item délibérément dépourvu de pairs pour valider que le fallback webseed fonctionne réellement de bout en bout, pas seulement en théorie.
