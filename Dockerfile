FROM php:8.2-cli
COPY . /usr/src/myapp
WORKDIR /usr/src/myapp
RUN docker-php-ext-install opcache
RUN docker-php-ext-enable opcache

CMD [ "php", "./your-script.php" ]