# Retry automatique borné pour le téléchargement, distinct du retry manuel de la conversion

Le retry de téléchargement ([issue #18](https://github.com/FXC-ai/hypertube/issues/18)) diverge
délibérément du pattern déjà en place pour la conversion (`app/Jobs/ConvertMovie.php`,
`$tries = 1`, retry uniquement manuel via un nouveau `POST /movies/{movie}/conversion`) : un
download attempt échoué est retenté **automatiquement**, jusqu'à 3 fois, avec un backoff (1 min
puis 5 min), avant de passer en état définitivement échoué (`Exhausted`) nécessitant une action
manuelle - voir [overview.md](../../torrent-client/overview.md#conception-retry--reenqueue-issue-18)
pour le détail du mécanisme.

## Décision

Retry automatique borné (3 tentatives + backoff) pour le téléchargement, retry manuel sans
compteur pour la conversion - deux politiques différentes pour deux domaines différents,
malgré le même pattern de namespacing par attempt (`download_attempt` / `conversion_attempt`).

## Pourquoi

Un échec de téléchargement P2P (tracker injoignable, pair qui ferme la connexion, web-seed qui
répond 503) est le plus souvent **transitoire** - retenter la même opération quelques minutes
plus tard a de bonnes chances de réussir sans aucune intervention humaine. Un échec ffmpeg
(codec non supporté, fichier source corrompu, `moov atom` mal placé) est au contraire presque
toujours **déterministe** - retenter à l'identique échoue à l'identique ; seule une action
humaine (autre fichier, autre source) change l'issue. Automatiser le retry de la conversion
n'apporterait donc rien, alors que ne pas l'automatiser pour le téléchargement forcerait
l'utilisateur à surveiller et relancer à la main des échecs réseau ordinaires.

## Conséquences

- Le namespacing de stockage (`download_attempt`, UUID généré à chaque nouvel essai, miroir de
  `conversion_attempt`) reste identique entre les deux domaines - seule la politique de retry
  autour diffère. Un lecteur qui compare les deux ne doit pas s'attendre à un comportement
  symétrique au-delà de ce namespacing.
- Le compteur de tentatives et le backoff vivent entièrement côté Laravel (nouveau Job, ex.
  `DownloadMovie`), pas dans le Client Torrent, qui reste stateless et ignore la notion de
  tentative - voir [CONTEXT.md](../../CONTEXT.md).
- Si un futur lecteur voit `ConvertMovie` en `$tries = 1` et propose d'aligner le téléchargement
  dessus (ou l'inverse), le renvoyer à cette ADR plutôt que de "corriger" l'un pour matcher
  l'autre : la divergence est assumée, pas un oubli.
