<?php

use App\Contracts\FileServiceInterface;
use App\Services\FileService;

// H8: no hay un segundo camino de subida que se salte validateFile (extensión del cliente, disco público).

test('el servicio de archivos no expone uploadImage: toda subida pasa por upload', function () {
    expect(method_exists(FileServiceInterface::class, 'uploadImage'))->toBeFalse()
        ->and(method_exists(FileService::class, 'uploadImage'))->toBeFalse();
});
