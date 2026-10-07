<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Models\DonorActivity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Solo una actividad programada cambia sus datos generales. Completarla,
 * reprogramarla o cancelarla son Actions propias.
 */
class UpdateActivity
{
    use NormalizesInput;

    public const string NOT_SCHEDULED = 'Solo se modifican actividades programadas.';

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(DonorActivity $activity, array $input, User $actor): DonorActivity
    {
        if (! $actor->hasPermission(Permission::ManageDonorActivities)) {
            throw new AuthorizationException('No tienes permiso para modificar actividades.');
        }

        $input = $this->normalize($input);
        $data = Validator::make($input, [
            'assigned_to_id' => ['required', 'integer', Rule::exists(User::class, 'id')],
            'type' => ['required', new Enum(ActivityType::class)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'assigned_to_id' => 'responsable', 'type' => 'tipo', 'subject' => 'asunto',
        ])->validate();

        return DB::transaction(function () use ($activity, $data): DonorActivity {
            $locked = DonorActivity::query()->lockForUpdate()->findOrFail($activity->id);

            if (! $locked->isScheduled()) {
                throw ValidationException::withMessages(['activity' => self::NOT_SCHEDULED]);
            }

            $locked->fill($data)->save();

            return $locked;
        });
    }
}
