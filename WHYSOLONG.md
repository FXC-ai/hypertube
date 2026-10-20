Le problème devient plus simple si l’on sépare clairement les trois acteurs : le serveur HTTP, FFmpeg et le moteur torrent. Aujourd’hui, chacun possède son propre chronomètre et peut abandonner sans savoir ce que les autres sont en train de faire.

## Les trois délais actuels

### 1. Le serveur HTTP abandonne après 60 secondes

Dans torrent-client/src/server/httpServer.js :

const DEFAULT_STREAM_OPTIONS = {
  readaheadBytes: Number(process.env.STREAM_READAHEAD_BYTES ?? 8 * 1024 * 1024),
  stallTimeoutMs: Number(process.env.STREAM_STALL_TIMEOUT_MS ?? 60000),
};

Quand FFmpeg demande une zone du fichier dont la pièce torrent n’est pas encore disponible, streamFile() appelle :

await waitForPiece(
  availability,
  piece,
  endedState,
  stallTimeoutMs,
  () => closed,
);

Puis waitForPiece() démarre ce minuteur :

const timer = setTimeout(
  () =>
    finish(
      new StreamStalledError(
        `piece ${pieceIndex} not received for ${stallTimeoutMs} ms`,
      ),
    ),
  stallTimeoutMs,
);

Cela signifie :

> « Si cette pièce n’est pas arrivée dans les 60 secondes, j’arrête de servir cette requête HTTP. »

Mais le serveur HTTP ne sait pas que le torrent est mort. Il sait seulement que cette pièce particulière a mis plus de 60 secondes à arriver.

Si les en-têtes HTTP ont déjà été envoyés, il ne peut plus répondre avec une erreur 503. Il fait alors :

res.destroy();

La connexion est brutalement coupée au milieu du fichier.

———

### 2. FFmpeg abandonne après 90 secondes sans données

Dans config/media.php (config/media.php) :

'stream' => [
    'rw_timeout_us' => 90_000_000,
    'probe_timeout_s' => 300,
],

Puis MovieInput.php (app/Services/Media/MovieInput.php) transmet cette valeur à FFmpeg :

return [
    '-rw_timeout', (string) config('media.stream.rw_timeout_us'),
    '-reconnect', '1',
    '-reconnect_on_network_error', '1',
    '-reconnect_on_http_error', '5xx',
    '-reconnect_delay_max', '5',
];

rw_timeout est exprimé en microsecondes :

90 000 000 µs = 90 secondes

FFmpeg applique donc cette règle :

> « Si je reste bloqué trop longtemps sur une opération réseau, j’abandonne cette lecture. »

Il ne connaît pas non plus l’état véritable du torrent.

———

### 3. Le moteur torrent décide après 120 secondes que le swarm est mort

Dans torrent-client/src/swarm/downloadTorrent.js :

const {
  // ...
  stallTimeoutMs = 120000,
  // ...
} = options;

Ce délai ne concerne pas directement la connexion HTTP. Il surveille le téléchargement torrent lui-même.

La variable suivante est mise à jour chaque fois qu’une pièce complète et valide arrive :

let lastProgressAt = Date.now();

Puis, lorsqu’une pièce est terminée :

completed += 1;
lastProgressAt = Date.now();

Le moteur considère donc le torrent comme bloqué lorsque plus aucune pièce n’a été complétée pendant 120 secondes. C’est ce mécanisme qui produit ton erreur :

No piece completed for 120s

Contrairement aux deux autres délais, celui-ci dispose d’informations pertinentes :

- les sources disponibles ;
- les tentatives effectuées ;
- les connexions refusées ;
- les timeouts des pairs ;
- les pièces encore manquantes ;
- la progression générale du téléchargement.

C’est donc lui qui est le mieux placé pour décider que le torrent est véritablement mort.

## Ce qui se produit actuellement

Supposons que FFmpeg attende la pièce 325 :

0 s     FFmpeg demande les octets appartenant à la pièce 325
        Le serveur HTTP donne une priorité urgente à cette pièce

30 s    Les premières sources échouent
        Le moteur torrent continue ses tentatives

60 s    La pièce n’est toujours pas arrivée
        Le serveur HTTP coupe la connexion

90 s    FFmpeg aurait lui-même abandonné si la connexion était restée ouverte

120 s   Le moteur torrent aurait enfin décidé si le swarm était bloqué

Le problème saute aux yeux : la connexion est coupée à 60 secondes alors que le téléchargement est encore officiellement actif pendant au moins 60 secondes supplémentaires.

La pièce pourrait parfaitement arriver à la 70e seconde :

60 s : serveur HTTP → « trop tard, je coupe »
70 s : moteur torrent → « j’ai enfin reçu la pièce »

À ce moment-là, le client torrent a bien récupéré la donnée, mais la connexion utilisée par FFmpeg n’existe déjà plus.

## Pourquoi le MP4 résiste mieux

Après une coupure, FFmpeg peut rouvrir une connexion HTTP grâce aux options :

