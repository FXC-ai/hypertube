init:
	docker compose up -d --build --scale worker-conversions=3
	docker compose exec app php artisan migrate:fresh --seed
	

import-movies:
	docker compose exec app php artisan import:wikidata-movies

logs-workers:
	docker compose logs -f worker-monitoring worker-subtitles worker-conversions

restart-workers:
	docker compose restart worker-monitoring worker-subtitles worker-conversions

start:
	docker compose up

stop:
	docker compose down

clean:
	docker compose down -v
	find storage/app/public/movies -mindepth 1 -maxdepth 1 -type d ! -name '1' -exec rm -rf -- {} +
	rm -rf -- storage/app/public/movies/1/hls
	find storage/app/public/avatars -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +

fclean: clean
	docker system prune -a

test:
	docker compose exec app php artisan test

show-movies:
	docker compose exec app php artisan tinker --execute='dump(App\Models\MovieData::all()->toArray());'

demo-scheduler:
	docker compose exec app php artisan tinker --execute="App\Models\Movie::where('id', '$(ARG)')->update(['last_watched_at' => '2000-01-01']);"
	docker compose exec app php artisan tinker --execute="print_r(App\Models\Movie::where('id', '$(ARG)')->first()?->toArray());"
	docker compose exec --user webapp scheduler php artisan movies:purge-stale --isolated
	docker compose exec app php artisan tinker --execute="print_r(App\Models\Movie::where('id', '$(ARG)')->first()?->toArray());"
