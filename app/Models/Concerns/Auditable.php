<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Registra en la bitácora quién cambió qué y cuándo (ADR-006).
 *
 * Cada modelo declara una lista cerrada:
 * - auditValueFields(): se guarda el valor anterior y el nuevo.
 * - auditNameOnlyFields(): solo se registra que el campo cambió (datos
 *   personales o fiscales que no deben copiarse a la bitácora).
 * Cualquier otro campo no se registra.
 *
 * Los eventos de negocio (confirmar, cancelar, archivar…) se indican con
 * auditAs() antes de guardar y sustituyen al "updated" genérico.
 *
 * @mixin Model
 */
trait Auditable
{
    private ?AuditEvent $pendingAuditEvent = null;

    /**
     * @return list<string>
     */
    abstract public static function auditValueFields(): array;

    /**
     * @return list<string>
     */
    abstract public static function auditNameOnlyFields(): array;

    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            /** @var Model&self $model */
            $model->writeAudit(AuditEvent::Created, array_keys(array_filter(
                $model->getAttributes(),
                fn (mixed $value): bool => $value !== null,
            )), creating: true);
        });

        static::updated(function (Model $model): void {
            /** @var Model&self $model */
            $model->writeAudit(AuditEvent::Updated, array_keys($model->getChanges()));
        });

        static::deleted(function (Model $model): void {
            /** @var Model&self $model */
            $model->writeAudit(AuditEvent::Deleted, [], deleting: true);
        });
    }

    public function auditAs(AuditEvent $event): static
    {
        $this->pendingAuditEvent = $event;

        return $this;
    }

    /**
     * Registra un evento que no proviene de columnas del modelo (por ejemplo,
     * el cambio de etiquetas, que vive en una tabla pivote).
     *
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function recordAudit(AuditEvent $event, array $oldValues, array $newValues): void
    {
        AuditLog::query()->create([
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'user_id' => auth()->id(),
            'changed_fields' => array_values(array_unique([...array_keys($oldValues), ...array_keys($newValues)])),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }

    /**
     * @param  list<string>  $fields
     */
    private function writeAudit(AuditEvent $default, array $fields, bool $creating = false, bool $deleting = false): void
    {
        $event = $this->pendingAuditEvent ?? $default;
        $this->pendingAuditEvent = null;

        $valueFields = static::auditValueFields();
        $audited = array_merge($valueFields, static::auditNameOnlyFields());
        $changed = $deleting ? [] : array_values(array_intersect($fields, $audited));

        if (! $creating && ! $deleting && $changed === []) {
            return;
        }

        $old = [];
        $new = [];
        foreach (array_intersect($changed, $valueFields) as $field) {
            if (! $creating) {
                $old[$field] = $this->getRawOriginal($field);
            }
            $new[$field] = $this->getAttributes()[$field] ?? null;
        }

        AuditLog::query()->create([
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'user_id' => auth()->id(),
            'changed_fields' => $changed,
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
        ]);
    }
}
