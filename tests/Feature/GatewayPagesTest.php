<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('app.url', 'https://proxy.example.test');
});

it('serves the one-screen homepage through Inertia', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('Home')
        ->assertSee('GitVerse Gateway');
});

it('serves the Vue page component for Inertia navigation', function (): void {
    $this->get('/', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->assertOk()
        ->assertJsonPath('component', 'Home');
});

it('keeps the connect screen private without a pending OAuth request', function (): void {
    $this->get('/connect')->assertForbidden();
});
