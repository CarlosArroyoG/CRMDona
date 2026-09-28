<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Communications\BulkAudience;
use App\Enums\BulkMessageStatus;
use App\Enums\DonorType;
use App\Enums\Permission;
use App\Models\BulkMessage;
use App\Models\Campaign;
use App\Models\Program;
use App\Models\Tag;
use App\Models\User;
use App\Support\TemplateRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Crea o edita el borrador de un envío masivo. Rechaza llaves mal cerradas y
 * variables que no existen. Si cambia el asunto o el texto, el correo de
 * prueba anterior deja de valer: hay que volver a probar antes de enviar.
 */
class SaveBulkMessage
{
    /**
     * @param  array<string, mixed>  $input  subject, body y los filtros de BulkAudience
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(?BulkMessage $message, array $input, User $actor): BulkMessage
    {
        if (! $actor->hasPermission(Permission::SendBulkMessages)) {
            throw new AuthorizationException('No tienes permiso para preparar envíos masivos.');
        }

        if ($message !== null && ! $message->isDraft()) {
            throw ValidationException::withMessages(['subject' => 'Solo se edita un envío en borrador.']);
        }

        /** @var array{subject: string, body: string} $data */
        $data = Validator::make($input, [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'donor_type' => ['nullable', new Enum(DonorType::class)],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rule::exists(Tag::class, 'id')],
            'campaign_ids' => ['nullable', 'array'],
            'campaign_ids.*' => ['integer', Rule::exists(Campaign::class, 'id')],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => ['integer', Rule::exists(Program::class, 'id')],
            'donated_from' => ['nullable', 'date'],
            'donated_until' => ['nullable', 'date', 'after_or_equal:donated_from'],
        ], [], [
            'subject' => 'asunto',
            'body' => 'texto',
            'donor_type' => 'tipo de persona',
            'tag_ids' => 'etiquetas',
            'campaign_ids' => 'campañas',
            'program_ids' => 'programas',
            'donated_from' => 'donó desde',
            'donated_until' => 'donó hasta',
        ])->validate();

        foreach (['subject', 'body'] as $field) {
            try {
                TemplateRenderer::validate($data[$field], array_keys(BulkMessage::VARIABLES));
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([$field => $exception->getMessage()]);
            }
        }

        $message ??= new BulkMessage(['created_by_id' => $actor->id, 'status' => BulkMessageStatus::Draft]);
        $message->fill([
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            'audience' => BulkAudience::fromArray($input)->toArray(),
        ]);

        if ($message->isDirty(['subject', 'body'])) {
            $message->fill(['tested_at' => null, 'tested_by_id' => null]);
        }

        $message->save();

        return $message;
    }
}
