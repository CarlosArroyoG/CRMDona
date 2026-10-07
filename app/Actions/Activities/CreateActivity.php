<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Models\Donor;
use App\Models\DonorActivity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Registra una interacción con un donante: programada (`scheduled_at` futuro)
 * o ya ocurrida (se registra directamente como `completed`).
 */
class CreateActivity
{
    use NormalizesInput;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $input, User $actor): DonorActivity
    {
        if (! $actor->hasPermission(Permission::ManageDonorActivities)) {
            throw new AuthorizationException('No tienes permiso para registrar actividades.');
        }

        $input = $this->normalize($input);
        $data = Validator::make($input, [
            'donor_id' => ['required', 'integer', Rule::exists(Donor::class, 'id')],
            'assigned_to_id' => ['required', 'integer', Rule::exists(User::class, 'id')],
            'type' => ['required', new Enum(ActivityType::class)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in([ActivityStatus::Scheduled->value, ActivityStatus::Completed->value])],
            'scheduled_at' => ['required_if:status,'.ActivityStatus::Scheduled->value, 'nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'result' => ['nullable', 'string', 'max:2000'],
            'next_action' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'donor_id' => 'donante', 'assigned_to_id' => 'responsable', 'type' => 'tipo', 'subject' => 'asunto',
            'scheduled_at' => 'fecha programada',
        ])->validate();

        $status = ActivityStatus::from((string) $data['status']);

        return DonorActivity::query()->create([
            'donor_id' => (int) $data['donor_id'],
            'assigned_to_id' => (int) $data['assigned_to_id'],
            'created_by_id' => $actor->id,
            'type' => $data['type'],
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'status' => $status,
            'scheduled_at' => $status === ActivityStatus::Scheduled ? $data['scheduled_at'] : null,
            'completed_at' => $status === ActivityStatus::Completed ? ($data['completed_at'] ?? now()) : null,
            'completed_by_id' => $status === ActivityStatus::Completed ? $actor->id : null,
            'result' => $data['result'] ?? null,
            'next_action' => $data['next_action'] ?? null,
        ]);
    }
}
