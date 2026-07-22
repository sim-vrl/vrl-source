FROM php:8.2-apache-bookworm

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf
RUN apt-get update
RUN apt-get install -y \
  git \
  zip \
  curl \
  sudo \
  unzip \
  libicu-dev \
  libbz2-dev \
  g++
# libpng-dev, libjpeg-dev, libfreetype6-dev, libmcrypt-dev, libreadline-dev
# removed (D-06): none map to an installed/enabled extension.
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli
RUN docker-php-ext-install \
  bz2 \
  intl \
  bcmath \
  opcache \
  calendar \
  pdo_mysql

# 2. set up document root for apache
COPY /000-default.conf /etc/apache2/sites-available/000-default.conf

# 3. mod_rewrite for URL rewrite and mod_headers for .htaccess extra headers like Access-Control-Allow-Origin-
RUN a2enmod rewrite headers

# 4. start with base php config, then add extensions
# php.ini-production chosen over php.ini-development (D-05): its stock defaults
# already match nearly every phpinfo.md-captured directive (opcache.*, error_reporting,
# zend.exception_ignore_args) without any conf.d override needed for those.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Must be named to sort AFTER docker-php-ext-*.ini alphabetically (a numeric
# prefix like 99-app.ini would sort BEFORE them and lose).
COPY docker/conf.d/zz-app.ini /usr/local/etc/php/conf.d/zz-app.ini

# 5. Composer
RUN curl -sS https://getcomposer.org/installer | php
RUN mv composer.phar /usr/local/bin/composer
RUN chmod +x /usr/local/bin/composer
RUN composer self-update

COPY . /var/www/html/

EXPOSE 80