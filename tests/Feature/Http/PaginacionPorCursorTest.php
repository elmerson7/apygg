<?php

use App\Http\Controllers\Controller;
use App\Http\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\Cursor;

uses(RefreshDatabase::class);

/** Controller de prueba que expone el helper (es `protected`, como lo usan los controllers reales). */
function controllerConCursor(): object
{
    return new class extends Controller
    {
        protected ?string $resource = UserResource::class;

        /** @param  list<int>  $ids */
        public function pagina(array $ids, int $porPagina, ?string $cursor = null): JsonResponse
        {
            return $this->sendCursorPaginated(
                User::query()->whereIn('id', $ids)->orderBy('id')->cursorPaginate($porPagina, ['*'], 'cursor', $cursor ? Cursor::fromEncoded($cursor) : null)
            );
        }
    };
}

test('la paginación por cursor devuelve el envelope con meta y links sin totales', function () {
    $ids = User::factory()->count(3)->create()->modelKeys();

    $json = controllerConCursor()->pagina($ids, 2)->getData(true);

    expect($json['success'])->toBeTrue()
        ->and($json['data'])->toHaveCount(2)
        ->and($json['meta'])->toHaveKeys(['per_page', 'has_more', 'next_cursor', 'prev_cursor'])
        ->and($json['meta'])->not->toHaveKeys(['total', 'last_page'])
        ->and($json['meta']['has_more'])->toBeTrue()
        ->and($json['meta']['next_cursor'])->toBeString()
        ->and($json['links'])->toHaveKeys(['next', 'prev']);
});

test('el cursor devuelto lleva a la página siguiente y la última no tiene más', function () {
    $ids = User::factory()->count(3)->create()->modelKeys();
    $controller = controllerConCursor();

    $primera = $controller->pagina($ids, 2)->getData(true);
    $segunda = $controller->pagina($ids, 2, $primera['meta']['next_cursor'])->getData(true);

    expect($segunda['data'])->toHaveCount(1)
        ->and($segunda['meta']['has_more'])->toBeFalse()
        ->and($segunda['meta']['next_cursor'])->toBeNull()
        ->and($segunda['data'][0]['id'])->not->toBe($primera['data'][0]['id']);
});
