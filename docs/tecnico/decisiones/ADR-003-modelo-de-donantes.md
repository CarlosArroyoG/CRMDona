# ADR-003 — Modelo de donantes

- **Estado:** Aceptado
- **Fecha:** 2026-09-22

## Contexto

Los donantes pueden ser personas físicas o morales. Hace falta distinguir sus datos sin llenar la
tabla de campos ambiguos, guardar datos fiscales solo cuando se pida un comprobante deducible y
separar el consentimiento de privacidad del de comunicaciones.

## Decisión

1. **Una tabla `donors`** con `type` (`individual` / `organization`):
   - Persona física: `first_name`, `last_name`, `second_last_name`, `birth_date`.
   - Persona moral: `legal_name`, `contact_name`.
   - Un `CHECK` en PostgreSQL (`donors_fields_by_type`) impide mezclar campos de ambos tipos.
   - `display_name` es una **columna generada** por PostgreSQL (nombre completo o razón social),
     para buscar y ordenar.
2. **Datos fiscales en `donor_tax_profiles` (1:1, opcional)**: RFC, nombre fiscal, régimen, código
   postal fiscal y uso de CFDI (sin valor predeterminado). Solo se valida la estructura; las reglas
   fiscales quedan pendientes (ver `docs/pendientes.md`).
3. **Consentimientos independientes:**
   - Aviso de privacidad: `privacy_notice_version` + `privacy_notice_accepted_at`. Van juntos o no
     van (`CHECK donors_privacy_notice_evidence`). Sin aviso configurado en la organización no se
     puede registrar la aceptación.
   - Comunicaciones: `accepts_communications` + `communications_consent_updated_at`.
4. **Sin dirección postal**: no hay finalidad operativa (minimización de datos).
5. **Etiquetas** en `tags` + `donor_tag` (nombres únicos sin distinguir mayúsculas).
6. **Duplicados:** el correo y el RFC **no** son únicos (familias u organizaciones pueden
   compartirlos). El formulario solo **advierte** si ya existen; nunca fusiona.

## Consecuencias

- La base de datos garantiza la coherencia aunque alguien escriba sin pasar por la aplicación.
- Separar los datos fiscales evita exponerlos a quien no tiene permiso (Solo lectura) y prepara el
  CFDI, que copiará estos datos al timbrar.
- Dos tablas separadas por tipo de persona se descartaron por ser sobrearquitectura para 6 campos.
