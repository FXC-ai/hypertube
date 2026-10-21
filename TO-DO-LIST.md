1. Télécharger et intégrer les sous-titres externes : Le torrent peut contenir des fichiers .srt/.vtt, mais DownloadMovie ne télécharge que l’index du fichier vidéo dans DownloadMovie.php (app/Jobs/DownloadMovie.php:123). La conversion ne traite donc que les sous-titres incorporés au conteneur. Il faut :

   - sélectionner et télécharger les sous-titres anglais disponibles ;
   - convertir les fichiers externes en WebVTT/HLS ;
   - les exposer dans le lecteur.


3. Faire réellement fonctionner les jobs en arrière-plan.
4. Implémenter la suppression après un mois sans visionnage.

8. Vérifier réellement l’envoi de l’email de réinitialisation. Pour l'instant l'envoi d'email fonctionne uniquement sur l'adresse fx.coindreau@gmail.com
9. Durcir les erreurs OAuth : Le callback ne traite que InvalidStateException. Les refus utilisateur, erreurs du fournisseur, réponses incorrectes et erreurs inattendues peuvent encore produire un HTTP 500 dans SocialiteController.php (app/Http/Controllers/Auth/SocialiteController.php:180).
10. Finaliser l’identité et l’ergonomie du site ; Il reste des éléments du starter Laravel : dashboard générique, liens vers le dépôt du starter, intitulés incohérents et pages peu finalisées. Il faut aussi vérifier manuellement les résolutions mobiles et Chrome/Firefox récents.
11. Ajouter la photo de profil à l’inscription et sa validation sécurisée.
12. Garantir la politique de mot de passe dans tous les environnements.
14. Ajouter un véritable header et footer global.
15. Corriger la restriction des fournisseurs OAuth : Le Route::whereIn() isolé dans web.php (routes/web.php:16) ne contraint pas les deux routes déclarées juste avant. Un fournisseur arbitraire peut donc atteindre le contrôleur et provoquer une erreur serveur. La contrainte doit être attachée aux routes ou contrôlée explicitement dans le contrôleur.
16. Nettoyer la console navigateur : Plusieurs console.log, console.warn et console.error sont encore présents.

5. Implémenter le suivi vu/non-vu par utilisateur.
6. Ajouter des tris et filtres demandés. Il reste à couvrir plusieurs critères tels que :

   - nom ;
   - genre ;
   - note IMDb ;
   - année de production ;
   - direction du tri ;
   - filtres correspondants.
7. Implémenter la vue par défaut des médias populaires : Sans recherche, les films sont actuellement classés par date de création locale. Il faut récupérer ou stocker un critère réel de popularité provenant des sources : téléchargements, vues, seeders, score, etc.
13. Compléter les miniatures :

    - l’année de production n’est pas affichée ;
    - created_at est attendu par React mais n’est même pas envoyé par IndexMovieResource ;
    - la coche “watched” est affichée sur tous les films sans état réel.