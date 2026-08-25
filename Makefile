.PHONY: up down logs ps test analyse migrate seed serve shell horizon

up:            ## Start the full dev stack (postgres, redis, app, nginx, worker)
	docker compose up -d --build

down:          ## Stop the dev stack
	docker compose down

logs:          ## Tail logs
	docker compose logs -f

ps:            ## Show service status
	docker compose ps

env-test-db:   ## Ensure the test database exists
	docker compose exec -T postgres createdb -U buddypay buddypay_test 2>/dev/null || true

test: env-test-db ## Run the automated test suite (PostgreSQL-backed)
	docker compose exec -T app php artisan test

analyse:       ## PHPStan (Larastan) + Pint
	docker compose exec -T app /var/www/html/vendor/bin/phpstan analyse --memory-limit=1G
	docker compose exec -T app /var/www/html/vendor/bin/pint --test

migrate:       ## Run migrations
	docker compose exec -T app php artisan migrate --force

seed:          ## Seed system accounts + bill catalog
	docker compose exec -T app php artisan db:seed --force

serve:         ## Serve the API with plain PHP (no nginx)
	docker compose exec -T app php artisan serve --host=0.0.0.0 --port=8000

shell:         ## Tinker
	docker compose exec -T app php artisan tinker

horizon:       ## Tail horizon workers
	docker compose logs -f worker
