<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Support\AuditOrigin;
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

    /** @var array<string, string|null> */
    private array $pendingAuditContext = [];

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

    /**
     * @param  array<string, string|null>  $context  datos del evento que no son columnas
     *                                               (por ejemplo, el motivo de una pausa). Solo texto seguro, nunca payloads.
     */
    public function auditAs(AuditEvent $event, array $context = []): static
    {
        $this->pendingAuditEvent = $event;
        $this->pendingAuditContext = $context;

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
            'source' => app(AuditOrigin::class)->current(),
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
        $context = $this->pendingAuditContext;
        $this->pendingAuditEvent = null;
        $this->pendingAuditContext = [];

        $valueFields = static::auditValueFields();
        $audited = array_merge($valueFields, static::auditNameOnlyFields());
        $changed = $deleting ? [] : array_values(array_intersect($fields, $audited));

        if (! $creating && ! $deleting && $changed === [] && $context === []) {
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

        foreach ($context as $key => $value) {
            $changed[] = $key;
            $new[$key] = $value;
        }

        AuditLog::query()->create([
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'user_id' => auth()->id(),
            'source' => app(AuditOrigin::class)->current(),
            'changed_fields' => $changed,
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
        ]);
    }
}
