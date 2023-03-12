.PHONY: help

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

run-tests: ## run integration tests
	docker run -it --rm --name my-running-script -v "${PWD}":/usr/src/myapp -w /usr/src/myapp php:8.2-cli php ./vendor/bin/phpunit --disallow-test-output ./tests
