<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\OrganizationSetting;
use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * Identidad institucional: única puerta al nombre y al logotipo que configura
 * Administración (`organization_settings.logo_path`, PNG o JPG en el disco
 * público). Panel, /donar, favicon, recibo PDF y correos la usan; ningún
 * módulo lee el logo por su cuenta ni lo trae de Internet. Sin logo, cada
 * lugar muestra el nombre de la organización.
 */
final class Branding
{
    /** Lado del favicon derivado (sirve también como ícono de iPhone). */
    public const int FAVICON_SIZE = 180;

    /** Límite de píxeles para procesar el logo con GD (evita agotar memoria). */
    private const int MAX_PIXELS = 16_000_000;

    public static function name(): string
    {
        return OrganizationSetting::current()->legal_name ?? config()->string('app.name');
    }

    /**
     * Ruta del logo en el disco público, solo si el archivo existe.
     */
    public static function logoPath(): ?string
    {
        $path = OrganizationSetting::current()->logo_path;

        return $path !== null && Storage::disk('public')->exists($path) ? $path : null;
    }

    public static function logoUrl(): ?string
    {
        $path = self::logoPath();

        return $path !== null ? Storage::disk('public')->url($path) : null;
    }

    /**
     * Logo como JPG sobre fondo blanco (el PDF incrusta JPG sin
     * decodificarlo; la transparencia del PNG se aplana en blanco).
     *
     * @return array{data: string, width: int, height: int}|null
     */
    public static function logoJpeg(int $maxWidth = 800): ?array
    {
        $image = self::logoImage();
        if ($image === null) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxWidth / $width);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = self::canvas($targetWidth, $targetHeight, opaque: true);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 90);
        $data = (string) ob_get_clean();

        return ['data' => $data, 'width' => $targetWidth, 'height' => $targetHeight];
    }

    /**
     * Favicon cuadrado derivado del logo (centrado, proporción intacta, fondo
     * transparente). Se guarda una vez por logo en el disco público; cambiar
     * el logo cambia el archivo y la URL.
     */
    public static function faviconPath(): ?string
    {
        $logo = self::logoPath();
        if ($logo === null) {
            return null;
        }

        $path = 'organization/favicon-'.substr(sha1($logo), 0, 16).'.png';
        $disk = Storage::disk('public');
        if ($disk->exists($path)) {
            return $path;
        }

        $image = self::logoImage();
        if ($image === null) {
            return null;
        }

        $size = self::FAVICON_SIZE;
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min($size / $width, $size / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = self::canvas($size, $size, opaque: false);
        imagecopyresampled($canvas, $image, intdiv($size - $targetWidth, 2), intdiv($size - $targetHeight, 2), 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($canvas);
        $disk->put($path, (string) ob_get_clean());

        return $path;
    }

    /**
     * URL del favicon (la del logo derivado o el ícono genérico del sitio).
     * Lleva la huella del logo para que el navegador no conserve uno viejo.
     */
    public static function faviconUrl(): string
    {
        $logo = self::logoPath();

        return route('favicon', $logo !== null ? ['v' => substr(sha1($logo), 0, 8)] : []);
    }

    private static function logoImage(): ?GdImage
    {
        $path = self::logoPath();
        if ($path === null) {
            return null;
        }

        $content = Storage::disk('public')->get($path);
        $info = $content !== null ? @getimagesizefromstring($content) : false;
        // Solo PNG o JPG (el formulario ya lo exige; aquí se revalida por contenido).
        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring((string) $content);

        return $image instanceof GdImage ? $image : null;
    }

    private static function canvas(int $width, int $height, bool $opaque): GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        if ($canvas === false) {
            throw new \RuntimeException('No se pudo preparar la imagen del logotipo.');
        }

        if ($opaque) {
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            // Sin mezcla: la copia conserva la transparencia del PNG original.
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        }

        return $canvas;
    }
}
