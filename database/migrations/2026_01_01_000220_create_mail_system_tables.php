<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sistema de correo multi-cuenta.
 *
 * - mail_providers        → proveedor y driver (resend, smtp, ses…)
 * - mail_accounts         → direcciones de envío asociadas a un proveedor
 * - mail_template_configs → qué cuenta usa cada plantilla y si está activa
 * - mail_settings         → pausa global y límites de envío
 * - mail_logs             → registro de cada correo enviado
 * - mail_events           → eventos del proveedor recibidos por webhook
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver');                        // resend, smtp, ses…
            $table->jsonb('config')->nullable();             // datos NO sensibles (host, puerto)
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_provider_id')->nullable()
                ->constrained('mail_providers')->nullOnDelete();
            $table->string('name');
            $table->string('from_email');
            $table->string('from_name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_default', 'priority']);
        });

        Schema::create('mail_template_configs', function (Blueprint $table) {
            $table->id();
            $table->string('template_key')->unique();
            $table->string('template_name');
            $table->foreignId('mail_account_id')->nullable()
                ->constrained('mail_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('subject_template')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });

        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->nullable()
                ->constrained('mail_accounts')->nullOnDelete();
            $table->string('template_key')->nullable();
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('recipient_type')->nullable();    // user, admin, custom…
            $table->string('subject');
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['mail_account_id', 'status']);
            $table->index(['template_key', 'created_at']);
            $table->index(['to_email', 'created_at']);
            $table->index('provider_message_id');
            $table->index('status');
        });

        Schema::create('mail_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_log_id')->nullable()
                ->constrained('mail_logs')->cascadeOnDelete();
            $table->string('provider_message_id');
            $table->string('provider_event_id')->nullable()->unique();  // idempotencia
            $table->string('type');
            $table->jsonb('payload');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['mail_log_id', 'created_at']);
            $table->index(['provider_message_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_events');
        Schema::dropIfExists('mail_logs');
        Schema::dropIfExists('mail_settings');
        Schema::dropIfExists('mail_template_configs');
        Schema::dropIfExists('mail_accounts');
        Schema::dropIfExists('mail_providers');
    }
};
