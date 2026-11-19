<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versión de tokens del usuario.
 *
 * Cada JWT lleva el claim `tv` con la versión vigente al emitirlo. Subirla
 * invalida de golpe todas las sesiones (access y refresh) del usuario: se usa
 * al cambiar o resetear la contraseña. Aditiva: las filas existentes quedan en
 * 0 y los tokens ya emitidos (sin claim `tv`) siguen valiendo hasta que se
 * suba la versión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('token_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('token_version');
        });
    }
};
