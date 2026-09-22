<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Las reglas viven en app/Actions. Aquí solo se muestran sus errores en
 * Filament: en los campos del formulario (prefijo "data.") o como aviso.
 */
trait ReportsActionErrors
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws ValidationException
     */
    protected static function withFormErrors(callable $callback, string $prefix = 'data.'): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$prefix.$key => $messages])
                ->all());
        }
    }

    /**
     * Ejecuta una acción sin formulario (confirmar, archivar…) y muestra el
     * resultado como notificación. Devuelve false si la regla lo impidió.
     *
     * @param  callable(): mixed  $callback
     */
    protected static function notifyOutcome(callable $callback, string $successTitle): bool
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('No se pudo completar la acción')
                ->body(implode(' ', $exception->validator->errors()->all()))
                ->send();

            return false;
        }

        Notification::make()->success()->title($successTitle)->send();

        return true;
    }
}
