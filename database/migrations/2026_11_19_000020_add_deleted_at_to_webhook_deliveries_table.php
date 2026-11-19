<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `WebhookDelivery` hereda `SoftDeletes` del Model base, pero la tabla se creó
 * sin `deleted_at`: cualquier consulta sobre las entregas (p. ej.
 * `$webhook->deliveries()->count()` en WebhookResource) fallaba con 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('webhook_deliveries', 'deleted_at')) {
            Schema::table('webhook_deliveries', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('webhook_deliveries', 'deleted_at')) {
            Schema::table('webhook_deliveries', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
