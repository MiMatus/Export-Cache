.PHONY: help tests phpstan phpcs fix-code-style install benchmark

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

build: ## build docker image
	docker build -t export-cache .

tests: ## run integration tests
	docker run --rm export-cache php ./vendor/bin/phpunit --disallow-test-output ./tests/Integration

phpstan: ## staticly analyze code
	docker run --rm export-cache php ./vendor/bin/phpstan analyse -c phpstan.neon

phpcs: ## checks codestyle complaince
	docker run --rm export-cache php ./vendor/bin/phpcs --extensions=php --standard=phpcs.xml -sp src tests

fix-code-style: ## fix codestyle according to phpcs ruleset
	docker run --rm -v "${PWD}":/app export-cache php ./vendor/bin/phpcbf --extensions=php --standard=phpcs.xml src tests

benchmark: ## run benchmarks
	docker run --rm -v "${PWD}":/app export-cache php ./vendor/bin/phpbench run tests/Performance --report=aggregate --filter=benchSet

install: ## install composer dependecies
	docker run --rm -v "${PWD}":/app composer install
