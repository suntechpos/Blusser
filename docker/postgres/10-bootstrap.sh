#!/bin/sh
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
\getenv owner_password DB_OWNER_PASSWORD
\getenv app_password DB_APP_PASSWORD
SELECT format('CREATE ROLE blusser_owner LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD %L', :'owner_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'blusser_owner') \gexec
SELECT format('CREATE ROLE blusser_app LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'blusser_app') \gexec
SELECT 'CREATE ROLE blusser_security NOLOGIN NOSUPERUSER BYPASSRLS NOCREATEDB NOCREATEROLE'
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'blusser_security') \gexec
GRANT blusser_security TO blusser_owner;
ALTER DATABASE blusser OWNER TO blusser_owner;
REVOKE ALL ON DATABASE blusser FROM PUBLIC;
GRANT CONNECT ON DATABASE blusser TO blusser_app;
SELECT 'CREATE DATABASE blusser_test OWNER blusser_owner'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'blusser_test') \gexec
REVOKE ALL ON DATABASE blusser_test FROM PUBLIC;
GRANT CONNECT ON DATABASE blusser_test TO blusser_app;
SQL
for database in blusser blusser_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$database" <<'SQL'
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO blusser_app, blusser_security;
GRANT CREATE ON SCHEMA public TO blusser_security;
ALTER ROLE blusser_app SET search_path = public;
SQL
done
