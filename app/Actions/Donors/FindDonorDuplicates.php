<?php

declare(strict_types=1);

namespace App\Actions\Donors;

use App\Models\Donor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Busca otros donantes con el mismo correo o RFC. Solo sirve para advertir:
 * nunca impide el registro ni fusiona donantes.
 */
class FindDonorDuplicates
{
    /**
     * @return Collection<int, Donor>
     */
    public function handle(?string $email, ?string $rfc, ?int $exceptDonorId = null): Collection
    {
        $email = $email !== null ? mb_strtolower(trim($email)) : '';
        $rfc = $rfc !== null ? mb_strtoupper(trim($rfc)) : '';

        if ($email === '' && $rfc === '') {
            return new Collection;
        }

        return Donor::query()
            ->when($exceptDonorId !== null, fn (Builder $query) => $query->whereKeyNot($exceptDonorId))
            ->where(function (Builder $query) use ($email, $rfc): void {
                if ($email !== '') {
                    $query->orWhereRaw('lower(email) = ?', [$email]);
                }
                if ($rfc !== '') {
                    $query->orWhereHas('taxProfile', fn (Builder $profile) => $profile->where('rfc', $rfc));
                }
            })
            ->orderBy('display_name')
            ->limit(5)
            ->get();
    }
}
