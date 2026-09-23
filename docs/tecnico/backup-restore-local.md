# Backup y restore PostgreSQL local

Procedimiento probado el 2026-09-23 con bases desechables. Nunca usar `crm` para estas operaciones.

## Variables

Usar bases separadas, por ejemplo `crm_backup_source` y `crm_backup_restore`, con el usuario PostgreSQL configurado en `.env`.

## Procedimiento

```powershell
docker compose exec -e DB_DATABASE=crm_backup_source app php artisan migrate:fresh --seed --force

docker compose exec postgres psql -U crm -d crm_backup_source -c "insert into users (name, email, password, role, created_at, updated_at) values ('Backup Proof', 'backup-proof-20260923@example.test', 'backup-proof-password', null, now(), now()) on conflict (email) do nothing;"

docker compose exec postgres sh -c "pg_dump -U crm -Fc crm_backup_source > /tmp/crm_backup_20260923.dump"
docker compose exec postgres dropdb -U crm --if-exists crm_backup_restore
docker compose exec postgres createdb -U crm crm_backup_restore
docker compose exec postgres pg_restore -U crm -d crm_backup_restore /tmp/crm_backup_20260923.dump

docker compose exec postgres psql -U crm -d crm_backup_restore -c "select to_regclass('public.users'); select name, email from users where email = 'backup-proof-20260923@example.test'; select count(*) from migrations;"
```

La prueba produjo la tabla `users`, la fila identificable y 30 migraciones en la base restaurada. Después se eliminaron las dos bases y el dump temporal:

```powershell
docker compose exec postgres dropdb -U crm --if-exists crm_backup_source
docker compose exec postgres dropdb -U crm --if-exists crm_backup_restore
docker compose exec postgres rm -f /tmp/crm_backup_20260923.dump
```

El procedimiento solo demuestra backup/restore local. S3, retención, cifrado gestionado y restauración en producción permanecen `[S]`.
