<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('sync_adapter_instances')->where('slug', 'netbox')->exists()) {
            return;
        }

        $now = now();
        DB::table('sync_adapter_instances')->insert([
            'slug' => 'netbox',
            'adapter_type' => 'netbox',
            'label' => 'NetBox',
            'active' => false,
            'built_in' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('sync_adapter_instances')
            ->where('slug', 'netbox')
            ->where('built_in', true)
            ->delete();
    }
};
