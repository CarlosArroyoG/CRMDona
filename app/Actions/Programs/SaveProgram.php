<?php

declare(strict_types=1);

namespace App\Actions\Programs;

use App\Actions\Concerns\NormalizesInput;
use App\Actions\Concerns\ResolvesSlugs;
use App\Enums\ProgramStatus;
use App\Models\Program;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class SaveProgram
{
    use NormalizesInput;
    use ResolvesSlugs;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(?Program $program, array $input): Program
    {
        $input = $this->normalize($input);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:150', function (string $attribute, mixed $value, \Closure $fail) use ($program): void {
                $taken = Program::query()
                    ->whereRaw('lower(name) = lower(?)', [(string) $value])
                    ->when($program?->exists, fn ($query) => $query->whereKeyNot($program?->id))
                    ->exists();
                if ($taken) {
                    $fail('Ya existe un programa con ese nombre.');
                }
            }],
            'slug' => $this->slugRules(Program::class, $program?->id),
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', new Enum(ProgramStatus::class)],
        ], [], ['name' => 'nombre', 'slug' => 'identificador', 'description' => 'descripción', 'status' => 'estado'])->validate();

        return DB::transaction(function () use ($program, $data): Program {
            $program ??= new Program;
            $program->fill([
                'name' => $data['name'],
                'slug' => $this->resolveSlug(Program::class, $data['slug'] ?? null, $data['name'], $program->id),
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
            ])->save();

            return $program;
        });
    }
}
