#!/bin/sh
# Role dispatch for the Khronoz production image.
#
# The role is the container's command, not a flag: `web` (default), `horizon`,
# `schedule`, `migrate`. Every orchestrator already has a field for this -- Cloud
# Run args, ECS command, Kubernetes args, compose command -- so one image serves
# them all without a rebuild, and each role is one process that receives SIGTERM
# directly.
set -e

# Resolve `auto` worker counts from the cgroup quota, not the host core count.
# swoole_cpu_num() and Node's os.cpus() both report host cores: inside a
# --cpus=2 container on a 10-core host they return 10. Round up so a fractional
# quota still yields one worker.
resolve_cpus() {
    _quota=
    _period=

    if [ -r /sys/fs/cgroup/cpu.max ]; then
        # cgroup v2: "<quota|max> <period>", quota "max" meaning unlimited.
        read -r _quota _period < /sys/fs/cgroup/cpu.max
        [ "$_quota" = "max" ] && _quota=
    elif [ -r /sys/fs/cgroup/cpu/cpu.cfs_quota_us ]; then
        _quota=$(cat /sys/fs/cgroup/cpu/cpu.cfs_quota_us)
        _period=$(cat /sys/fs/cgroup/cpu/cpu.cfs_period_us)
        [ "$_quota" -le 0 ] 2>/dev/null && _quota=
    fi

    if [ -n "$_quota" ] && [ -n "$_period" ] && [ "$_period" -gt 0 ] 2>/dev/null; then
        echo $(( (_quota + _period - 1) / _period ))
        return
    fi

    nproc 2>/dev/null || echo 1
}

CPUS=$(resolve_cpus)
[ "$CPUS" -ge 1 ] 2>/dev/null || CPUS=1

[ "${OCTANE_WORKERS:-auto}" = "auto" ] && OCTANE_WORKERS="$CPUS"
[ "${OCTANE_TASK_WORKERS:-auto}" = "auto" ] && OCTANE_TASK_WORKERS="$CPUS"
[ "${SSR_WORKERS:-auto}" = "auto" ] && SSR_WORKERS="$CPUS"

export OCTANE_WORKERS OCTANE_TASK_WORKERS SSR_WORKERS

# config:cache and route:cache must happen here rather than at build time: the
# environment is injected by the orchestrator, and caching at build would bake
# the builder's own values into the image.
#
# Only the application roles below need it. The `exec "$@"` fallthrough exists so
# that `docker run <image> node --version` or `php -m` works; bootstrapping the
# framework for those would be waste at best and a failure at worst, since a
# bare `docker run` carries none of the environment config:cache reads.
case "$1" in
    web|horizon|schedule|migrate|artisan)
        if [ "${SKIP_OPTIMIZE:-false}" != "true" ]; then
            php artisan config:cache --no-ansi
            php artisan route:cache --no-ansi
        fi
        ;;
esac

case "$1" in
    web)
        # The one role that is two processes -- Octane and the Inertia SSR
        # renderer -- so it is also the one role that does not exec its process
        # directly. See docker/web.sh.
        exec /usr/local/bin/web
        ;;
    horizon)
        exec php artisan horizon
        ;;
    schedule)
        exec php artisan schedule:work --no-ansi
        ;;
    # Its own role rather than a flag on the others: migrations run on the
    # `owner` connection, which AppServiceProvider refuses to resolve outside a
    # console run, so the serving roles must never carry DB_OWNER_* at all. One
    # shot, never on start -- a migration racing N replicas during a rolling
    # deploy is a footgun. db:grant re-issues the app role's row privileges over
    # whatever tables the migration just added.
    migrate)
        php artisan migrate --force --database=owner --no-ansi
        exec php artisan db:grant --no-ansi
        ;;
    artisan)
        shift
        exec php artisan "$@"
        ;;
    *)
        exec "$@"
        ;;
esac
