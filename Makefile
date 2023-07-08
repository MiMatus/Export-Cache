.PHONY: help

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

build-image: ## build docker image
	docker build -t export-cache:latest .

install-deps: ## install dependecies
	test
run-tests: ## run integration tests
	docker run -it --rm --name my-running-script -v "${PWD}":/usr/src/myapp -w /usr/src/myapp export-cache:latest php -d opcache.enable_cli=1 ./vendor/bin/phpunit --disallow-test-output ./tests
