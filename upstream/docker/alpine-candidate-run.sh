#!/bin/bash
# Exit if either server dies so Docker can restart the complete bounded service.
set -euo pipefail
mkdir -p /run/apache2
php-fpm --test
httpd -t
php-fpm --nodaemonize &
fpm_pid=$!
httpd -DFOREGROUND &
apache_pid=$!
cleanup(){ kill "$fpm_pid" "$apache_pid" 2>/dev/null || true; wait "$fpm_pid" "$apache_pid" 2>/dev/null || true; }
trap cleanup EXIT
trap 'exit 0' TERM INT
set +e
wait -n "$fpm_pid" "$apache_pid"
# Even a clean unsolicited child exit must restart both services.
exit 1
