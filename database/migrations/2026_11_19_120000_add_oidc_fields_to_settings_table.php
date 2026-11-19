<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('oidc_enabled')->default(0);
            $table->string('oidc_display_name')->nullable()->default(null);
            $table->string('oidc_issuer')->nullable()->default(null);
            $table->string('oidc_client_id')->nullable()->default(null);
            $table->text('oidc_client_secret')->nullable()->default(null);
            $table->string('oidc_scopes')->nullable()->default(null);
            $table->string('oidc_username_claim')->nullable()->default(null);
            $table->boolean('oidc_forcelogin')->default(0);
            $table->boolean('oidc_slo')->default(0);
            $table->boolean('oidc_auto_create_users')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'oidc_enabled',
                'oidc_display_name',
                'oidc_issuer',
                'oidc_client_id',
                'oidc_client_secret',
                'oidc_scopes',
                'oidc_username_claim',
                'oidc_forcelogin',
                'oidc_slo',
                'oidc_auto_create_users',
            ]);
        });
    }
};
