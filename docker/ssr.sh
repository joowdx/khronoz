#!/bin/sh
# stderr onto stdout so both reach the container's log stream unsplit.
exec 2>&1

date +%s > /tmp/ssr.started

# node directly, not `php artisan inertia:start-ssr`: that command runs
# `node <bundle>` as a child and relays its output, costing a resident PHP
# process for output runit already captures.
exec node /usr/local/bin/cluster.mjs
