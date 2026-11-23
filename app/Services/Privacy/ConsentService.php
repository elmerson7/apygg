<?php

namespace App\Services\Privacy;

use App\Contracts\AuditServiceInterface;
use App\Contracts\ConsentServiceInterface;
use App\Exceptions\PrivacyException;
use App\Models\LegalDocument;
use App\Models\User;
use App\Models\UserConsent;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Documentos legales y consentimientos. Sin estado entre peticiones (Octane).
 */
class ConsentService implements ConsentServiceInterface
{
    public function __construct(private readonly AuditServiceInterface $audit) {}

    public function publish(array $data, ?User $author): LegalDocument
    {
        return DB::transaction(function () use ($data, $author): LegalDocument {
            $existing = LegalDocument::query()->where('key', $data['key'])->where('version', $data['version'])->first();
            $effective = $existing instanceof LegalDocument
                ? $existing->effective_at
                : (isset($data['effective_at']) ? Carbon::parse($data['effective_at']) : now());

            $document = LegalDocument::query()->create([
                'key' => $data['key'],
                'version' => $data['version'],
                'locale' => $data['locale'],
                'title' => $data['title'],
                'body' => $data['body'],
                'content_hash' => hash('sha256', $data['title']."\n".$data['body']),
                'effective_at' => $effective,
                'created_by' => $author?->getKey(),
            ]);

            $this->audit->recordAction('legal.document-published', null, null, [
                'key' => $data['key'], 'version' => $data['version'], 'locale' => $data['locale'], 'effective_at' => $effective->toIso8601String(),
            ]);

            return $document;
        });
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return LegalDocument::query()
            ->when(! empty($filters['key']), fn ($q) => $q->where('key', $filters['key']))
            ->when(! empty($filters['locale']), fn ($q) => $q->where('locale', $filters['locale']))
            ->orderByDesc('effective_at')->orderByDesc('id')
            ->paginate($perPage);
    }

    public function current(?string $locale = null): array
    {
        $out = [];

        foreach ($this->currentVersions() as $key => $version) {
            $out[] = $this->describe($this->pick($key, $version, $locale));
        }

        return $out;
    }

    public function currentFor(string $key, ?string $locale = null): array
    {
        $version = $this->currentVersions()[$key] ?? null;

        if ($version === null) {
            throw PrivacyException::documentUnknown($key);
        }

        return $this->describe($this->pick($key, $version, $locale), true);
    }

    public function stateFor(User $user): array
    {
        $latest = $this->latestActions($user);
        $required = $this->required();
        $out = [];

        foreach ($this->currentVersions() as $key => $version) {
            $last = $latest[$key] ?? null;
            $status = match (true) {
                $last === null => 'pending',
                $last->action === UserConsent::WITHDRAWN => 'withdrawn',
                $last->version === $version => 'accepted',
                default => 'outdated',
            };

            $out[] = [
                'key' => $key,
                'current_version' => $version,
                'accepted_version' => $last !== null && $last->action === UserConsent::ACCEPTED ? $last->version : null,
                'status' => $status,
                'mandatory' => in_array($key, $required, true),
                'updated_at' => $last?->created_at,
            ];
        }

        return $out;
    }

    public function pendingFor(User $user, ?string $locale = null): array
    {
        $required = $this->required();
        $latest = $this->latestActions($user);
        $pending = [];

        foreach ($this->currentVersions() as $key => $version) {
            if (! in_array($key, $required, true)) {
                continue;
            }

            $last = $latest[$key] ?? null;
            if ($last === null || $last->action !== UserConsent::ACCEPTED || $last->version !== $version) {
                $pending[] = ['key' => $key, 'version' => $version, 'title' => $this->pick($key, $version, $locale)->title];
            }
        }

        return $pending;
    }

