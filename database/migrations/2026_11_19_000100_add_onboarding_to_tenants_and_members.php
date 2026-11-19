<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('settings');
        });

        // Rol DEL TENANT (owner/admin/member...). Las filas existentes quedan como `member`:
        // asigna al dueño con `php artisan tenancy:set-role` (docs/upgrading.md).
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->string('role')->default('member')->after('status');
            $table->index(['tenant_id', 'status', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status', 'role']);
            $table->dropColumn('role');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('trial_ends_at');
        });
    }
};
