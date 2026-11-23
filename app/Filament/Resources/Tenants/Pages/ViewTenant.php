<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Contracts\AuditServiceInterface;
use App\Contracts\PlanLimitsInterface;
use App\Contracts\TenantAdminServiceInterface;
use App\Enums\TenantStatus;
use App\Filament\Resources\Tenants\TenantResource;
use App\Filament\Support\Failure;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Ficha de empresa. Las acciones llaman a los servicios (nunca `$record->update()`) y el motivo de las
 * sensibles se valida con `AuditServiceInterface::requireReason` antes de actuar.
 */
class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    /** El registro de la ficha, ya tipado como empresa (Filament lo declara `Model|int|string`). */
    private function tenant(): Tenant
    {
        $record = $this->record;

        return $record instanceof Tenant ? $record : throw new \LogicException('La ficha no tiene una empresa cargada.');
    }

    /** @var Tenant */
    public Model|int|string|null $record;

    /** Resumen de texto del plan y de cada límite (con su origen: plan u override). */
    public static function describePlan(Tenant $tenant): string
    {
        $overview = app(PlanLimitsInterface::class)->overview((int) $tenant->getKey());
        $lines = ['Plan: '.($overview['plan'] ?? 'por defecto').($overview['enabled'] ? '' : ' (límites apagados: PLAN_LIMITS_ENABLED=false)')];

        foreach ((array) $overview['limits'] as $key => $limit) {
            $lines[] = sprintf('%s: %s [%s]', $key, $limit['limit'] ?? 'ilimitado', $limit['source']);
        }

        return implode("\n", $lines);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->statusAction('suspend', 'Suspender', 'danger', TenantStatus::Active, 'La empresa dejará de poder operar (403 tenant_suspended) hasta que se reactive.'),
            $this->statusAction('reactivate', 'Reactivar', 'success', TenantStatus::Suspended, 'La empresa vuelve a operar.'),
            $this->limitAction(),
            $this->planAction(),
        ];
    }

    private function statusAction(string $name, string $label, string $color, TenantStatus $visibleWhen, string $description): Action
    {
        return Action::make($name)
            ->label($label)->color($color)
            ->modalDescription($description)
            ->schema([Textarea::make('reason')->label('Motivo')->required()->maxLength((int) config('audit.reason_max_length', 500))])
            ->visible(fn (): bool => $this->tenant()->status === $visibleWhen && Gate::allows('update', $this->tenant()))
            ->action(function (array $data) use ($name): void {
                Gate::authorize('update', $this->tenant());

                $service = app(TenantAdminServiceInterface::class);
                $actor = Auth::guard('web')->user();

                $tenant = Failure::guard(fn (): Tenant => $name === 'suspend'
                    ? $service->suspend($this->tenant(), $data['reason'], $actor)
                    : $service->reactivate($this->tenant(), $data['reason'], $actor));

                if ($tenant instanceof Tenant) {
                    $this->tenant()->refresh();
                    Notification::make()->success()->title($name === 'suspend' ? 'Empresa suspendida' : 'Empresa reactivada')->send();
                }
            });
    }

    private function limitAction(): Action
    {
        return Action::make('limit')
            ->label('Override de límite')->color('gray')
            ->schema([
                Select::make('key')->label('Límite')->required()
                    ->options(collect(array_keys((array) config('plans.limits', [])))->mapWithKeys(fn (string $k): array => [$k => $k])->all()),
                Toggle::make('unlimited')->label('Ilimitado')->live(),
                TextInput::make('value')->label('Valor')->numeric()->minValue(0)
                    ->required(fn ($get): bool => ! $get('unlimited'))
                    ->hidden(fn ($get): bool => (bool) $get('unlimited')),
                Textarea::make('reason')->label('Motivo')->required()->maxLength((int) config('audit.reason_max_length', 500)),
            ])
            ->visible(fn (): bool => Gate::forUser(Auth::guard('web')->user())->allows('update', $this->tenant()) && (bool) Auth::guard('web')->user()?->can('plan-limits.manage'))
            ->action(function (array $data): void {
                abort_unless((bool) Auth::guard('web')->user()?->can('plan-limits.manage'), 403);

                $saved = Failure::guard(function () use ($data): true {
                    $audit = app(AuditServiceInterface::class);
                    $reason = $audit->requireReason('tenant.limit-override', $data['reason']);
                    $value = ! empty($data['unlimited']) ? null : (int) $data['value'];

                    app(PlanLimitsInterface::class)->setOverride((int) $this->tenant()->getKey(), $data['key'], $value);
                    $audit->recordAction('tenant.limit-override', $this->tenant(), $reason, ['key' => $data['key'], 'value' => $value], (int) $this->tenant()->getKey());

                    return true;
                });

                if ($saved) {
                    Notification::make()->success()->title('Override guardado')->send();
                }
            });
    }

    private function planAction(): Action
    {
        return Action::make('plan')
            ->label('Cambiar plan')->color('gray')
            ->schema([
                Select::make('plan')->label('Plan')->placeholder('Por defecto')
                    ->options(collect(array_keys((array) config('plans.plans', [])))->mapWithKeys(fn (string $k): array => [$k => $k])->all()),
            ])
            ->fillForm(fn (): array => ['plan' => $this->tenant()->plan])
            ->visible(fn (): bool => Gate::forUser(Auth::guard('web')->user())->allows('update', $this->tenant()) && (bool) Auth::guard('web')->user()?->can('plan-limits.manage'))
            ->action(function (array $data): void {
                abort_unless((bool) Auth::guard('web')->user()?->can('plan-limits.manage'), 403);

                app(PlanLimitsInterface::class)->setPlan((int) $this->tenant()->getKey(), $data['plan'] ?: null);
                $this->tenant()->refresh();
                Notification::make()->success()->title('Plan actualizado')->send();
            });
    }
}
