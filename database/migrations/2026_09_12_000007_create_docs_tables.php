<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asistente de documentación.
 *
 * - doc_chunks        → fragmentos de docs/*.md, con búsqueda full-text nativa
 * - doc_conversations → hilos de preguntas, para poder repreguntar
 *
 * La búsqueda usa `tsvector` de PostgreSQL, que no necesita extensiones. Para
 * búsqueda semántica con pgvector, ver docs/docs-assistant.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        $config = config('docs-assistant.text_search_config', 'spanish');

        Schema::create('doc_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('doc_id');                       // ruta relativa del .md
            $table->string('scope', 30)->default('general');
            $table->string('title')->nullable();            // título del documento
            $table->string('heading')->nullable();          // encabezado de la sección
            $table->text('content');
            $table->string('content_hash', 64)->nullable(); // evita reindexar lo que no cambió
            $table->integer('position')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('scope');
            $table->index(['scope', 'doc_id']);
            $table->index('content_hash');
        });

        // Columna generada: se mantiene sola en cada INSERT/UPDATE, así que no
        // hay forma de que el índice quede desincronizado con el contenido.
        DB::statement("
            ALTER TABLE doc_chunks
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('{$config}', coalesce(heading, '')), 'A') ||
                setweight(to_tsvector('{$config}', coalesce(title, '')), 'B') ||
                setweight(to_tsvector('{$config}', content), 'C')
            ) STORED
        ");

        DB::statement('CREATE INDEX idx_doc_chunks_search ON doc_chunks USING GIN (search_vector)');

        Schema::create('doc_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 30)->default('general');
            $table->string('title')->nullable();
            $table->jsonb('messages')->default('[]');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_conversations');
        Schema::dropIfExists('doc_chunks');
    }
};
