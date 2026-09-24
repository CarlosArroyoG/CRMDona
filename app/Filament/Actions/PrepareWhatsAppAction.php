<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Donors\PrepareBirthdayWhatsApp;
use App\Models\Donor;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Js;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * "Preparar WhatsApp" (ficha del donante y cumpleaños del Escritorio). Pasa
 * por PrepareBirthdayWhatsApp, que revalida permiso y consentimiento y lo
 * registra; luego abre wa.me con el mensaje. La interfaz dice claramente que
 * el CRM solo abrió WhatsApp: el envío lo confirma la persona ahí.
 */
final class PrepareWhatsAppAction
{
    /**
     * @param  Closure(array<string, mixed>): ?Donor  $donor  resuelve el donante (registro o argumento de la acción)
     */
    public static function make(Closure $donor, string $name = 'prepareWhatsApp'): Action
    {
        return Action::make($name)
            ->label('Preparar WhatsApp')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('success')
            ->visible(fn (array $arguments): bool => ($model = $donor($arguments)) !== null
                && PrepareBirthdayWhatsApp::canPrepare($model, self::actor()))
            ->modalHeading('Preparar felicitación por WhatsApp')
            ->modalDescription('Se abrirá WhatsApp con la felicitación de cumpleaños ya escrita (la misma plantilla del correo). El CRM no envía el mensaje: revísalo y confirma el envío en WhatsApp.')
            ->modalSubmitActionLabel('Abrir WhatsApp')
            ->action(function (array $arguments, Component $livewire) use ($donor): void {
                $model = $donor($arguments);
                $actor = self::actor();
                if ($model === null || $actor === null) {
                    return;
                }

                try {
                    $url = app(PrepareBirthdayWhatsApp::class)->handle($model, $actor);
                } catch (ValidationException|AuthorizationException $exception) {
                    Notification::make()->danger()->title('No se pudo preparar el WhatsApp')
                        ->body($exception instanceof ValidationException ? implode(' ', $exception->validator->errors()->all()) : $exception->getMessage())
                        ->send();

                    return;
                }

                $livewire->js('window.open('.Js::from($url).', "_blank", "noopener")');
                Notification::make()->success()
                    ->title('Se abrió WhatsApp; confirma el envío ahí.')
                    ->body('El CRM no envió el mensaje: solo preparó la conversación. Si WhatsApp no se abrió, usa el botón.')
                    ->actions([Action::make('open')->label('Abrir WhatsApp')->url($url, shouldOpenInNewTab: true)])
                    ->persistent()
                    ->send();
            });
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
