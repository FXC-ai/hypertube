# ffmpeg lit un film en cours de téléchargement via HTTP, pas directement le fichier qui grossit

**Statut : proposé, à valider par FX.** Remplace la décision "Pont de conversion" de l'[issue #6](https://github.com/FXC-ai/hypertube/issues/6) ("`ffmpeg` lit directement le même fichier que le Client Torrent écrit, pendant qu'il grossit") et le mécanisme de seuil de l'[issue #14](https://github.com/FXC-ai/hypertube/issues/14).

Pendant un téléchargement, `ConvertMovie` (ffprobe puis ffmpeg) lit le film via un endpoint HTTP du Client Torrent, `GET /downloads/:id/files/:index`, qui supporte les requêtes `Range`. Si les octets demandés ne sont pas encore téléchargés, le Client Torrent passe les pièces correspondantes en priorité et **retient la réponse** jusqu'à les avoir. Une fois le téléchargement terminé, ffmpeg relit le fichier sur disque comme aujourd'hui. Contrat détaillé dans [torrent-client/API.md](../../torrent-client/API.md), séquence complète dans [torrent-client/overview.md](../../torrent-client/overview.md#conception-sélection-de-fichiers-robustesse-et-streaming-tickets-a-c-b).

## Contexte : pourquoi lire le fichier qui grossit ne marche pas

Le Client Torrent écrit chaque pièce à sa position finale dans le fichier (`handle.write(data, 0, length, fileOffset)`). Tant que le téléchargement n'est pas fini, le fichier a donc la bonne taille apparente mais contient des **trous** : des zones jamais écrites, qui se relisent comme des octets à zéro. Trois conséquences :

1. **ffmpeg ne voit pas les trous.** Un trou se lit comme de la donnée valide (des zéros). ffmpeg ne lève pas d'erreur "donnée pas encore là" : il décode des zéros, produit des segments HLS corrompus, ou s'arrête sur une erreur de décodage trompeuse. Aucun seuil de progression ne protège contre ça.
2. **Les trous ne sont pas seulement en fin de fichier.** Les pièces partent dans l'ordre des index, mais une pièce qui échoue est remise **en fin de file**. Le log Mulan du 28/09 le montre : la pièce 12, tout au début du fichier, manquait encore alors que 1229 pièces sur 1421 étaient reçues. Un seuil "86 % téléchargé" aurait lancé ffmpeg sur un fichier troué dès les premières secondes.
3. **Les métadonnées peuvent être à la fin.** Un MP4 peut avoir son atome `moov` (codecs, timestamps, offsets des samples) après les données `mdat`, et un MKV ses `Cues` en fin de fichier. ffprobe en a besoin pour analyser le fichier. Avec une lecture directe, il faudrait que le client sache lui-même où se trouvent ces structures (parser les boîtes MP4 et les éléments EBML), et que Laravel attende qu'elles soient là.

## Décision

Le Client Torrent expose chaque fichier choisi d'un téléchargement en HTTP avec support `Range`, et c'est **la lecture de ffmpeg qui pilote la priorité** :

- ffprobe cherche le `moov` à la fin du fichier : il envoie une requête `Range` sur la fin, le client priorise ces pièces et répond dès qu'il les a.
- ffmpeg lit ensuite séquentiellement : chaque requête priorise les pièces demandées, plus une fenêtre d'avance (8 Mo par défaut).
- Le reste du fichier continue de se télécharger en arrière-plan, dans l'ordre.
- Dès le démarrage, la première et la dernière pièce du fichier vidéo principal sont priorisées d'office, pour que ffprobe n'attende pas.

Le client n'a donc **pas besoin de comprendre les formats MP4 ou MKV** : il sert des plages d'octets, et ffprobe/ffmpeg savent déjà où chercher.

## Alternatives écartées

| Option | Pourquoi écartée |
|---|---|
| **Lire le fichier qui grossit + seuil de progression** (décision actuelle de #6/#14) | Les trous se lisent comme des zéros sans erreur (points 1 et 2 ci-dessus). Aucun seuil ne garantit l'absence de trou. |
| **Lire le fichier + carte des pièces exposée à Laravel** | Laravel devrait savoir quelles plages ffmpeg va lire avant qu'il les lise, ce qui est impossible pour un seek. Il faudrait aussi que le client parse le MP4 et le MKV pour trouver `moov` et `Cues`. Beaucoup de code, et toujours fragile. |
| **Système de fichiers FUSE qui bloque les lectures sur les trous** | Même résultat que HTTP côté ffmpeg, mais demande `/dev/fuse` et des privilèges dans Docker, ainsi qu'une dépendance native. Hors de proportion pour le projet. |
| **Tube nommé (FIFO) alimenté dans l'ordre** | Pas de seek possible : ffprobe ne peut pas aller lire un `moov` placé en fin de fichier. |

## Conséquences

- **Côté Laravel (FX)** : `MediaProbe` et `HlsConverter` reçoivent une URL `http://client-torrent:7881/downloads/{id}/files/{index}` au lieu d'un chemin tant que le téléchargement n'est pas terminé, puis le chemin disque après. ffprobe et ffmpeg acceptent tous les deux une URL comme entrée. Il faut passer `-rw_timeout` à une valeur supérieure au délai d'attente du client (voir API.md), sinon ffmpeg abandonne avant que la pièce arrive.
- **Plus besoin de seuil** : `ConvertMovie` peut démarrer dès que le téléchargement est lancé. Pour afficher "prêt à regarder", le statut expose `contiguousBytesFromStart` par fichier.
- **Le `moov`/`Cues` en fin de fichier n'est plus un risque à valider** (critère d'acceptation de #14) : c'est ffprobe qui va le chercher, et le client le priorise. Il reste à le vérifier avec un vrai MP4 et un vrai MKV, mais ce n'est plus une inconnue de conception.
- **Si le Client Torrent redémarre pendant une conversion**, l'URL renvoie `404` (l'état est en mémoire, voir [ADR-0008](0008-client-state-in-memory-with-disk-recheck.md)) : ffmpeg échoue, `ConvertMovie` échoue, et le reenqueue de #18 relance un download attempt qui reprend grâce à la vérification sur disque.
- **Latence des pièces prioritaires** : le client ouvre aujourd'hui une connexion TCP par pièce (voir [README.md](../../torrent-client/README.md#notes-pour-la-suite)). Une pièce prioritaire paie donc toujours un handshake. Acceptable pour démarrer ; des sessions de pair persistantes réduiraient le temps de démarrage de la lecture.
- **Plusieurs lecteurs simultanés** sur le même fichier sont possibles (chaque requête enregistre sa propre fenêtre de priorité), mais en pratique il n'y en a qu'un par film : le seul ffmpeg de `ConvertMovie`. Les spectateurs lisent le HLS produit, jamais cet endpoint.
