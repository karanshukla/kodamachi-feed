#!/bin/sh
set -e

# Railway (and most PaaS) assign a port at runtime rather than at build time.
if [ -n "${PORT}" ]; then
    export SERVER_NAME=":${PORT}"
fi

# Migrations are idempotent and the database may be a fresh volume, so this
# runs on every boot rather than at build time -- the image has no volume.
# --force because there is no TTY here: migrate:up is marked destructive and
# would otherwise block on a confirmation prompt nobody can answer.
php /app/tempest migrate:up --force

exec supervisord -c /etc/supervisor/supervisord.conf
