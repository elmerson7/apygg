<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estado de una flag declarada en config/features.php. Sin fila manda el config.
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('rollout_percentage')->default(100);
            $table->string('rollout_by', 10)->default('user'); // user | tenant
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Override puntual por tenant o por usuario (exactamente uno de los dos).
        Schema::create('feature_flag_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('feature_key', 100);
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('enabled');
            $table->timestamps();

            $table->index('feature_key');
        });

        DB::statement('ALTER TABLE feature_flag_overrides ADD CONSTRAINT feature_flag_overrides_one_subject CHECK ((tenant_id IS NOT NULL) <> (user_id IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX feature_flag_overrides_tenant_unique ON feature_flag_overrides (feature_key, tenant_id) WHERE tenant_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX feature_flag_overrides_user_unique ON feature_flag_overrides (feature_key, user_id) WHERE user_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flag_overrides');
        Schema::dropIfExists('feature_flags');
    }
};
