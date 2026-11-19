<?php

namespace Tests\Support;

use App\Enums\TenantMembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ayudas para probar multiempresa: enciende la config, crea la tabla de TenantNote
 * (dentro de la transacción del test, se revierte sola) y fabrica tenants y miembros.
 */
trait InteractsWithTenancy
{
    public function enableTenancy(): void
    {
        config(['multi-tenancy.enabled' => true]);

        if (! Schema::hasTable('tenant_notes')) {
            Schema::create('tenant_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('title');
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function makeTenant(string $slug, TenantStatus $status = TenantStatus::Active): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => $status]);
    }

    public function addMember(Tenant $tenant, User $user, TenantMembershipStatus $status = TenantMembershipStatus::Active): void
    {
        $tenant->users()->attach($user->getKey(), ['status' => $status->value, 'joined_at' => now()]);
    }
}
