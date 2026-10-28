# Afficher une erreur de téléchargement sur la page du film

## Objectif

Quand le Client Torrent échoue en arrière-plan, afficher un message simple :

> This movie is not available at the moment. Please try again later.

Le détail technique reste dans `movies.download_error` et dans les logs. Il ne doit pas être affiché à l'utilisateur.

Ce changement concerne la partie vidéo du sujet (III.3) et évite qu'un échec reste invisible dans l'interface, conformément aux vérifications d'erreurs de la section V.1.

## 1. Transmettre un message public avec les props Inertia

Dans `app/Http/Controllers/MovieController.php`, remplacer la prop `download_error` actuelle par :

```php
'download_error' => match ($movie->download_status) {
    DownloadStatus::Failed => 'This movie is not available at the moment. Please try again later.',
    DownloadStatus::Unsupported => $movie->download_error,
    default => null,
},
```

Le message technique d'origine reste ainsi disponible pour le diagnostic, sans exposer une URL ou une erreur interne au navigateur.

## 2. Traiter le statut `failed` dans React

Dans `resources/js/pages/movies/show.tsx`, ajouter :

```tsx
const downloadFailed = moviePageData.download_status === 'failed';
```

Adapter ensuite le libellé du statut :

```tsx
const statusLabel = isPurging
    ? 'Cleaning up stored video'
    : isUnsupported
        ? 'Unsupported torrent'
        : downloadFailed
            ? 'Unavailable'
            : conversionStatusLabel[moviePageData.conversion_status];
```

Afficher l'erreur de téléchargement avant l'erreur de conversion :

```tsx
const displayedError = isPurging
    ? null
    : isUnsupported || downloadFailed
        ? moviePageData.download_error
        : moviePageData.conversion_error;
```

Dans le bloc du lecteur, ajouter le cas `downloadFailed` pour afficher :

```text
This movie is not available at the moment.
Please try again later.
```

## 3. Arrêter le polling après l'échec

Le job est asynchrone : `usePoll` doit continuer jusqu'à ce que Laravel renvoie le statut `failed`, puis s'arrêter.

Ajouter `downloadFailed` à la condition du `useEffect` :

```tsx
if (
    !isPurging &&
    (
        isUnsupported ||
        downloadFailed ||
        moviePageData.conversion_status === 'failed' ||
        (videoIsPlayable && subtitlesAreTerminal)
    )
) {
    stop();
}
```

Ajouter aussi `downloadFailed` au tableau des dépendances du `useEffect`.

Le bouton doit rester disponible afin de permettre une nouvelle tentative si l'indisponibilité était temporaire. Adapter son icône et son texte :

```tsx
{downloadFailed || moviePageData.conversion_status === 'failed' ? (
    <RotateCcw />
) : (
    <Play />
)}
{downloadFailed || moviePageData.conversion_status === 'failed'
    ? 'Retry'
    : 'Prepare video'}
```

## 4. Ajouter un test Pest

Dans `tests/Feature/Movies/MovieControllerTest.php`, ajouter un test qui crée un film avec :

```php
'download_status' => DownloadStatus::Failed,
'download_error' => 'Client Torrent answered 502: HTTP 403',
```

Puis vérifier la réponse Inertia :

```php
->where('moviePageData.download_status', DownloadStatus::Failed->value)
->where(
    'moviePageData.download_error',
    'This movie is not available at the moment. Please try again later.',
)
```

Cela vérifie également que le détail technique n'est pas envoyé au front.

## 5. Vérifier

Lancer le test ciblé :

```bash
php artisan test --compact tests/Feature/Movies/MovieControllerTest.php
```

Puis ouvrir un film dont `download_status` vaut `failed`. La page doit afficher le message, arrêter son polling et conserver la possibilité de réessayer.
