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
        Schema::table('organization_settings', function (Blueprint $table): void {
            $table->string('global_cfdi_periodicity', 10)->default('daily');
        });
        DB::table('organization_settings')->whereNull('donation_legend')->update(['donation_legend' => 'Este comprobante ampara un donativo, el cual será destinado por la donataria a los fines propios de su objeto social. En el caso de que los bienes donados hayan sido deducidos previamente para los efectos del impuesto sobre la renta, este donativo no es deducible.']);
        DB::statement("alter table organization_settings add constraint organization_settings_global_cfdi_periodicity_valid check (global_cfdi_periodicity in ('daily', 'weekly', 'monthly'))");

        Schema::table('donor_tax_profiles', function (Blueprint $table): void {
            $table->boolean('foreign_resident')->default(false);
            $table->string('foreign_tax_id', 100)->nullable();
        });

        Schema::table('donations', function (Blueprint $table): void {
            $table->decimal('in_kind_quantity', 12, 3)->nullable();
            $table->string('in_kind_unit_code', 10)->nullable();
            $table->string('in_kind_product_service_code', 10)->nullable();
            $table->decimal('in_kind_unit_value', 12, 2)->nullable();
            $table->decimal('in_kind_total_value', 12, 2)->nullable();
            $table->string('fiscal_route', 20)->nullable();
            $table->text('fiscal_block_reason')->nullable();
            $table->timestamp('fiscal_late_at')->nullable();
        });
        // Legacy in-kind rows predate the fiscal detail columns. NOT VALID
        // preserves them during rollback/reapply while enforcing the rule on
        // every new row and every future update.
        DB::statement("alter table donations add constraint donations_in_kind_evidence check (kind <> 'in_kind' or (in_kind_quantity > 0 and in_kind_unit_code is not null and in_kind_product_service_code is not null and in_kind_unit_value >= 0 and in_kind_total_value > 0)) not valid");
        DB::statement("alter table donations add constraint donations_fiscal_route_valid check (fiscal_route is null or fiscal_route in ('individual', 'public_general', 'blocked'))");

        Schema::create('global_cfdis', function (Blueprint $table): void {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('periodicity', 10);
            $table->string('provider', 30);
            $table->string('status', 30)->index();
            $table->decimal('total', 12, 2);
            $table->char('currency', 3)->default('MXN');
            $table->string('idempotency_key', 255)->unique();
            $table->string('external_id', 100)->nullable();
            $table->uuid('uuid')->nullable();
            $table->string('series', 25)->nullable();
            $table->string('folio', 40)->nullable();
            $table->timestamp('requested_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 100)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('stamped_at')->nullable();
            $table->string('xml_path', 255)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->timestamps();
        });
        DB::statement("alter table global_cfdis add constraint global_cfdis_periodicity_valid check (periodicity in ('daily', 'weekly', 'monthly'))");
        DB::statement('alter table global_cfdis add constraint global_cfdis_period_valid check (period_end >= period_start)');
        DB::statement("alter table global_cfdis add constraint global_cfdis_status_valid check (status in ('pending', 'stamping', 'stamped', 'failed', 'rejected'))");
        DB::statement('alter table global_cfdis add constraint global_cfdis_total_positive check (total > 0)');
        DB::statement('create unique index global_cfdis_period_unique on global_cfdis (periodicity, period_start, period_end)');
        DB::statement('create unique index global_cfdis_uuid_unique on global_cfdis (uuid) where uuid is not null');

        Schema::table('cfdis', function (Blueprint $table): void {
            $table->foreignId('global_cfdi_id')->nullable()->after('donation_id')->constrained('global_cfdis')->restrictOnDelete();
            $table->foreignId('donation_id')->nullable()->change();
        });
        DB::statement('alter table cfdis add constraint cfdis_one_owner check (num_nonnulls(donation_id, global_cfdi_id) = 1)');

        Schema::create('donation_global_cfdi', function (Blueprint $table): void {
            $table->foreignId('global_cfdi_id')->constrained('global_cfdis')->restrictOnDelete();
            $table->foreignId('donation_id')->constrained()->restrictOnDelete();
            $table->string('operation_number', 80);
            $table->timestamps();
            $table->primary(['global_cfdi_id', 'donation_id']);
            $table->unique('donation_id');
            $table->unique('operation_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_global_cfdi');
        DB::statement('alter table cfdis drop constraint if exists cfdis_one_owner');
        Schema::table('cfdis', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('global_cfdi_id');
            $table->foreignId('donation_id')->nullable(false)->change();
        });
        Schema::dropIfExists('global_cfdis');
        DB::statement('alter table donations drop constraint if exists donations_fiscal_route_valid');
        DB::statement('alter table donations drop constraint if exists donations_in_kind_evidence');
        Schema::table('donations', function (Blueprint $table): void {
            $table->dropColumn(['in_kind_quantity', 'in_kind_unit_code', 'in_kind_product_service_code', 'in_kind_unit_value', 'in_kind_total_value', 'fiscal_route', 'fiscal_block_reason', 'fiscal_late_at']);
        });
        Schema::table('donor_tax_profiles', function (Blueprint $table): void {
            $table->dropColumn(['foreign_resident', 'foreign_tax_id']);
        });
        DB::statement('alter table organization_settings drop constraint if exists organization_settings_global_cfdi_periodicity_valid');
        Schema::table('organization_settings', function (Blueprint $table): void {
            $table->dropColumn('global_cfdi_periodicity');
        });
    }
};
