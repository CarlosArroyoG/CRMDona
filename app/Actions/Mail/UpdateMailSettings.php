<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\AuditEvent;
use App\Enums\MailEncryption;
use App\Enums\Permission;
use App\Mail\Outgoing\OutgoingMailConfig;
use App\Models\MailSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Guarda el correo saliente (solo Administrador). Reglas de la contraseña:
 * - vacía = conservar la guardada;
 * - con texto = reemplazarla;
 * - `remove_password` = eliminarla explícitamente.
 * Nunca se devuelve ni se registra; la bitácora solo dice "reemplazada" o
 * "eliminada". Con usuario, la contraseña es obligatoria; sin usuario
 * (relay sin autenticación) no se guarda ninguna.
 *
 * No se imponen reglas de un proveedor concreto ni se bloquean servidores
 * internos (instalaciones corporativas): ver docs/tecnico/correo-saliente.md.
 */
class UpdateMailSettings
{
    public function __construct(private readonly OutgoingMailConfig $mail) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $input, User $actor): MailSetting
    {
        if (! $actor->hasPermission(Permission::ManageMailSettings)) {
            throw new AuthorizationException('Solo el Administrador configura el correo saliente.');
        }

        // La contraseña no se recorta (los espacios pueden ser parte de ella); vacía = conservar.
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $clean = $key === 'password' ? $value : trim($value);
                $input[$key] = $clean === '' ? null : $clean;
            }
        }
        $enabled = (bool) ($input['enabled'] ?? false);

        /** @var array{enabled?: bool, host: string|null, port: int|string|null, encryption: string, username: string|null, password: string|null, remove_password?: bool, from_address: string|null, from_name: string|null, reply_to_address: string|null, reply_to_name: string|null, timeout: int|string} $data */
        $data = Validator::make($input, [
            'enabled' => ['boolean'],
            'host' => [Rule::requiredIf($enabled), 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$|^\[?[0-9A-Fa-f:.]+\]?$/'],
            'port' => [Rule::requiredIf($enabled), 'nullable', 'integer', 'between:1,65535'],
            'encryption' => ['required', new Enum(MailEncryption::class)],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'remove_password' => ['boolean'],
            'from_address' => [Rule::requiredIf($enabled), 'nullable', 'email:rfc', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to_address' => ['nullable', 'email:rfc', 'max:255'],
            'reply_to_name' => ['nullable', 'string', 'max:255'],
            'timeout' => ['required', 'integer', 'between:1,120'],
        ], [
            'host.regex' => 'Escribe solo el nombre del servidor o su IP, sin "smtp://", puerto ni rutas (ej. smtp.ejemplo.com).',
        ], [
            'host' => 'servidor SMTP', 'port' => 'puerto', 'encryption' => 'seguridad', 'username' => 'usuario', 'password' => 'contraseña',
            'from_address' => 'correo del remitente', 'from_name' => 'nombre del remitente', 'reply_to_address' => 'correo de respuesta (Reply-To)',
            'reply_to_name' => 'nombre de respuesta', 'timeout' => 'tiempo de espera',
        ])->validate();

        $settings = DB::transaction(function () use ($data, $enabled): MailSetting {
            $settings = MailSetting::query()->lockForUpdate()->findOrFail(MailSetting::current()->id);

            $newPassword = $data['password'] ?? null;
            $remove = (bool) ($data['remove_password'] ?? false);
            $username = $data['username'] ?? null;

            if ($newPassword !== null && $remove) {
                throw ValidationException::withMessages(['password' => 'Escribe una contraseña nueva o marca "Eliminar contraseña", no ambas.']);
            }

            $keepsPassword = $newPassword !== null || (! $remove && $settings->hasPassword());
            if ($username !== null && ! $keepsPassword) {
                throw ValidationException::withMessages(['password' => 'Con usuario SMTP, la contraseña es obligatoria.']);
            }
            if ($username === null && $newPassword !== null) {
                throw ValidationException::withMessages(['username' => 'Escribe el usuario SMTP o deja la contraseña vacía.']);
            }

            $values = [
                'enabled' => $enabled,
                'host' => $data['host'] ?? null,
                'port' => isset($data['port']) ? (int) $data['port'] : null,
                'encryption' => $data['encryption'],
                'username' => $username,
                'from_address' => $data['from_address'] ?? null,
                'from_name' => $data['from_name'] ?? null,
                'reply_to_address' => $data['reply_to_address'] ?? null,
                'reply_to_name' => filled($data['reply_to_address'] ?? null) ? ($data['reply_to_name'] ?? null) : null,
                'timeout' => (int) $data['timeout'],
            ];

            $context = [];
            if ($newPassword !== null) {
                $values['password'] = $newPassword;
                $context['smtp_password'] = 'reemplazada';
            } elseif (($remove || $username === null) && $settings->hasPassword()) {
                $values['password'] = null;
                $context['smtp_password'] = 'eliminada';
            }

            $values['version'] = $settings->version + 1;
            $settings->auditAs(AuditEvent::Updated, $context)->forceFill($values)->save();

            return $settings;
        });

        // En este proceso se aplica ya; los workers la toman antes de su siguiente Job.
        $this->mail->refresh();

        return $settings;
    }
}
