#!/bin/sh
set -e

# Render assigns the listen port via $PORT at runtime, not build time -
# Apache's config has to be patched at container start, not baked in.
# For Docker-runtime services Render doesn't actually inject $PORT (only
# native runtimes get that) - it just expects the container listening on
# 10000 unconditionally, so that's the fallback here, not 80. Confirmed
# by a real failed deploy: the health checker hit
# nexapos-platform.onrender.com:10000 and timed out against an Apache
# that was still listening on 80.
PORT="${PORT:-10000}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec "$@"
