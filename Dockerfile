FROM php:8.1-cli
MAINTAINER Miro Cillik <miro@keboola.com>

ENV COMPOSER_PROCESS_TIMEOUT=600

# Deps
RUN apt-get update
RUN apt-get install -y wget curl make git bzip2 time libzip-dev zip unzip libssl-dev openssl vim

# PHP
RUN docker-php-ext-install sockets

# Composer
WORKDIR /root
RUN cd \
  && curl -sS https://getcomposer.org/installer | php \
  && ln -s /root/composer.phar /usr/local/bin/composer

# Main
ADD . /code
WORKDIR /code
RUN echo "memory_limit = -1" >> /usr/local/etc/php/php.ini
RUN echo "date.timezone = \"Europe/Prague\"" >> /usr/local/etc/php/php.ini
RUN composer selfupdate && composer update --no-interaction --prefer-dist

CMD php ./run.php --data=/data
