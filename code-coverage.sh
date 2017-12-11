#!/bin/sh
composer install \
&& ./vendor/bin/phpunit --coverage-clover build/logs/clover.xml \
&& ./vendor/bin/test-reporter
