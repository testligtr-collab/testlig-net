# Make is optional; Windows users should prefer Composer scripts in composer.json.

.PHONY: setup start stop test stan cs-check cs-fix doctrine-validate migrate cache-clear

setup:
	composer install

start:
	docker compose up -d

stop:
	docker compose down

test:
	composer test

stan:
	composer stan

cs-check:
	composer cs:check

cs-fix:
	composer cs:fix

doctrine-validate:
	composer doctrine:validate

migrate:
	composer migrate

cache-clear:
	composer cache:clear
