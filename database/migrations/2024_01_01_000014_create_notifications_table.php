<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Eliminar tabla con CASCADE para eliminar índices asociados
        DB::statement('DROP TABLE IF EXISTS notifications CASCADE');

        // Eliminar índices huérfanos explícitamente por si acaso
        DB::statement('DROP INDEX IF EXISTS notifications_notifiable_type_notifiable_id_index CASCADE');
        DB::statement('DROP INDEX IF EXISTS notifications_notifiable_index CASCADE');
        DB::statement('DROP INDEX IF EXISTS notifications_read_at_index CASCADE');
        DB::statement('DROP INDEX IF EXISTS notifications_created_at_index CASCADE');

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable'); // Crea notifiable_type y notifiable_id
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Crear índices con nombres explícitos para evitar conflictos
        DB::statement('CREATE INDEX IF NOT EXISTS notifications_notifiable_index ON notifications (notifiable_type, notifiable_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS notifications_read_at_index ON notifications (read_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS notifications_created_at_index ON notifications (created_at)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
