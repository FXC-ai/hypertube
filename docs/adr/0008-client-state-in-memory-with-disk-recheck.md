# L'état du Client Torrent reste en mémoire, la reprise se fait en revérifiant les fichiers sur disque

**Statut : implémenté (#27), reste à relire par FX** (le point ouvert en bas touche la conception de l'[issue #18](https://github.com/FXC-ai/hypertube/issues/18)).

Le Client Torrent ne persiste aucun état de téléchargement : ni fichier d'état, ni base de données. Pour reprendre un téléchargement interrompu (échec, annulation, redémarrage du conteneur), un nouveau `POST /downloads` sur le **même `outputDir`** commence par **revérifier les fichiers déjà présents** : il calcule le SHA-1 de chaque pièce sur disque et le compare au hash du `.torrent`. Toute pièce valide est comptée comme reçue et n'est pas retéléchargée. C'est le "recheck" que font tous les clients BitTorrent classiques.

## Contexte

Aujourd'hui, l'état d'un téléchargement vit dans une `Map` en mémoire (`downloadManager.js`), et un nouveau `POST /downloads` repart toujours de zéro. Le log Mulan du 28/09 montre le coût : le téléchargement a échoué à 1229 pièces sur 1421 (2,58 Go sur 2,98 Go). Avec le reenqueue prévu par #18, la tentative suivante aurait retéléchargé les 2,58 Go.

La question posée sur Discord ("le serveur torrent devra stocker les informations dans une DB ? ou en mémoire ?") a quatre réponses possibles.

## Options comparées

| Option | Avantages | Inconvénients |
|---|---|---|
| **1. Mémoire seule** (aujourd'hui) | Le plus simple. Aucun fichier en plus. Le client reste sans état au sens de [CONTEXT.md](../../CONTEXT.md). | Tout échec ou redémarrage fait repartir de zéro. Inacceptable pour des films de plusieurs Go. |
| **2. Mémoire + recheck sur disque** (retenue) | Reprise complète sans rien stocker : les fichiers **sont** l'état. Robuste par construction : une pièce corrompue ou à moitié écrite échoue au hash et est retéléchargée. Fonctionne aussi si les fichiers viennent d'ailleurs (copie manuelle, ancien téléchargement). Le client reste sans état. | Coût CPU et disque au démarrage : lire et hasher les fichiers existants (de l'ordre de quelques secondes à quelques dizaines de secondes pour 3 Go, selon le disque et le montage Docker). Les pièces à cheval entre un fichier choisi et un fichier non choisi ne peuvent pas être revérifiées (voir Conséquences). |
| **3. Fichier d'état à côté des données** (`.hypertube-state.json` : infohash, fichiers choisis, bitfield) | Reprise instantanée, sans relire les données. | Deux sources de vérité (le fichier d'état et les données) qui peuvent diverger : crash entre l'écriture d'une pièce et la mise à jour de l'état, fichier copié ou modifié à la main. Il faudrait de toute façon un recheck pour être sûr. Écritures disque en plus à chaque pièce. |
| **4. Base de données de Laravel** | Laravel voit tout l'état directement. | Viole la règle déjà actée dans #6 : le Client Torrent ne se connecte jamais à SQLite (un seul écrivain). Couple fortement le client à Laravel. Une écriture en base par pièce (des milliers par film) sur SQLite. |

## Décision

Option 2. L'état en mémoire suffit pendant qu'un téléchargement tourne, et la vérification sur disque au démarrage donne la reprise sans ajouter de source de vérité. Si le temps de vérification devient gênant, l'option 3 pourra s'ajouter plus tard **en complément** (comme cache qui évite de relire les pièces déjà validées), sans changer l'API.

## Conséquences

- **Nouvel état `"checking"`** dans `GET /downloads/:id`, entre la création du job et `"downloading"`, pendant la vérification. `piecesCompleted` y progresse au fil des pièces validées. Voir [torrent-client/API.md](../../torrent-client/API.md).
- **Pièces de bord non vérifiables** : avec la sélection de fichiers, les octets d'un fichier non choisi ne sont jamais écrits. Une pièce qui chevauche un fichier choisi et un fichier non choisi ne peut donc pas être revérifiée depuis le disque : elle est retéléchargée. C'est au plus deux pièces par fichier choisi.
- **Après un redémarrage du conteneur**, Laravel reçoit `404` sur `GET /downloads/:id`. Le Client Torrent n'essaie pas de se souvenir des jobs : c'est à Laravel de relancer un `POST /downloads`, ce que le reenqueue de #18 fait déjà quand le service est injoignable ou le job inconnu.
- **Le client reste sans état au sens de [CONTEXT.md](../../CONTEXT.md)** : il ne connaît toujours ni film, ni utilisateur, ni tentative. Il reconnaît seulement des fichiers valides dans un `outputDir`.

## Point ouvert pour FX : `outputDir` et download attempts (#18)

La conception de #18 ([overview.md](../../torrent-client/overview.md#décisions-actées)) namespace l'`outputDir` par `download_attempt`, en miroir de `hls/{conversion_attempt}`. Chaque nouvelle tentative écrit donc dans un **nouveau** dossier vide, et le recheck n'y trouve rien : la reprise ne sert à rien.

Proposition : une nouvelle tentative **réutilise le dossier de la tentative échouée précédente**. Le but du namespacing de #18 ("une tentative précédente réussie n'est jamais écrasée") reste tenu, puisqu'on ne réutilise que le dossier d'une tentative **échouée** ; une tentative réussie n'est jamais relancée. Le namespacing ne reste utile que si une source régénère son torrent entre deux tentatives (nouvel infohash, fichiers potentiellement différents) : dans ce cas seulement, un nouveau dossier est justifié.

À trancher avec FX avant d'implémenter la reprise côté Laravel.
