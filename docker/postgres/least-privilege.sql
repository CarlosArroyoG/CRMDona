-- Usuarios de mínimo privilegio para la base del CRM
-- (docs/tecnico/proteccion-de-datos.md §2).
--
-- Se ejecuta UNA vez, como el superusuario de PostgreSQL, sobre la base del CRM:
--
--   psql -U <superusuario> -d <base> \
--        -v db=<base> -v owner_password='<contraseña crm_owner>' -v app_password='<contraseña crm_app>' \
--        -f least-privilege.sql
--
-- Resultado:
--   crm_owner  dueño del esquema; solo lo usan las migraciones al desplegar.
--   crm_app    el que usa la aplicación (app, worker, scheduler): lee y escribe
--              filas, sin crear/borrar tablas, sin TRUNCATE, sin superusuario.
--              La bitácora y las notas de incidencias solo aceptan INSERT, y los
--              donativos no se pueden borrar ni aunque alguien inyecte SQL.
-- Se puede volver a ejecutar: no duplica nada y vuelve a aplicar los permisos.
-- Las contraseñas nunca se guardan en git: se pasan con -v al ejecutarlo.

\set ON_ERROR_STOP on

SELECT format('CREATE ROLE crm_owner LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', :'owner_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'crm_owner') \gexec
SELECT format('CREATE ROLE crm_app LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'crm_app') \gexec

-- Si ya existían, se actualizan sus contraseñas y atributos.
SELECT format('ALTER ROLE crm_owner LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', :'owner_password') \gexec
SELECT format('ALTER ROLE crm_app LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT', :'app_password') \gexec
ALTER ROLE crm_app SET statement_timeout = '60s';

-- Base y esquema: nadie más entra ni crea objetos.
ALTER DATABASE :"db" OWNER TO crm_owner;
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO crm_owner, crm_app;
ALTER SCHEMA public OWNER TO crm_owner;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO crm_app;

-- Tablas, vistas y funciones existentes pasan a crm_owner. Las secuencias de
-- las columnas id cambian de dueño junto con su tabla; las funciones de
-- extensiones (unaccent) se quedan como están.
DO $$
DECLARE
    item record;
BEGIN
    FOR item IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
        EXECUTE format('ALTER TABLE public.%I OWNER TO crm_owner', item.tablename);
    END LOOP;
    FOR item IN SELECT viewname FROM pg_views WHERE schemaname = 'public' LOOP
        EXECUTE format('ALTER VIEW public.%I OWNER TO crm_owner', item.viewname);
    END LOOP;
    FOR item IN
        SELECT p.oid::regprocedure AS signature
        FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public'
          AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = p.oid AND d.deptype = 'e')
    LOOP
        EXECUTE format('ALTER FUNCTION %s OWNER TO crm_owner', item.signature);
    END LOOP;
END $$;

-- Permisos de la aplicación sobre lo que ya existe…
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO crm_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO crm_app;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO crm_app;

-- …y sobre lo que creen las migraciones futuras (las ejecuta crm_owner).
ALTER DEFAULT PRIVILEGES FOR ROLE crm_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO crm_app;
ALTER DEFAULT PRIVILEGES FOR ROLE crm_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO crm_app;
ALTER DEFAULT PRIVILEGES FOR ROLE crm_owner IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO crm_app;

-- Evidencia que solo crece: además de los triggers, la aplicación no tiene
-- permiso de modificarla ni borrarla.
REVOKE UPDATE, DELETE ON audit_logs FROM crm_app;
REVOKE UPDATE, DELETE ON payment_incident_notes FROM crm_app;
REVOKE DELETE ON donations FROM crm_app;
-- El historial de migraciones solo lo toca crm_owner.
REVOKE INSERT, UPDATE, DELETE ON migrations FROM crm_app;
