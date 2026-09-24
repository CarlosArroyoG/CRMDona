<?php

declare(strict_types=1);

use App\Actions\Communications\IssueDonationReceipt;
use App\Communications\ComposedMessage;
use App\Enums\TaxRegime;
use App\Mail\DonorMessage;
use App\Models\Donation;
use App\Models\OrganizationSetting;
use App\Payments\Gateways\FakeScenario;
use App\Support\Branding;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\get;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake((string) config('communications.disk'));
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'privacy_notice_url' => 'https://www.fdonbosco.org/aviso-de-privacidad', 'privacy_notice_version' => '2026-09',
    ])->save();
});

/**
 * Logotipo generado con GD (PNG con transparencia o JPG) guardado como lo hace el formulario.
 */
function useLogo(string $type, int $width, int $height): string
{
    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 22, 37, 98, $type === 'png' ? 60 : 0));
    ob_start();
    $type === 'png' ? imagepng($image) : imagejpeg($image);
    $path = "organization/logo-prueba.{$type}";
    Storage::disk('public')->put($path, (string) ob_get_clean());
    OrganizationSetting::current()->forceFill(['logo_path' => $path])->save();

    return $path;
}

function receiptPdf(): string
{
    startFakeDonation([], FakeScenario::Success);
    $receipt = app(IssueDonationReceipt::class)->handle(Donation::query()->latest('id')->firstOrFail());

    return (string) Storage::disk((string) config('communications.disk'))->get((string) $receipt->pdf_path);
}

it('recibo PDF con logo PNG: se incrusta como JPG proporcional (400×100 → 160×40) y sigue diciendo que no es fiscal', function (): void {
    useLogo('png', 400, 100);

    $pdf = receiptPdf();

    expect($pdf)->toStartWith('%PDF-1.4')->toContain('/Subtype /Image')->toContain('/Filter /DCTDecode')
        ->toContain('/XObject << /Im1 7 0 R >>')->toContain('q 160 0 0 40 56 ')
        ->toContain('RECIBO DE DONATIVO')->toContain('No es una factura')->toContain('comprobante fiscal')->toContain('R-');
});

it('recibo PDF con logo JPG cuadrado conserva la proporción (60×60)', function (): void {
    useLogo('jpg', 300, 300);

    expect(receiptPdf())->toContain('q 60 0 0 60 56 ')->toContain('/Width 300 /Height 300');
});

it('sin logo (o con un archivo que no es PNG/JPG) el recibo sale sin imagen y con la leyenda no fiscal', function (): void {
    expect(receiptPdf())->not->toContain('/Subtype /Image')->toContain('No es una factura')->toContain('comprobante fiscal');

    Storage::disk('public')->put('organization/viejo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    OrganizationSetting::current()->forceFill(['logo_path' => 'organization/viejo.svg'])->save();
    expect(Branding::logoJpeg())->toBeNull()->and(Branding::faviconPath())->toBeNull();
});

it('correos institucionales: logo arriba con tamaño razonable; sin logo, el nombre de la organización', function (): void {
    $message = new ComposedMessage('Asunto', "Hola, María:\n\nGracias.", [], [], false, null, null);

    $withoutLogo = (new DonorMessage($message))->render();
    expect($withoutLogo)->toContain('FUNDACION DE PRUEBA');
    expect($withoutLogo)->not->toContain('<img');

    useLogo('png', 400, 100);
    $html = (new DonorMessage($message))->render();
    expect($html)->toContain('<img src="'.Branding::logoUrl().'"')->toContain('alt="FUNDACION DE PRUEBA"')->toContain('height="56"');
});

it('favicon derivado del logo: PNG cuadrado de 180 px; sin logo, el ícono genérico', function (): void {
    get('/favicon.png')->assertRedirect(asset('favicon.ico'));

    useLogo('png', 400, 100);
    $response = get('/favicon.png')->assertOk()->assertHeader('Content-Type', 'image/png');
    $size = getimagesizefromstring((string) $response->getContent());

    expect($size[0] ?? null)->toBe(180)->and($size[1] ?? null)->toBe(180)
        ->and(Branding::faviconUrl())->toContain('/favicon.png?v=');
});

it('una sola fuente: /donar y el acceso al panel usan el logo y el favicon configurados', function (): void {
    useLogo('png', 400, 100);

    $logo = (string) Branding::logoUrl();
    get('/donar')->assertOk()->assertSee($logo, false)->assertSee(Branding::faviconUrl(), false);
    get('/admin/login')->assertOk()->assertSee($logo, false)->assertSee(Branding::faviconUrl(), false);
});
