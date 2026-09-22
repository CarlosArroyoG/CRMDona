<?php

declare(strict_types=1);

namespace App\Actions\Programs;

use App\Models\Program;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solo sin donativos ni campañas (las llaves foráneas también lo impiden).
 * Lo habitual es archivarlo.
 */
class DeleteProgram
{
    public const string IN_USE = 'Este programa tiene campañas o donativos y no puede eliminarse. Puedes archivarlo.';

    /**
     * @throws ValidationException
     */
    public function handle(Program $program): void
    {
        DB::transaction(function () use ($program): void {
            if ($program->donations()->exists() || $program->campaigns()->exists()) {
                throw ValidationException::withMessages(['program' => self::IN_USE]);
            }

            $program->delete();
        });
    }
}
