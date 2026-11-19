<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('status')->default('active')->after('domain');
            $table->foreignId('parent_id')->nullable()->after('status')->constrained('tenants')->nullOnDelete();
            $table->index('status');
        });

        DB::table('tenants')->where('is_active', false)->update(['status' => 'suspended']);

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'slug']);
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('settings');
        });

        DB::table('tenants')->where('status', '!=', 'active')->update(['is_active' => false]);

        Schema::table('tenants', function (Blueprint $table) {
            $table->index(['is_active', 'slug']);
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('status');
        });
    }
};
