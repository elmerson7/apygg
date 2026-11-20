<?php

namespace App\Imports;

use App\Contracts\UserServiceInterface;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo: crea usuarios desde un CSV/XLSX con columnas `email`, `name` y opcional `username`.
 * Idempotente por email (sin distinguir mayúsculas): el que ya existe se omite. NO importa roles
 * (evita escalada de privilegios): reciben el rol por defecto. Sin contraseña usable: deben pedir
 * «olvidé mi contraseña».
 */
class UsersImporter extends Importer
{
    public function __construct(private readonly UserServiceInterface $users) {}

    public function key(): string
    {
        return 'users';
    }

    public function permission(): string
    {
        return 'users.import';
    }

    public function requiredHeaders(): array
    {
        return ['email', 'name'];
    }

    public function optionalHeaders(): array
    {
        return ['username'];
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }

    public function process(array $rows, ?int $tenantId): array
    {
        $emails = array_map(fn (array $r) => mb_strtolower((string) $r['data']['email']), $rows);
        $existing = array_flip(User::withTrashed()
            ->whereIn(DB::raw('lower(email)'), $emails)
            ->pluck('email')
            ->map(fn ($e) => mb_strtolower((string) $e))
            ->all());

        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $item) {
            $data = $item['data'];
            $email = mb_strtolower((string) $data['email']);

            if (isset($existing[$email])) {
                $skipped++;

                continue;
            }

            if (! empty($data['username']) && User::withTrashed()->where('username', $data['username'])->exists()) {
                $errors[] = ['row' => $item['row'], 'message' => 'username_in_use'];

                continue;
            }

            try {
                $this->users->create([
                    'email' => $email,
                    'name' => (string) $data['name'],
                    'username' => $data['username'] ?? null,
                    'password' => Str::random(40),
                ]);
                $existing[$email] = true;
                $created++;
            } catch (BusinessLogicException) {
                $skipped++; // carrera: otro proceso lo creó entre la comprobación y el alta
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }
}
