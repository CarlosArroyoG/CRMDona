#!/bin/sh
# Crea la base de datos que usa Pest (`crm_testing`) junto a la de desarrollo.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE crm_testing OWNER "$POSTGRES_USER";
SQL
