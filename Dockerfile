# Adjust the version from https://hub.docker.com/_/wordpress
FROM wordpress:6.9.1-php8.4-apache
 
COPY php.ini $PHP_INI_DIR/conf.d/
