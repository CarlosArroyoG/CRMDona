<?php

declare(strict_types=1);

namespace App\Actions\Donors;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\DonorOrigin;
use App\Enums\DonorType;
use App\Enums\Permission;
use App\Models\Donor;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra un donante a partir de una fila de la carga CSV
 * (docs/tecnico/carga-y-envios-masivos.md). Reutiliza SaveDonor, así que la
 * fila se valida igual que el alta manual y queda en la bitácora.
 *
 * - Nunca modifica a un donante existente: si el correo ya está registrado
 *   (sin importar mayúsculas), la fila se rechaza con el motivo.
 * - "Acepta comunicaciones" solo se respeta si quien importa confirmó que la
 *   Fundación tiene ese consentimiento documentado; si no, entra en "no".
 * - El aviso de privacidad y los datos fiscales no se importan.
 */
class ImportDonorRow
{
    use NormalizesInput;

    public const int MAX_TAGS_PER_ROW = 10;

    private const array INDIVIDUAL = ['fisica', 'persona fisica', 'individual', 'pf'];

    private const array ORGANIZATION = ['moral', 'persona moral', 'organizacion', 'empresa', 'pm'];

    private const array YES = ['si', 's', 'yes', 'y', '1', 'true', 'verdadero', 'x'];

    private const array NO = ['no', 'n', '0', 'false', 'falso'];

    public function __construct(private readonly SaveDonor $saveDonor) {}

    /**
     * @param  array<string, mixed>  $row  columnas ya mapeadas (type, first_name, email, tags…)
     * @param  string|null  $batchTag  etiqueta común a toda la carga (p. ej. "Carga septiembre 2026")
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $row, User $actor, bool $consentConfirmed, ?string $batchTag = null): Donor
    {
        if (! $actor->hasPermission(Permission::ImportDonors)) {
            throw new AuthorizationException('No tienes permiso para cargar donantes.');
        }

        $row = $this->normalize($row);
        $type = $this->type($row['type'] ?? null);
        $email = isset($row['email']) ? mb_strtolower((string) $row['email']) : null;
        $accepts = $this->yesNo($row['accepts_communications'] ?? null) && $consentConfirmed;

        return DB::transaction(function () use ($row, $type, $email, $accepts, $actor, $batchTag): Donor {
            if ($email !== null) {
                // Dos bloques del mismo archivo con el mismo correo no crean dos donantes.
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['donor-import:'.$email]);

                if (Donor::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
                    throw ValidationException::withMessages(['email' => "Ya existe un donante con el correo {$email}; no se modificó."]);
                }
            }

            $tagIds = $this->tagIds($row['tags'] ?? null);
            if (filled($batchTag)) {
                $tagIds[] = $this->tagId(trim((string) $batchTag));
            }

            return $this->saveDonor->handle(null, [
                'type' => $type->value,
                'first_name' => $row['first_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
                'second_last_name' => $row['second_last_name'] ?? null,
                'birth_date' => $type === DonorType::Individual ? $this->date($row['birth_date'] ?? null) : null,
                'legal_name' => $row['legal_name'] ?? null,
                'contact_name' => $row['contact_name'] ?? null,
                'email' => $email,
                'phone' => $row['phone'] ?? null,
                'notes' => $row['notes'] ?? null,
                'privacy_notice_accepted' => false,
                'accepts_communications' => $accepts,
                'tag_ids' => array_values(array_unique($tagIds)),
            ], $actor, DonorOrigin::CsvImport);
        });
    }

    private function type(mixed $value): DonorType
    {
        $key = self::key($value);

        return match (true) {
            $key === '' || in_array($key, self::INDIVIDUAL, true) => DonorType::Individual,
            in_array($key, self::ORGANIZATION, true) => DonorType::Organization,
            default => throw ValidationException::withMessages(['type' => 'Tipo de persona no reconocido: usa "física" o "moral".']),
        };
    }

    private function yesNo(mixed $value): bool
    {
        $key = self::key($value);

        return match (true) {
            $key === '' || in_array($key, self::NO, true) => false,
            in_array($key, self::YES, true) => true,
            default => throw ValidationException::withMessages(['accepts_communications' => 'Acepta comunicaciones: usa "sí" o "no".']),
        };
    }

    /**
     * Acepta 31/12/1980, 1980-12-31 y 31-12-1980 (año de cuatro dígitos).
     */
    private function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;
        $parts = match (true) {
            (bool) preg_match('~^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$~', $text, $match) => [(int) $match[3], (int) $match[2], (int) $match[1]],
            (bool) preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $text, $match) => [(int) $match[1], (int) $match[2], (int) $match[3]],
            default => null,
        };

        if ($parts === null || ! checkdate($parts[1], $parts[2], $parts[0])) {
            throw ValidationException::withMessages(['birth_date' => 'Fecha de nacimiento inválida: usa el formato dd/mm/aaaa.']);
        }

        return sprintf('%04d-%02d-%02d', ...$parts);
    }

    /**
     * Etiquetas separadas por punto y coma o coma. Se reutiliza la existente
     * sin importar mayúsculas; si no existe, se crea.
     *
     * @return list<int>
     */
    private function tagIds(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $names = array_values(array_unique(array_filter(array_map('trim', preg_split('/[;,]/', (string) $value) ?: []), fn (string $name): bool => $name !== '')));
        if (count($names) > self::MAX_TAGS_PER_ROW) {
            throw ValidationException::withMessages(['tags' => 'Máximo '.self::MAX_TAGS_PER_ROW.' etiquetas por donante.']);
        }

        return array_map($this->tagId(...), $names);
    }

    private function tagId(string $name): int
    {
        if (mb_strlen($name) > 50) {
            throw ValidationException::withMessages(['tags' => "La etiqueta \"{$name}\" supera 50 caracteres."]);
        }

        $existing = Tag::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        try {
            return DB::transaction(fn (): int => Tag::query()->create(['name' => $name])->id);
        } catch (UniqueConstraintViolationException) {
            return (int) Tag::query()->where('name', $name)->value('id');
        }
    }

    /**
     * Minúsculas y sin acentos, para comparar "Física", "fisica" o "SÍ".
     */
    private static function key(mixed $value): string
    {
        $text = mb_strtolower(trim((string) $value));

        return strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    }
}
