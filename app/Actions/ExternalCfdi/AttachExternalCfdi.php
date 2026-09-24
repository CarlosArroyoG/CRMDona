<?php

declare(strict_types=1);

namespace App\Actions\ExternalCfdi;

use App\Enums\DonationStatus;
use App\Enums\Permission;
use App\ExternalCfdi\CfdiXmlReader;
use App\Models\Donation;
use App\Models\ExternalCfdi;
use App\Models\OrganizationSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Adjunta a un donativo el CFDI que contabilidad emitió fuera del CRM. Solo
 * guarda evidencia: no timbra, no consulta al SAT ni a ningún PAC, y no
 * certifica la validez fiscal del documento.
 *
 * Los datos (UUID, fechas, total) se leen del XML. Los archivos se validan
 * por su contenido (no por el nombre) y se guardan en el disco privado con
 * un nombre generado.
 */
class AttachExternalCfdi
{
    public const int MAX_PDF_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly CfdiXmlReader $reader) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Donation $donation, string $xml, ?string $pdf, ?string $notes, User $actor, ?ExternalCfdi $replaces = null): ExternalCfdi
    {
        if (! $actor->hasPermission(Permission::ManageExternalCfdis)) {
            throw new AuthorizationException('No tienes permiso para adjuntar CFDI externos.');
        }

        if ($donation->status !== DonationStatus::Confirmed) {
            throw ValidationException::withMessages(['xml' => 'Solo se adjuntan CFDI a donativos confirmados.']);
        }

        $this->assertMime($xml, ['text/xml', 'application/xml', 'text/plain'], 'xml', 'El archivo debe ser un XML.');
        $data = $this->reader->read($xml);

        $organizationRfc = OrganizationSetting::current()->rfc;
        if ($organizationRfc !== null && $data->issuerRfc !== null && strtoupper($organizationRfc) !== $data->issuerRfc) {
            throw ValidationException::withMessages(['xml' => 'El RFC emisor del CFDI no coincide con el RFC de la organización.']);
        }

        if ($pdf !== null) {
            if (strlen($pdf) > self::MAX_PDF_BYTES || ! str_starts_with($pdf, '%PDF-')) {
                throw ValidationException::withMessages(['pdf' => 'El PDF no es válido o excede 10 MB.']);
            }
            $this->assertMime($pdf, ['application/pdf'], 'pdf', 'El archivo debe ser un PDF.');
        }

        $notes = $notes !== null ? mb_substr(trim($notes), 0, 1000) : null;

        return DB::transaction(function () use ($donation, $xml, $pdf, $notes, $actor, $data, $replaces): ExternalCfdi {
            $locked = Donation::query()->lockForUpdate()->findOrFail($donation->id);

            $duplicate = ExternalCfdi::query()->active()->where('donation_id', $locked->id)->where('uuid', $data->uuid)
                ->when($replaces !== null, fn ($query) => $query->whereKeyNot($replaces?->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['xml' => 'Ese CFDI (mismo UUID) ya está adjunto a este donativo.']);
            }

            $base = 'external-cfdi/'.now()->format('Y/m').'/'.$data->uuid.'-'.Str::random(12);
            $disk = Storage::disk('local');
            $disk->put("{$base}.xml", $xml) || throw new RuntimeException('No se pudo guardar el XML.');
            $pdfPath = null;
            if ($pdf !== null) {
                $disk->put("{$base}.pdf", $pdf) || throw new RuntimeException('No se pudo guardar el PDF.');
                $pdfPath = "{$base}.pdf";
            }

            $record = ExternalCfdi::query()->create([
                'donation_id' => $locked->id,
                'uuid' => $data->uuid,
                'issued_at' => $data->issuedAt,
                'stamped_at' => $data->stampedAt,
                'total' => $data->total,
                'xml_path' => "{$base}.xml",
                'pdf_path' => $pdfPath,
                'notes' => $notes === '' ? null : $notes,
                'source' => ExternalCfdi::SOURCE_UPLOAD,
                'uploaded_by_id' => $actor->id,
                'uploaded_at' => now(),
            ]);

            if ($replaces !== null) {
                app(RemoveExternalCfdi::class)->handle($replaces, "Reemplazado por el CFDI {$data->uuid}.", $actor);
            }

            return $record;
        });
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws ValidationException
     */
    private function assertMime(string $contents, array $allowed, string $field, string $message): void
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
