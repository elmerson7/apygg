<?php

namespace App\Enums;

/**
 * Códigos de error estables y legibles por máquina.
 *
 * Todo error de la API lleva un `code` (junto a `request_id`). El `status` HTTP
 * dice la clase de fallo; el `code` dice cuál es, y NO cambia aunque cambie el
 * mensaje o el idioma. Los clientes deben ramificar por `code`, no por `message`.
 *
 * Extensible por proyecto: una excepción propia declara su código sobrescribiendo
 * `ApiException::errorCode()` (puede devolver un case de este enum o un string
 * propio, p. ej. `invoice_already_paid`). Cada case nuevo de este enum debe
 * documentarse en docs/api-response-structure.md (lo comprueba ErrorCodeDocsTest).
 */
enum ErrorCode: string
{
    case BadRequest = 'bad_request';
    case ValidationFailed = 'validation_failed';
    case Unauthenticated = 'unauthenticated';
    case TokenExpired = 'token_expired';
    case TokenInvalid = 'token_invalid';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case Duplicate = 'duplicate';
    case BusinessRule = 'business_rule';
    case PayloadTooLarge = 'payload_too_large';
    case RateLimited = 'rate_limited';
    case AccountLocked = 'account_locked';
    case AccountBlocked = 'account_blocked';
    case FeatureDisabled = 'feature_disabled';
    case IpNotAllowed = 'ip_not_allowed';
    case ApiKeyRequired = 'api_key_required';
    case ApiKeyInvalid = 'api_key_invalid';
    case InsufficientScope = 'insufficient_scope';
    case TenantInvalid = 'tenant_invalid';
    case TenantInactive = 'tenant_inactive';
    case TenantRequired = 'tenant_required';
    case CrossTenant = 'cross_tenant';
    case ServerError = 'server_error';
    case UpstreamError = 'upstream_error';
    case ServiceUnavailable = 'service_unavailable';
    case HttpError = 'http_error';

    /**
     * Código por defecto según el status HTTP.
     */
    public static function fromStatus(int $status): self
    {
        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            413 => self::PayloadTooLarge,
            422 => self::ValidationFailed,
            429 => self::RateLimited,
            502, 504 => self::UpstreamError,
            503 => self::ServiceUnavailable,
            default => $status >= 500 ? self::ServerError : self::HttpError,
        };
    }

    /**
     * Código a partir del `type` histórico de la respuesta (el que ya existía antes que `code`).
     */
    public static function fromType(?string $type): ?self
    {
        if ($type === null || $type === '') {
            return null;
        }

        return self::tryFrom($type) ?? match ($type) {
            'unauthorized' => self::Unauthenticated,
            'validation_error' => self::ValidationFailed,
            'rate_limit_exceeded' => self::RateLimited,
            'internal_server_error' => self::ServerError,
            'business_logic_error' => self::BusinessRule,
            'login_locked' => self::AccountLocked,
            'access_denied' => self::IpNotAllowed,
            'bad_gateway', 'gateway_timeout' => self::UpstreamError,
            default => null,
        };
    }

    /**
     * Resuelve cualquier código (case, string propio o nulo) al string que viaja en la respuesta.
     */
    public static function resolve(self|string|null $code, int $status, ?string $type = null): string
    {
        if ($code instanceof self) {
            return $code->value;
        }

        if (is_string($code) && $code !== '') {
            return $code;
        }

        return (self::fromType($type) ?? self::fromStatus($status))->value;
    }
}
