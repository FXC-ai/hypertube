1. Télécharger et intégrer les sous-titres externes : améliorer la priorité


3. Faire réellement fonctionner les jobs en arrière-plan.


/usr/bin/gh auth git-credential get: 1: /usr/bin/gh: not found

16. Nettoyer la console navigateur : Plusieurs console.log, console.warn et console.error sont encore présents.

____________________________________________________________________________________________________________________________________________________________________

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