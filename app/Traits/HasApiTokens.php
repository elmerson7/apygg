<?php

namespace App\Traits;

use Illuminate\Support\Str;

/**
 * Nota: Este trait requiere que exista el modelo ApiKey.
 * Si no existe, crear en app/Models/ApiKey.php
 */

/**
 * Trait HasApiTokens
 *
 * Proporciona métodos para crear, revocar y listar tokens API.
 * Requiere tabla api_keys con estructura estándar.
 */
trait HasApiTokens
{
    /**
     * Relación con API Keys.
     */
    public function apiKeys()
    {
        // Intentar encontrar la clase ApiKey
        $apiKeyClass = $this->getApiKeyClass();

        return $this->hasMany($apiKeyClass, 'user_id');
    }

    /**
     * Obtener la clase ApiKey a usar.
     */
    protected function getApiKeyClass(): string
    {
        if (class_exists(\App\Models\ApiKey::class)) {
            return \App\Models\ApiKey::class;
        }

        // Fallback a namespace alternativo si existe
        if (class_exists(\App\Models\Logs\ApiKey::class)) {
            return \App\Models\Logs\ApiKey::class;
        }

        // Si no existe, lanzar excepción informativa
        throw new \RuntimeException(
            'ApiKey model not found. Please create App\Models\ApiKey or configure the model class.'
        );
    }

    /**
     * Obtener API Keys activas (no expiradas ni eliminadas).
     */
    public function activeApiKeys()
    {
        return $this->apiKeys()
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Crear un nuevo API Key.
     *
     * @param  string  $name  Nombre descriptivo del token
     * @param  array  $scopes  Scopes/permissions del token
     * @param  \DateTimeInterface|null  $expiresAt  Fecha de expiración (null = sin expiración)
     * @return ApiKey
     */
    public function createApiKey(string $name, array $scopes = [], ?\DateTimeInterface $expiresAt = null)
    {
        $apiKeyClass = $this->getApiKeyClass();
        $key = Str::random(64); // Generar clave aleatoria de 64 caracteres

        return $apiKeyClass::create([
            'user_id' => $this->getKey(),
            'name' => $name,
            'key' => hash('sha256', $key), // Guardar hash, nunca el valor plano
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Revocar un API Key específico.
     *
     * @param  string  $keyId  ID del API Key a revocar
     */
    public function revokeApiKey(string $keyId): bool
    {
        $apiKey = $this->apiKeys()->find($keyId);

        if (! $apiKey) {
            return false;
        }

        return $apiKey->delete();
    }

    /**
     * Revocar todos los API Keys del usuario.
     *
     * @return int Número de tokens revocados
     */
    public function revokeAllApiKeys(): int
    {
        return $this->apiKeys()->delete();
    }

    /**
     * Verificar si un token es válido.
     *
     * @param  string  $token  Token a verificar
     * @return ApiKey|null
     */
    public function findApiKeyByToken(string $token)
    {
        $hashedToken = hash('sha256', $token);

        return $this->activeApiKeys()
            ->where('key', $hashedToken)
            ->first();
    }

    /**
     * Verificar si el usuario tiene un token con un scope específico.
     *
     * @param  string  $token  Token a verificar
     * @param  string  $scope  Scope requerido
     */
    public function hasApiKeyWithScope(string $token, string $scope): bool
    {
        $apiKey = $this->findApiKeyByToken($token);

        if (! $apiKey) {
            return false;
        }

        $scopes = $apiKey->scopes ?? [];

        // Si no tiene scopes definidos, tiene acceso total
        if (empty($scopes)) {
            return true;
        }

        return in_array($scope, $scopes) || in_array('*', $scopes);
    }

    /**
     * Actualizar último uso de un token.
     *
     * @param  string  $token  Token usado
     */
    public function updateApiKeyLastUsed(string $token): bool
    {
        $apiKey = $this->findApiKeyByToken($token);

        if (! $apiKey) {
            return false;
        }

        return $apiKey->update([
            'last_used_at' => now(),
        ]);
    }

    /**
     * Obtener estadísticas de API Keys.
     */
    public function getApiKeyStats(): array
    {
        $total = $this->apiKeys()->count();
        $active = $this->activeApiKeys()->count();
        $expired = $this->apiKeys()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();
        $revoked = $this->apiKeys()->onlyTrashed()->count();

        return [
            'total' => $total,
            'active' => $active,
            'expired' => $expired,
            'revoked' => $revoked,
        ];
    }
}
