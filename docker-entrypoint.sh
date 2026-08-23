#!/bin/sh
set -e

# Render assigns the listen port via $PORT at runtime, not build time -
# Apache's config has to be patched at container start, not baked in.
PORT="${PORT:-80}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec "$@"
