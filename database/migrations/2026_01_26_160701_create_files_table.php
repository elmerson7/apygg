<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('user_id')->nullable()->index();
            $table->string('name');
            $table->string('filename');
            $table->string('path');
            $table->string('url')->nullable();
            $table->string('disk')->default('public');
            $table->string('mime_type');
            $table->string('extension');
            $table->bigInteger('size');
            $table->enum('type', ['image', 'document', 'video', 'audio', 'archive', 'other'])->default('other');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('deleted_by')->nullable();

            $table->index('user_id');
            $table->index('type');
            $table->index('category');
            $table->index('created_at');
            $table->index('expires_at');
            $table->index(['user_id', 'type']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
