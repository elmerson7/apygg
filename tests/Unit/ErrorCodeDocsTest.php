<?php

use App\Enums\ErrorCode;

test('cada case de ErrorCode está documentado en docs/api-response-structure.md', function () {
    $doc = file_get_contents(base_path('docs/api-response-structure.md'));

    foreach (ErrorCode::cases() as $case) {
        expect($doc)->toContain('`'.$case->value.'`');
    }
});
