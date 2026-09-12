#!/bin/sh
# Roles are cluster-wide; grants live in the first migration.
set -e
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" <<SQL
DO \$\$ BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${APP_DB_USERNAME}') THEN
        CREATE ROLE "${APP_DB_USERNAME}" LOGIN PASSWORD '${APP_DB_PASSWORD}';
    END IF;
END \$\$;
SQL
