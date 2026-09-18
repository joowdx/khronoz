#!/bin/sh
exec 2>&1

# Octane renders SSR-enabled Inertia pages through INERTIA_SSR_URL, so it must
# not accept traffic before the renderer answers there. runsvdir starts both
# services at once, so the ordering is enforced here, on every start and restart.
while ! php -r 'exit(@fsockopen("127.0.0.1", (int) getenv("INERTIA_SSR_PORT"), $errno, $error, 1) ? 0 : 1);' 2>/dev/null; do
    sleep 1
done

date +%s > /tmp/octane.started

exec php /app/artisan octane:start \
    --server=swoole \
    --host=0.0.0.0 \
    --port="${PORT}" \
    --workers="${OCTANE_WORKERS}" \
    --task-workers="${OCTANE_TASK_WORKERS}" \
    --max-requests="${OCTANE_MAX_REQUESTS}"
