#!/bin/sh
# runsv runs this every time a service's ./run exits, with the service directory
# as the working directory.
#
# runit restarts a failed service forever; supervisord did not. Three starts that
# died inside `startsecs` put a program in FATAL, and an event listener brought
# the container down with it. This reproduces that, so a web container whose
# Octane cannot bind its port, or whose SSR bundle is broken, exits and is
# replaced -- rather than looping while /up answers for a process that is not
# actually serving.
name=${PWD##*/}
failures_file=/tmp/${name}.failures

# Deliberate stops during shutdown are not failures.
if [ -f /tmp/service-stopping ]; then
    exit 0
fi

started=$(cat "/tmp/${name}.started" 2>/dev/null || echo 0)

# Stayed up long enough to count as a real start: forget earlier failures. This
# is supervisord's `startsecs`, which was 3s for ssr and 5s for octane.
if [ "$(( $(date +%s) - started ))" -ge 5 ]; then
    echo 0 > "$failures_file"

    exit 0
fi

failures=$(( $(cat "$failures_file" 2>/dev/null || echo 0) + 1 ))
echo "$failures" > "$failures_file"

if [ "$failures" -ge 3 ]; then
    echo "${name} died within 5s three times; stopping the container" >&2
    : > /tmp/service-failed
    kill -TERM 1
fi
