<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the application returns a successful API response', function () {
    $response = $this->getJson('/');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'name',
            'status',
            'version',
            'endpoints',
        ])
        ->assertJson([
            'status' => 'running',
        ]);
});
