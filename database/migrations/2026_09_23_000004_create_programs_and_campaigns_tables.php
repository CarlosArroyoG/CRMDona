<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programa = destino permanente (Becas). Campaña = esfuerzo temporal
 * (Navidad 2026), opcionalmente de un programa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->index();
            $table->timestamps();
        });
        DB::statement('create unique index programs_name_unique on programs (lower(name))');

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->index();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->decimal('goal_amount', 12, 2)->nullable();
            $table->timestamps();
        });
        DB::statement('alter table campaigns add constraint campaigns_dates_order check (ends_on is null or starts_on is null or ends_on >= starts_on)');
        DB::statement('alter table campaigns add constraint campaigns_goal_positive check (goal_amount is null or goal_amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('programs');
    }
};
