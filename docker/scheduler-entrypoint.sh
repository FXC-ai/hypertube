#!/usr/bin/env bash

set -euo pipefail

cd /var/www/html

cleanup_time="${MOVIE_CLEANUP_TIME:-03:00}"

if [[ ! "${cleanup_time}" =~ ^([01][0-9]|2[0-3]):([0-5][0-9])$ ]]; then
    echo "MOVIE_CLEANUP_TIME must use the HH:MM format." >&2
    exit 1
fi

hour="${BASH_REMATCH[1]}"
minute="${BASH_REMATCH[2]}"

{
    echo 'SHELL=/bin/bash'
    echo 'HOME=/home/webapp'
    echo 'PATH=/usr/local/bin:/usr/bin:/bin'
    echo "${minute} ${hour} * * * webapp cd /var/www/html && /usr/local/bin/php artisan movies:purge-stale --isolated --no-interaction >> /var/www/html/storage/logs/scheduler.log 2>&1"
} > /etc/cron.d/hypertube

chmod 0644 /etc/cron.d/hypertube

echo "Movie cleanup scheduled every day at ${cleanup_time} (container time)."

exec /usr/sbin/cron -f