'-reconnect', '1',
'-reconnect_on_network_error', '1',
'-reconnect_on_http_error', '5xx',

Le MP4 se prête relativement bien à cette reprise parce que FFmpeg peut souvent redemander une plage précise du fichier avec une requête HTTP Range.

Conceptuellement :

lecture MP4
→ coupure
→ nouvelle requête Range: bytes=15000000-
→ poursuite de la lecture

Le MKV est plus sensible aux interruptions du flux. Selon l’endroit et la manière dont la connexion est fermée, son démuxeur peut interpréter la coupure comme une fin de données exploitable.

Il peut alors se passer ceci :

FFmpeg lit le début du MKV
→ il produit quelques segments HLS
→ la pièce suivante tarde
→ le serveur détruit la connexion
→ le démuxeur considère qu’il n’a plus de paquets à lire
→ FFmpeg ferme proprement la playlist
→ FFmpeg retourne éventuellement 0

Cela explique la différence observée, mais cela ne signifie pas que le serveur devrait volontairement traiter les deux conteneurs différemment.

## Pourquoi deux branches ne sont probablement pas nécessaires

La proposition initiale était :

MP4 → couper puis reconnecter
MKV → conserver la connexion ouverte

Mais pourquoi couper volontairement la connexion MP4 si le torrent est toujours actif ?

Même si le MP4 supporte mieux les reconnexions, une déconnexion reste :

- une requête supplémentaire ;
- une nouvelle lecture Range ;
- un état réseau supplémentaire à gérer ;
- une occasion supplémentaire pour FFmpeg d’interpréter incorrectement la réponse.

Le comportement le plus logique est identique pour les deux formats :

Une pièce manque
        ↓
Le serveur la marque urgente
        ↓
Il attend pendant que le torrent est actif
        ↓
La pièce arrive ───────────────→ le flux continue
        │
        └── le torrent échoue ─→ le flux est fermé avec une erreur

Le format du conteneur n’intervient plus dans cette décision.

## Ce que signifie réellement « attendre indéfiniment »

Cela ne signifie pas supprimer toute détection de blocage.

Le serveur HTTP attendrait sans posséder son propre délai arbitraire :

await waitForPiece(
  availability,
  pieceIndex,
  endedState,
  () => closed,
);

La fonction surveillerait alors seulement trois événements :

if (availability.has(pieceIndex)) {
  // La pièce est arrivée : reprendre l’envoi.
} else if (endedState()) {
  // Le torrent est failed ou cancelled : arrêter.
} else if (isClosed()) {
  // FFmpeg a fermé la connexion : libérer les ressources.
}

Le minuteur HTTP de 60 secondes disparaîtrait pour une pièce demandée après l’ouverture du torrent.

Le moteur torrent conserverait son délai de 120 secondes :

stallTimeoutMs = 120000;

S’il ne reçoit réellement plus aucune pièce, il passe le job à failed. endedState() détecte alors cet état et débloque la requête HTTP.

« Attendre indéfiniment » signifie donc plus précisément :

> Le serveur HTTP ne prend pas lui-même la décision d’abandonner. Il attend soit la pièce, soit la décision du moteur torrent.

## Et que faire du rw_timeout de FFmpeg ?

Si on conserve :

'-rw_timeout', '90000000',

FFmpeg abandonnera toujours après 90 secondes, donc avant la décision normale du torrent à 120 secondes.

Deux possibilités existent.

La solution strictement cohérente consiste à ne pas appliquer rw_timeout à ce flux :

return [
    '-reconnect', '1',
    '-reconnect_on_network_error', '1',
    '-reconnect_on_http_error', '5xx',
    '-reconnect_delay_max', '5',
];

FFmpeg attend alors la réponse du client torrent.

Une solution plus défensive serait de conserver un délai nettement supérieur, par exemple plusieurs minutes :

'rw_timeout_us' => 300_000_000, // 5 minutes

Ce délai ne servirait plus à gérer une pièce torrent lente. Il deviendrait seulement une protection contre un véritable bug du serveur HTTP qui laisserait une connexion suspendue alors que le téléchargement est déjà terminé.

Cependant, un délai fixe peut toujours couper un torrent encore actif. La solution conceptuellement la plus propre reste donc :

FFmpeg ne décide pas si le torrent est mort
Serveur HTTP ne décide pas si le torrent est mort
Moteur torrent décide si le torrent est mort

## Conclusion

Les deux branches MP4/MKV ne rendraient pas le téléchargement plus rapide et ne répareraient pas un swarm réellement mauvais.

La correction utile est plus petite : supprimer la compétition entre les trois chronomètres. Le serveur HTTP attend tant que le torrent est actif, FFmpeg n’impose pas un délai plus court, et le moteur torrent conserve son mécanisme de détection d’échec à 120 secondes.

La validation HLS que tu viens d’ajouter reste le dernier filet de sécurité : même si FFmpeg interprète malgré tout une coupure comme une fin normale, Laravel refusera désormais le faux résultat tronqué.
