# Laravel Passport pour l'authentification OAuth2 de l'API REST

Le sujet exige (§III.4) une API REST authentifiée par OAuth2, distincte de la session web Inertia utilisée par l'app principale. Rien n'est installé actuellement - `composer.json` ne référence ni `laravel/passport` ni `laravel/sanctum`.

## Considered Options

### Laravel Passport

Serveur OAuth2 complet (RFC 6749) : client credentials, authorization code, password grant, refresh tokens, scopes.

- **+** Colle littéralement à l'exigence du sujet (« OAuth2 authentication »), qui précise explicitement qu'il faudra « provide evidence that your API is truly RESTful » en soutenance - Passport donne une conformité RFC vérifiable (`/oauth/token`, `/oauth/authorize` standards), pas une réinvention maison difficile à justifier à l'oral.
- **+** Le sujet documente lui-même `POST oauth/token` « Expects client + secret, returns an auth token » - correspond exactement au grant `client_credentials` de Passport, prêt à l'emploi.
- **+** Scopes natifs pour restreindre les permissions par client si besoin plus tard (bonus).
- **−** Plus lourd à mettre en place que Sanctum : migrations dédiées, clients OAuth à créer/gérer, `php artisan passport:install`.
- **−** Le grant `client_credentials` seul n'identifie pas un utilisateur final - or le sujet exige des règles par utilisateur (« Authenticated users are allowed to retrieve any profile, but may only update their own profile »). Il faudra donc soit le grant `password` (retiré de la spec OAuth 2.1 mais toujours supporté par Passport), soit `authorization_code`, plus lourd côté client. **Point non tranché par cette ADR - voir « Conséquences ».**

### Laravel Sanctum

Tokens API « personal access tokens » simples (Bearer) - pas un serveur OAuth2 au sens strict : pas de RFC 6749, pas de notion de client avec secret.

- **+** Beaucoup plus simple à installer et utiliser (`php artisan install:api`, pas de gestion de clients OAuth).
- **+** Suffisant si le besoin réel est « un token qui identifie un utilisateur » plutôt que du vrai OAuth2.
- **−** Ne répond pas littéralement à l'exigence du sujet (« with an OAuth2 authentication ») : pas de flow OAuth2 standard, pas de `client_id`/`client_secret` ni de grants - un évaluateur strict sur la formulation du sujet pourrait le refuser en soutenance.
- **−** Le sujet documente explicitement `POST oauth/token` avec client + secret : Sanctum n'a pas nativement cette notion de client applicatif séparé de l'utilisateur.

### Implémentation manuelle du flow OAuth2

- **+** Contrôle total, aucune dépendance supplémentaire.
- **−** Réimplémente ce que Passport fait déjà (et a été audité pour) : risque de sécurité (gestion de secrets, expiration de tokens, révocation) nettement plus élevé pour un bénéfice nul. Le sujet est explicite : « the slightest security breach will give you 0 » - pas le bon endroit pour une réinvention maison.
- **−** Aucun avantage identifié par rapport à Passport ici ; option retenue uniquement pour compléter la comparaison.

## Décision

Laravel Passport.

## Conséquences

- Ajouter `laravel/passport` à `composer.json`, migrations dédiées (`oauth_clients`, `oauth_access_tokens`, etc.), lancer `php artisan passport:install`.
- **Point ouvert, à trancher séparément** : quel grant type utiliser pour obtenir un token lié à un utilisateur précis (nécessaire pour « may only update their own profile ») - `password` grant ou `authorization_code`. À planifier dans un prochain round avant d'écrire les routes API.
- Documenter le grant type choisi et le flow d'obtention de token dans la doc d'API, nécessaire pour la preuve de « vraie » API RESTful exigée en soutenance.
