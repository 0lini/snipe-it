<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_warranty_coverages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('asset_id')->nullable()->index();
            $table->string('provider', 50);
            $table->string('reference')->nullable();
            $table->string('service_level')->nullable();
            $table->string('coverage_type')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable()->index();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'provider', 'reference'], 'asset_warranty_coverages_unique');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->timestamp('warranty_last_checked_at')->nullable()->after('warranty_months');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('warranty_last_checked_at');
        });

        Schema::dropIfExists('asset_warranty_coverages');
    }
};
