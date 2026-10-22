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
            $table->boolean('oidc_login')->nullable()->default(0);
            $table->string('oidc_issuer')->nullable()->default(null);
            $table->string('oidc_client_id')->nullable()->default(null);
            $table->text('oidc_client_secret')->nullable()->default(null);
            $table->string('oidc_scopes')->nullable()->default(null);
            $table->string('oidc_username_claim')->nullable()->default(null);
            $table->string('oidc_button_label')->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'oidc_login',
                'oidc_issuer',
                'oidc_client_id',
                'oidc_client_secret',
                'oidc_scopes',
                'oidc_username_claim',
                'oidc_button_label',
            ]);
        });
    }
};
