# Plugin "docker compose" (v2) si disponible, sinon binaire "docker-compose" (v1).
# Surchargeable : make up COMPOSE=docker-compose
ifeq ($(origin COMPOSE),undefined)
COMPOSE := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
endif

# Nom du projet compose, utilisé pour filtrer les volumes. La v1 retire tout sauf [a-z0-9] du
# nom du dossier, la v2 garde les tirets et underscores.
ifeq ($(COMPOSE),docker-compose)
PROJECT ?= $(shell basename "$(CURDIR)" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9')
else
PROJECT ?= $(shell basename "$(CURDIR)" | tr '[:upper:]' '[:lower:]')
endif

# Paramètres du test manuel de l'API du Client Torrent (make api-test).
# Surchargeables : make api-test TORRENT_URL=... OUTPUT_DIR=...
API ?= http://localhost:7881
TORRENT_URL ?= https://archive.org/download/1953_Movie_Trailers_Starting_Monday/1953_Movie_Trailers_Starting_Monday_archive.torrent
OUTPUT_DIR ?= /var/www/html/storage/app/public/movies/999

.DEFAULT_GOAL := help

.PHONY: help up down build logs ps restart clean fclean volumes images \
	client-up client-logs client-shell \
	torrent-dev torrent-test torrent-test-all torrent-lint \
	api-health api-test api-cancel

help: ## Liste les commandes
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-18s %s\n", $$1, $$2}'

## Stack Docker

up: ## Construit et démarre toute la stack (app + client-torrent)
	$(COMPOSE) up -d --build

down: ## Arrête et supprime les conteneurs (volumes et images conservés)
	$(COMPOSE) down

build: ## Construit les images sans démarrer
	$(COMPOSE) build

logs: ## Suit les logs de tous les services
	$(COMPOSE) logs -f

ps: ## État des conteneurs
	$(COMPOSE) ps

restart: ## Redémarre les conteneurs
	$(COMPOSE) restart

clean: down ## Alias de down

fclean: ## DESTRUCTIF : supprime conteneurs, volumes (base SQLite, films) et images du projet
	@echo "Ceci supprime les conteneurs, les images ET les volumes nommés de ce projet ($(PROJECT))."
	@echo "Les volumes contiennent la base SQLite et les films téléchargés. Rien n'est récupérable."
	@read -p "Taper 'oui' pour confirmer : " ans; [ "$$ans" = "oui" ] || { echo "Annulé."; exit 1; }
	$(COMPOSE) down --volumes --rmi all --remove-orphans

volumes: ## Liste les volumes Docker du projet
	docker volume ls --filter label=com.docker.compose.project=$(PROJECT)

images: ## Liste les images du projet
	$(COMPOSE) images

## Client Torrent (Docker)

client-up: ## Reconstruit et relance uniquement le Client Torrent
	$(COMPOSE) up -d --build client-torrent

client-logs: ## Suit les logs du Client Torrent
	$(COMPOSE) logs -f client-torrent

client-shell: ## Ouvre un shell dans le conteneur du Client Torrent
	$(COMPOSE) exec client-torrent sh

## Client Torrent (sans Docker, Node 20 ou plus)

torrent-dev: ## Lance le service en local sur le port 7881 (page de test : http://localhost:7881/)
	cd torrent-client && npm start

torrent-test: ## Tests unitaires (rapides, sans réseau)
	cd torrent-client && node --test $$(ls test/*.test.js test/*/*.test.js | grep -v integration)

torrent-test-all: ## Tous les tests, dont le réseau réel (archive.org, Sintel), plus de 2 min
	cd torrent-client && npm test

torrent-lint: ## ESLint sur torrent-client/ (config racine)
	npx eslint torrent-client

## Test manuel de l'API (le service doit tourner : make up ou make torrent-dev)

api-health: ## GET /health
	curl -sf $(API)/health && echo || { echo "Service injoignable sur $(API)"; exit 1; }

api-test: ## Télécharge le torrent archive.org par défaut et suit la progression
	@id=$$(curl -sf -X POST $(API)/downloads -H 'Content-Type: application/json' \
		-d '{"torrentUrl":"$(TORRENT_URL)","outputDir":"$(OUTPUT_DIR)"}' \
		| sed -n 's/.*"id":"\([^"]*\)".*/\1/p'); \
	[ -n "$$id" ] || { echo "POST /downloads a échoué (service lancé ? URL du .torrent correcte ?)"; exit 1; }; \
	echo "job $$id"; \
	while :; do \
		s=$$(curl -sf $(API)/downloads/$$id); echo "$$s"; \
		case "$$s" in *'"status":"downloading"'*) sleep 2;; *) break;; esac; \
	done; \
	case "$$s" in \
		*'"status":"completed"'*) echo "OK - fichiers dans $(OUTPUT_DIR)"; $(COMPOSE) exec -T app ls -la $(OUTPUT_DIR) 2>/dev/null || true;; \
		*) echo "ECHEC"; exit 1;; \
	esac

api-cancel: ## Annule un job : make api-cancel ID=<id>
	@[ -n "$(ID)" ] || { echo "Usage : make api-cancel ID=<id du job>"; exit 1; }
	curl -s -X DELETE $(API)/downloads/$(ID) && echo
