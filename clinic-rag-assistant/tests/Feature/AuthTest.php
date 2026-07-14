<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// SPA 認証

it('正しい資格情報でログインできる', function () {
    User::factory()->create([
        'email' => 'staff@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->postJson('/api/login', [
        'email' => 'staff@example.com',
        'password' => 'password',
    ])->assertOk()->assertJsonPath('email', 'staff@example.com');
});

it('誤った資格情報は 422 を返す', function () {
    User::factory()->create(['email' => 'staff@example.com']);

    $this->postJson('/api/login', [
        'email' => 'staff@example.com',
        'password' => 'wrong',
    ])->assertStatus(422);
});

it('未認証では /api/user にアクセスできない', function () {
    $this->getJson('/api/user')->assertStatus(401);
});
