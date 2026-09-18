#!/bin/sh
# Process supervision for the `web` role, and only that role.
#
# Octane and the Inertia SSR renderer share this container because the framework
# couples them: config/inertia.php points SSR at a loopback address, so the two
# must share a network namespace. Every other role is a single process that
# receives SIGTERM directly and needs none of this.
#
# runit rather than supervisord: `supervisor` on Alpine depends on python3, which
# is 48 MB of interpreter to arbitrate two processes. runit is 591 KiB and
# depends on libc alone.
set -e

SERVICE_DIR=/etc/service

# A restarted container keeps the /tmp it had, and a leftover stop flag makes
# terminate() a no-op, so the next SIGTERM is answered by SIGKILL instead.
rm -f /tmp/service-stopping /tmp/service-failed /tmp/octane.started \
    /tmp/ssr.started /tmp/octane.failures /tmp/ssr.failures

# Ordered shutdown, which is the whole reason this script exists rather than an
# exec'd `runsvdir`. Octane stops first and gets the longer grace period so
# in-flight requests finish rendering before the renderer they call is taken
# away. The flag file tells docker/finish.sh that these exits are
# deliberate and not a crash loop.
terminate() {
    if [ -f /tmp/service-stopping ]; then
        return 0
    fi

    : > /tmp/service-stopping

    /usr/sbin/sv -w 30 stop "$SERVICE_DIR/octane" || true
    /usr/sbin/sv -w 10 stop "$SERVICE_DIR/ssr" || true

    kill "$RUNSVDIR" 2>/dev/null || true
}

trap terminate TERM INT

/usr/sbin/runsvdir "$SERVICE_DIR" &
RUNSVDIR=$!

# A trap only runs between foreground commands, so `wait` is interrupted by the
# signal rather than completing -- hence the loop, and hence backgrounding
# runsvdir instead of exec'ing it.
while kill -0 "$RUNSVDIR" 2>/dev/null; do
    wait "$RUNSVDIR" && break
done

# A service that could not stay up leaves this behind before signalling PID 1.
# Exit non-zero so the orchestrator reads it as a crash rather than a clean stop.
if [ -f /tmp/service-failed ]; then
    exit 1
fi
