#!/bin/sh
composer install \
&& ./vendor/bin/phpstan analyse ./src ./tests --level=max --no-progress -c phpstan.neon \
&& ./vendor/bin/phpcs -n --ignore=vendor --extensions=php . \
&& ./vendor/bin/phpunit
