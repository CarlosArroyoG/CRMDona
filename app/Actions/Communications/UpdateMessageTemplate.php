<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\Permission;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\TemplateRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Guarda el texto de una plantilla. Rechaza llaves mal cerradas y variables
 * que no existen para ese tipo de correo; aun así, al enviar, una plantilla
 * que fallara se sustituye por la predeterminada.
 */
class UpdateMessageTemplate
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(MessageTemplate $template, array $input, User $actor): MessageTemplate
    {
        if (! $actor->hasPermission(Permission::ManageMessageTemplates)) {
            throw new AuthorizationException('No tienes permiso para editar plantillas.');
        }

        /** @var array{subject: string, body: string} $data */
        $data = Validator::make($input, [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ], [], ['subject' => 'asunto', 'body' => 'texto'])->validate();

        $allowed = array_keys($template->kind->variables());
        foreach (['subject', 'body'] as $field) {
            try {
                TemplateRenderer::validate($data[$field], $allowed);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([$field => $exception->getMessage()]);
            }
        }

        $template->forceFill([...$data, 'updated_by_id' => $actor->id])->save();

        return $template;
    }
}
