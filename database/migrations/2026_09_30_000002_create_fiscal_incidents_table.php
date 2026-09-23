<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donation_id')->constrained()->restrictOnDelete();
            $table->string('type', 50);
            $table->string('status', 20)->default('open');
            $table->string('dedupe_key', 255)->unique();
            $table->text('details');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['donation_id', 'status']);
        });
        DB::statement("alter table fiscal_incidents add constraint fiscal_incidents_status_valid check (status in ('open', 'resolved'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_incidents');
    }
};
