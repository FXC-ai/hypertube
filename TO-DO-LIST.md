
## Priorité

 - les sous-titres sont dispos uniquement pendant la conversion pour TPB AFK ?
 - Probleme avec les photos de profil de 42
 - Corriger les erreurs HLS et les avertissements du client torrent.

## Pour Ben

- Trier les résultats recherchés par nom.
- Afficher par défaut les médias populaires avec un véritable critère de popularité.
- Ajouter les tris et filtres par nom, genre, note IMDb, année et direction (nom ;genre ; note IMDb ; année de production ; direction du tri)
- Afficher l’année de production sur les miniatures.

## Nice to have

- Nettoyer les console.log, console.warn et console.error du frontend.
- Améliorer la précision du suivi vu/non-vu.
- Garantir un tampon plus important avant de déclarer la vidéo lisible.
- Fiabiliser la sélection des sous-titres selon la langue audio et la langue préférée.
- Protéger les fichiers vidéo et les sous-titres contre un accès direct sans authentification.

## Détails

- Permettre la déconnexion depuis la page d’accueil lorsqu’un utilisateur est connecté.
- Chiffrer les tokens OAuth stockés en base.

## Déjà en place ou accepté

- La recherche utilise le catalogue local alimenté en amont, car les API externes ne sont pas suffisamment performantes.
- Le suivi vu/non-vu à l’ouverture de la fiche est accepté comme approximation.
- La suppression après un mois fonctionne suffisamment bien avec le suivi actuel.