    public function accept(User $user, array $documents, ?string $ip, ?string $userAgent, ?string $locale = null): void
    {
        $current = $this->currentVersions();

        // Valida todo antes de escribir: o se aceptan todos o ninguno.
        foreach ($documents as $doc) {
            if (! isset($current[$doc['key']])) {
                throw PrivacyException::documentUnknown($doc['key']);
            }
            if ($current[$doc['key']] !== $doc['version']) {
                throw PrivacyException::consentVersionOutdated($doc['key']);
            }
        }

        DB::transaction(function () use ($user, $documents, $ip, $userAgent, $locale): void {
            // Serializa las escrituras de este usuario: dos aceptaciones simultáneas no duplican filas.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $latest = $this->latestActions($user);

            foreach ($documents as $doc) {
                $last = $latest[$doc['key']] ?? null;
                if ($last !== null && $last->action === UserConsent::ACCEPTED && $last->version === $doc['version']) {
                    continue;
                }

                UserConsent::query()->create([
                    'user_id' => $user->getKey(),
                    'document_key' => $doc['key'],
                    'version' => $doc['version'],
                    'locale' => $locale ?? app()->getLocale(),
                    'action' => UserConsent::ACCEPTED,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
                ]);
            }
        });
    }

    public function withdraw(User $user, string $key, ?string $ip, ?string $userAgent): void
    {
        if (in_array($key, $this->required(), true)) {
            throw PrivacyException::consentMandatory($key);
        }

        $version = $this->currentVersions()[$key] ?? null;
        if ($version === null) {
            throw PrivacyException::documentUnknown($key);
        }

        DB::transaction(function () use ($user, $key, $version, $ip, $userAgent): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $last = $this->latestActions($user)[$key] ?? null;

            if ($last === null || $last->action === UserConsent::WITHDRAWN) {
                return;
            }

            UserConsent::query()->create([
                'user_id' => $user->getKey(),
                'document_key' => $key,
                'version' => $last->version ?: $version,
                'locale' => app()->getLocale(),
                'action' => UserConsent::WITHDRAWN,
                'ip_address' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
            ]);
        });
    }

    /**
     * @return list<string>
     */
    private function required(): array
    {
        return array_values(array_map('strval', (array) config('privacy.consents.required', [])));
    }

    /**
     * Versión vigente por clave: la de `effective_at` más reciente que ya empezó.
     *
     * @return array<string, string>
     */
    private function currentVersions(): array
    {
        $rows = DB::select(
            'SELECT DISTINCT ON ("key") "key", "version" FROM legal_documents WHERE effective_at <= ? ORDER BY "key", effective_at DESC, id DESC',
            [now()],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->key] = (string) $row->version;
        }

        return $out;
    }

    /**
     * Última acción del usuario por clave.
     *
     * @return array<string, \stdClass>
     */
    private function latestActions(User $user): array
    {
        $rows = DB::table('user_consents')
            ->whereIn('id', fn ($q) => $q->selectRaw('MAX(id)')->from('user_consents')->where('user_id', $user->getKey())->groupBy('document_key'))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->document_key] = $row;
        }

        return $out;
    }

    private function pick(string $key, string $version, ?string $locale): LegalDocument
    {
        $docs = LegalDocument::query()->where('key', $key)->where('version', $version)->get();

        foreach (array_filter([$locale ?? app()->getLocale(), (string) config('app.fallback_locale')]) as $wanted) {
            $hit = $docs->firstWhere('locale', $wanted);
            if ($hit instanceof LegalDocument) {
                return $hit;
            }
        }

        return $docs->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(LegalDocument $doc, bool $withBody = false): array
    {
        $row = [
            'key' => $doc->key,
            'version' => $doc->version,
            'locale' => $doc->locale,
            'title' => $doc->title,
            'effective_at' => $doc->effective_at->toIso8601String(),
            'content_hash' => $doc->content_hash,
            'mandatory' => in_array($doc->key, $this->required(), true),
        ];

        return $withBody ? $row + ['body' => $doc->body] : $row;
    }
}
