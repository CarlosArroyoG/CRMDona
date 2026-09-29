# Protección de la base de datos de donantes

Defensa en capas (2026-09-29). La idea es que ninguna falla aislada exponga los datos de los
donantes. Si una capa se rompe, la siguiente sigue protegiendo.

| Capa | Qué protege | Dónde |
|---|---|---|
| 1. Aplicación | Inyección SQL, XSS, accesos sin permiso | Código del CRM (ya aplicado) |
| 2. Usuario de base de mínimo privilegio | Que un fallo de la app borre, altere o robe con superusuario | PostgreSQL (§2) |
| 3. Cifrado de datos sensibles | Que una copia robada de la base sea legible | Código del CRM (§3) |
| 4. Red | Que alguien llegue a PostgreSQL o Redis desde Internet | Coolify y servidor (§4) |
| 5. Servidor, respaldos y llaves | Robo del disco, respaldos o `APP_KEY` | Institución (§5) |
| 6. Personas | Cuentas robadas o abuso interno | MFA, roles y bitácora (§6) |

Verificación después de cada despliegue:

```
php artisan app:security-check
```

Revisa `APP_DEBUG`, la cookie segura, la sesión cifrada, la contraseña de Redis y que el usuario de la
base no sea superusuario ni dueño de las tablas. Termina con error si algo falla.

## 1. Aplicación (ya aplicado)

- **Consultas parametrizadas:** SQL crudo solo con fragmentos fijos. Las pruebas de ataque (`tests/Feature/Security/AttackSimulationTest.php`) cubren:
  - inyección con mayúsculas, comentarios, Unicode y `pg_sleep`;
  - suplantación de IP;
  - ráfagas y fuerza bruta.
- **Accesos:** MFA obligatorio, también en las descargas fuera del panel. Permisos por rol en cada acción y pantalla.
- **Límites por IP:** envíos y páginas públicas, webhooks, login y baja.
- **Bitácora inmutable:** trigger en la base.
- **Producción:** no arranca con `APP_DEBUG=true` y la cookie de sesión va solo por HTTPS.

## 2. Usuarios de PostgreSQL de mínimo privilegio

La imagen oficial de PostgreSQL crea un **superusuario** (`POSTGRES_USER`). Si la aplicación usara ese
usuario, un fallo de la app permitiría:
- borrar tablas;
- leer archivos del servidor;
- ejecutar comandos con `COPY ... TO PROGRAM`.

Por eso se separan tres usuarios:

| Usuario | Para qué | Puede |
|---|---|---|
| Superusuario (`POSTGRES_USER`) | Solo administración de emergencia | Todo. Su contraseña se guarda fuera de Coolify, en el gestor de contraseñas de la institución |
| `crm_owner` | Migraciones al desplegar | Crear y alterar tablas del esquema del CRM. No es superusuario |
| `crm_app` | La aplicación (app, worker, scheduler) | Leer y escribir filas. **No** puede crear, alterar, vaciar ni borrar tablas, ejecutar comandos, leer archivos, crear usuarios, tocar la bitácora ni borrar donativos |

Verificado contra PostgreSQL real el 2026-09-29. Con `crm_app` quedaron bloqueados:
- `DROP TABLE`, `TRUNCATE`, `ALTER TABLE` y `CREATE TABLE/FUNCTION`;
- `COPY ... TO PROGRAM` y `pg_read_file`;
- `CREATE ROLE`;
- borrar o modificar `audit_logs`, borrar `donations` y alterar `migrations`.

### Procedimiento (una sola vez)

1. Generar dos contraseñas largas y aleatorias, por ejemplo con `openssl rand -hex 24`. Guardarlas en el gestor de contraseñas.
2. En la terminal del recurso `postgres` de Coolify, ejecutar como superusuario:

   ```
   psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
        -v db="$POSTGRES_DB" -v owner_password='<crm_owner>' -v app_password='<crm_app>' \
        -f least-privilege.sql
   ```

   El script está en `docker/postgres/least-privilege.sql`. Se puede volver a ejecutar sin daño.
3. Variables de los recursos en Coolify:

   | Recurso | Variables |
   |---|---|
   | `app` | `DB_USERNAME=crm_app`, `DB_PASSWORD=<crm_app>`, `DB_MIGRATION_USERNAME=crm_owner`, `DB_MIGRATION_PASSWORD=<crm_owner>` |
   | `worker` y `scheduler` | `DB_USERNAME=crm_app` y `DB_PASSWORD=<crm_app>`. **Sin** las variables de migración |

4. Redesplegar. `docker/entrypoint.sh` migra con `crm_owner` y después guarda la configuración con `crm_app`.
5. `php artisan app:security-check` debe salir en verde.

Opcional, para más protección: después de cada despliegue, quitar `DB_MIGRATION_*` del recurso `app` y
ponerlas solo al migrar. Así ni siquiera una ejecución de código en el contenedor obtiene la contraseña
del dueño.

