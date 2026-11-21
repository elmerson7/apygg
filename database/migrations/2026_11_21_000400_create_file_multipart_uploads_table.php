<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Datos de la subida por partes de una intención (file_upload_intents): 1 a 1, se borra con ella.
        // Sin tenant_id propio: solo se alcanza a través de su intención, que sí está aislada por tenant.
        Schema::create('file_multipart_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_upload_intent_id')->unique()->constrained('file_upload_intents')->cascadeOnDelete();
            $table->string('upload_id', 1024);          // id de la subida multipart en el proveedor (S3 UploadId)
            $table->bigInteger('part_size');            // bytes por parte (todas salvo la última)
            $table->unsignedInteger('parts_count');     // partes esperadas = ceil(size / part_size)
            $table->timestamps();
            $table->softDeletes();                      // base Model: SoftDeletes (se borra de verdad con la intención)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_multipart_uploads');
    }
};
