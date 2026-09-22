<?php

declare(strict_types=1);

/*
 * Nombres visibles en la bitácora. Cada campo auditado de un modelo debe
 * tener aquí su etiqueta (lo verifica tests/Feature/Audit/AuditLabelsTest.php).
 */
return [
    'types' => [
        'user' => 'Usuario',
        'donor' => 'Donante',
        'donor_tax_profile' => 'Datos fiscales de donante',
        'tag' => 'Etiqueta',
        'program' => 'Programa',
        'campaign' => 'Campaña',
        'donation' => 'Donativo',
        'organization_setting' => 'Configuración de la organización',
    ],

    'fields' => [
        // Comunes
        'name' => 'Nombre',
        'email' => 'Correo electrónico',
        'status' => 'Estado',
        'slug' => 'Identificador',
        'description' => 'Descripción',
        'notes' => 'Notas',
        'tags' => 'Etiquetas',
        'registered_by_id' => 'Registrado por (usuario)',
        // Usuario
        'role' => 'Rol',
        'deactivated_at' => 'Desactivado el',
        'password' => 'Contraseña',
        // Donante
        'type' => 'Tipo de persona',
        'first_name' => 'Nombre(s)',
        'last_name' => 'Apellido paterno',
        'second_last_name' => 'Apellido materno',
        'legal_name' => 'Razón social',
        'contact_name' => 'Persona de contacto',
        'phone' => 'Teléfono',
        'birth_date' => 'Fecha de nacimiento',
        'archived_at' => 'Archivado el',
        'accepts_communications' => 'Acepta comunicaciones',
        'communications_consent_updated_at' => 'Cambio de consentimiento de comunicaciones',
        'privacy_notice_version' => 'Versión del aviso de privacidad aceptada',
        'privacy_notice_accepted_at' => 'Aceptación del aviso de privacidad',
        // Datos fiscales
        'rfc' => 'RFC',
        'tax_name' => 'Nombre fiscal',
        'tax_regime' => 'Régimen fiscal',
        'tax_postal_code' => 'Código postal fiscal',
        'cfdi_use' => 'Uso de CFDI',
        // Campaña
        'program_id' => 'Programa',
        'starts_on' => 'Fecha de inicio',
        'ends_on' => 'Fecha de fin',
        'goal_amount' => 'Meta',
        // Donativo
        'donor_id' => 'Donante',
        'campaign_id' => 'Campaña',
        'kind' => 'Tipo de donativo',
        'payment_method' => 'Forma de pago',
        'amount' => 'Importe o valor',
        'currency' => 'Moneda',
        'received_on' => 'Fecha de recepción',
        'reference' => 'Referencia',
        'in_kind_description' => 'Descripción de especie',
        'tax_receipt_requested' => 'Solicitó recibo deducible',
        'confirmed_at' => 'Confirmado el',
        'confirmed_by_id' => 'Confirmado por (usuario)',
        'cancelled_at' => 'Cancelado el',
        'cancelled_by_id' => 'Cancelado por (usuario)',
        'cancellation_reason' => 'Motivo de cancelación',
        // Organización
        'authorization_number' => 'Número de oficio de autorización',
        'authorization_date' => 'Fecha de autorización',
        'donation_legend' => 'Leyenda de donativo',
        'logo_path' => 'Logotipo',
        'email_signature' => 'Firma de correo',
        'privacy_notice_url' => 'URL del aviso de privacidad',
    ],
];
