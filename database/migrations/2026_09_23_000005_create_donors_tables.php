<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Donantes (ADR-003): una tabla con reglas por tipo de persona, datos
 * fiscales 1:1 opcionales y etiquetas. Sin dirección postal (sin finalidad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donors', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20)->index();
            // Persona física
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('second_last_name')->nullable();
            $table->date('birth_date')->nullable();
            // Persona moral
            $table->string('legal_name')->nullable();
            $table->string('contact_name')->nullable();
            // Comunes
            $table->string('display_name')->storedAs(
                "case when type = 'individual' "
                ."then first_name || ' ' || last_name || coalesce(' ' || second_last_name, '') "
                .'else legal_name end'
            );
            $table->string('email')->nullable()->index();
            $table->string('phone', 30)->nullable();
            $table->text('notes')->nullable();
            // Consentimientos (independientes entre sí)
            $table->string('privacy_notice_version', 50)->nullable();
            $table->timestamp('privacy_notice_accepted_at')->nullable();
            $table->boolean('accepts_communications')->default(false);
            $table->timestamp('communications_consent_updated_at')->nullable();

            $table->timestamp('archived_at')->nullable()->index();
            $table->foreignId('registered_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        DB::statement("alter table donors add constraint donors_type_valid check (type in ('individual', 'organization'))");
        DB::statement(<<<'SQL'
            alter table donors add constraint donors_fields_by_type check (
                (type = 'individual' and first_name is not null and last_name is not null
                    and legal_name is null and contact_name is null)
                or
                (type = 'organization' and legal_name is not null and first_name is null
                    and last_name is null and second_last_name is null and birth_date is null)
            )
        SQL);
        DB::statement('alter table donors add constraint donors_privacy_notice_evidence check ((privacy_notice_version is null) = (privacy_notice_accepted_at is null))');

        Schema::create('donor_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donor_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('rfc', 13)->index();
            $table->string('tax_name');
            $table->string('tax_regime', 3);
            $table->string('tax_postal_code', 5);
            $table->string('cfdi_use', 4)->nullable();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 50);
            $table->timestamps();
        });
        DB::statement('create unique index tags_name_unique on tags (lower(name))');

        Schema::create('donor_tag', function (Blueprint $table): void {
            $table->foreignId('donor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->restrictOnDelete();
            $table->primary(['donor_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('donor_tax_profiles');
        Schema::dropIfExists('donors');
    }
};
