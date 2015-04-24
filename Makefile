
# default versions to test against
# these can be overridden by setting the environment variables in the shell
PHP_VERSION=php-5.6.8
YII_VERSION=dev-master
PGSQL_VERSION=latest


help:
	@echo "make test	- run phpunit tests using a docker environment"
	@echo "make clean	- stop docker and remove container"

test: docker-php docker-pgsql adjust-config
	composer require "yiisoft/yii2:${YII_VERSION}" --prefer-dist
	composer install --prefer-dist
	docker run --rm=true -v $(shell pwd):/opt/test --link $(shell cat tests/dockerids/pgsql):postgres yiitest/php:${PHP_VERSION} phpunit --verbose --color

adjust-config:
	#echo "<?php \$$config['databases']['pgsql']['dsn'] = 'redis';" > tests/data/config.local.php

docker-pgsql: dockerfiles
	docker pull postgres:${PGSQL_VERSION}
	docker run -d -P postgres > tests/dockerids/pgsql

docker-php: dockerfiles
	cd tests/docker/php && sh build.sh

dockerfiles:
	test -d tests/docker || git clone https://github.com/cebe/jenkins-test-docker tests/docker
	cd tests/docker && git checkout -- . && git pull
	mkdir -p tests/dockerids

clean:
	docker stop $(shell cat tests/dockerids/pgsql)
	docker rm $(shell cat tests/dockerids/pgsql)
	rm tests/dockerids/pgsql

