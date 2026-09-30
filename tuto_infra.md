## Les conteneurs

* **Un conteneur Nginx**, qui ouvre un port vers l’extérieur (**port 8000**) afin de recevoir les requêtes du navigateur. Nginx sert directement les fichiers statiques et demande à Laravel de générer les pages Inertia et les données.

* **Un conteneur `app`**, qui fait tourner PHP-FPM et répond aux requêtes HTTP provenant du conteneur Nginx.

* **Un conteneur `worker`**, qui assure l’exécution des jobs ajoutés à la base de données par le conteneur `app`. Le but est de lancer les conversions longues sans bloquer le site.

* **Un conteneur `frontend`**, qui fait tourner un serveur Vite. Ce serveur permet de mettre à jour en temps réel le frontend ainsi que le code produit par Wayfinder en cas d’ouverture de nouvelles routes Laravel.

## Concernant les volumes

Les conteneurs ont besoin de volumes.

Pour simplifier l’environnement de développement, on peut monter tout le code du projet dans les conteneurs `app`, `worker` et `frontend`.

En revanche, le conteneur Nginx n’a pas besoin de tout le projet. Il a seulement besoin des dossiers contenant les fichiers qu’il doit servir directement :

* `index.php`, qui se trouve à la racine du projet et permet d’effectuer les requêtes auprès du conteneur PHP-FPM (`app`) ;
* le contenu du dossier `public`, qui contient les fichiers statiques du build ;
* `C:\...\hypertube\storage\app\public`, afin de servir les manifests et les segments HLS des vidéos.

## Concernant les images

Il nous faut :

* une image Nginx ;
* une image contenant le code de l’application, qui sera utilisée pour Vite, PHP-FPM et le worker.

## Comment les conteneurs fonctionnent-ils entre eux ?

Le navigateur demande d’abord la page Laravel à Nginx. Laravel répond avec une page `app.blade.php`, qui est la single page de l’application.

Le code `@vite` présent dans `app.blade.php` ajoute l’adresse du serveur Vite dans le HTML. Le navigateur sait donc qu’il doit charger les fichiers frontend depuis `localhost:5173`.

Nginx communique avec PHP-FPM via le nom Docker `app:9000`.

### Exemple : connexion au site

Imaginons que l’utilisateur se connecte au site `http://localhost:8000`.

Nginx demande au conteneur `app` ce qu’il doit renvoyer. Le conteneur `app` lui envoie alors une page HTML produite grâce à `app.blade.php`. Cette page contient le point de montage Inertia ainsi que les balises générées par Vite. Le code React chargé par Vite prend ensuite le contrôle de l’interface.

L’utilisateur clique ensuite sur le lien permettant de se connecter. La fonction générée par Wayfinder est exécutée par le navigateur et transmet une requête à Nginx. Nginx fait alors appel à `app` pour obtenir la réponse Inertia. `app` lui fournit cette réponse, puis Nginx la renvoie au navigateur.

### Exemple : conversion d’un film

Imaginons maintenant que l’utilisateur veuille lancer la conversion d’un film.

Le fonctionnement est similaire : le navigateur envoie une requête à Nginx, qui la transmet à `app`. `app` ajoute un job dans la table des jobs, puis le conteneur `worker` lance la conversion dans un autre processus afin de ne pas bloquer Laravel.

Si l’utilisateur souhaite regarder le film pendant sa conversion, Nginx se charge de lui envoyer le manifest et les segments HLS disponibles.

Lorsque l’utilisateur clique sur le bouton Play du lecteur vidéo, cela déclenche une requête HTTP de type `/storage/`, qui possède un lien symbolique vers `storage/app/public`, correspondant au disque contenant les fichiers vidéo et les segments HLS.

## Ebauche de la solution :

### Dockerfile de l'imgage pour l'app, le worker et Vite :
```
  FROM php:8.5-fpm

  RUN apt-get update && apt-get install -y \
      curl \
      ffmpeg \
      git \
      libzip-dev \
      unzip \
      && docker-php-ext-install \
          pdo_sqlite \
          pcntl \
          bcmath \
          zip \
      && rm -rf /var/lib/apt/lists/*

  COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

  RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
      && apt-get install -y nodejs

  WORKDIR /var/www/html

  COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
  RUN chmod +x /usr/local/bin/entrypoint.sh

  ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

  CMD ["php-fpm", "-F"]
```
### entrypoint 
```
#!/bin/sh

  set -e

  mkdir -p \
      storage/framework/views \
      storage/framework/cache \
      storage/framework/sessions \
      storage/logs \
      bootstrap/cache

  exec "$@"
```
  Ce script est commun aux trois conteneurs.

  La commande finale dépend du service :

  app      → php-fpm -F
  worker   → php artisan queue:work
  frontend → npm run dev


## docker-compose :

```
  services:
    nginx:
      image: nginx:alpine
      ports:
        - "8000:80"
      volumes:
        - ./public:/var/www/html/public:ro
        - ./storage/app/public:/var/www/html/storage/app/public:ro
        - ./docker/nginx/conf.d/default.conf:/etc/nginx/conf.d/default.conf:ro
      depends_on:
        - app

    app:
      build:
        context: .
        dockerfile: Dockerfile
      command: ["php-fpm", "-F"]
      volumes:
        - .:/var/www/html
      depends_on:
        - worker

    worker:
      build:
        context: .
        dockerfile: Dockerfile
      command:
        [
          "php",
          "artisan",
          "queue:work",
          "--tries=1",
          "--timeout=0"
        ]
      volumes:
        - .:/var/www/html

    frontend:
      build:
        context: .
        dockerfile: Dockerfile
      command:
        [
          "npm",
          "run",
          "dev",
          "--",
          "--host=0.0.0.0"
        ]
      ports:
        - "5173:5173"
      volumes:
        - .:/var/www/html
      depends_on:
        - app
```
  Les montages sont donc :

  app :
  . → /var/www/html

  worker :
  . → /var/www/html

  frontend :
  . → /var/www/html

  nginx :
  ./public
      → /var/www/html/public

  ./storage/app/public
      → /var/www/html/storage/app/public

  Nginx ne reçoit pas le projet complet. Il reçoit uniquement les deux dossiers dont il a besoin.

### docker/nginx/conf.d/default.conf
```
  server {
      listen 80;
      server_name localhost;

      root /var/www/html/public;
      index index.php;

      location /storage/ {
          alias /var/www/html/storage/app/public/;
      }

      location / {
          try_files $uri $uri/ /index.php?$query_string;
      }

      location ~ \.php$ {
          include fastcgi_params;
          fastcgi_pass app:9000;
          fastcgi_param SCRIPT_FILENAME /var/www/html/public$fastcgi_script_name;
      }

      location ~ /\. {
          deny all;
      }
  }
```