En local y en las pruebas se sigue usando el superusuario, porque las pruebas recrean las tablas.

## 3. Cifrado de datos sensibles

Cifrados con `APP_KEY` (AES-256-CBC con MAC; cast `encrypted` de Laravel):

| Tabla | Columnas |
|---|---|
| `donor_tax_profiles` | RFC, nombre fiscal, régimen, CP fiscal, uso de CFDI |
| `donors` | teléfono, notas |
| Ya estaban | contraseña SMTP, secretos y códigos del MFA, enlaces de pago |

- **Huella del RFC** (`rfc_hash`, HMAC-SHA256 con una llave derivada de `APP_KEY`): permite detectar RFC duplicados y buscar un RFC **completo** sin descifrar. Un fragmento del RFC ya no se encuentra.
- **Legibles a propósito:** nombre, correo y fecha de nacimiento, porque la búsqueda sin acentos, los cumpleaños y los envíos los necesitan. Los protegen las capas 2, 4, 5 y 6.
- **Bitácora:** los campos fiscales se registran solo por nombre, nunca su valor, ni legible ni cifrado.
- **`APP_KEY` es la llave de todo:**
  - si se pierde, esos datos no se recuperan;
  - si se filtra junto con la base, se pueden descifrar.

  Se guarda en el gestor de contraseñas de la institución, **nunca junto a los respaldos de la base**.
- **Rotar `APP_KEY`** exige volver a cifrar y recalcular `rfc_hash`: no hacerlo sin un procedimiento probado (pendiente #44).
- **Despliegue con el CRM en producción:** Coolify despliega en cada push a `main`. Mientras la migración cifra, el contenedor anterior sigue atendiendo, y lo que guarde en esos segundos puede quedar legible.
  - `app:encrypt-legacy-data` cifra lo pendiente y completa `rfc_hash`. Corre solo cada 15 minutos y también se puede lanzar a mano.
  - `app:security-check` avisa si queda algo legible.
  - Antes del push:
    1. respaldo de la base;
    2. confirmar que `APP_KEY` es fija en Coolify y está guardada aparte;
    3. hacerlo en horario de poco uso.

## 4. Red

- **PostgreSQL y Redis sin puertos publicados:** solo en la red interna de Coolify. El `docker-compose.yml` publica el 5432 solo para desarrollo local.
- **Redis con contraseña** (`REDIS_PASSWORD`): guarda sesiones y trabajos de la cola.
- **Firewall del servidor:** solo 80 y 443 abiertos a Internet. El 22 (SSH) limitado a las IP de quien administra, y el panel de Coolify igual (o detrás de VPN).
- **Cloudflare u otro proxy delante del dominio:** oculta la IP real del servidor y absorbe DDoS volumétricos (pendiente #58).
- **Si PostgreSQL vive en otro servidor:** `DB_SSLMODE=verify-full`, con el certificado del servidor.

## 5. Servidor, respaldos y llaves

- **Disco cifrado** del servidor o volumen (función del proveedor de VPS o LUKS).
- **SSH solo con llaves**, sin contraseña ni acceso directo de `root`. Actualizaciones de seguridad automáticas y `fail2ban`.
- **Coolify:** cuenta de administración con 2FA y usuarios nominales.
- **Respaldos** (pendiente #4):
  - `pg_dump` diario **cifrado** (por ejemplo con `age` o `gpg`) antes de salir del servidor;
  - destino S3 externo con versionado o bloqueo de objetos (protege contra ransomware);
  - prueba de restauración periódica;
  - la llave del cifrado de respaldos y `APP_KEY` se guardan aparte.
- **Registro de conexiones de PostgreSQL** (`log_connections`, `log_disconnections`) para detectar accesos inusuales.

## 6. Personas

- **MFA obligatorio** para los cuatro roles. Pocos Administradores y cuentas nominales (nunca compartidas).
- **Salidas del personal:** desactivar la cuenta el mismo día (**Usuarios → Desactivar**).
- **Exportaciones:** solo Administrador, Coordinador y Contador; el archivo se borra a los 7 días y solo lo descarga quien lo generó. Revisar la bitácora periódicamente.
- **Rotar la `sk_test_`** de Stripe expuesta en pruebas (#14) y cualquier llave que se haya compartido por chat o correo.

## 7. Si hay un incidente

1. **Contener:**
   - desactivar las cuentas afectadas;
   - rotar las contraseñas de `crm_app`/`crm_owner` con `ALTER ROLE … PASSWORD` y actualizarlas en Coolify;
   - si hubo ejecución de código en el servidor, rotar también `APP_KEY` (con procedimiento), las llaves de pago y el SMTP.
2. **Evaluar:** revisar la bitácora del CRM, los registros de conexión de PostgreSQL y los de Coolify.
3. **Avisar:** la LFPDPPP obliga a informar a los titulares si la vulneración afecta de forma significativa sus derechos. Lo decide la institución con su asesor legal.
