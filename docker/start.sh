#!/bin/sh
set -e

# Start php-fpm in background
php-fpm -D

# Run nginx in foreground (keeps the container alive)
exec nginx -g 'daemon off;'
