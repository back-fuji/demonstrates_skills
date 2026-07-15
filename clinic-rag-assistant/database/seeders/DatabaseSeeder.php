<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 管理者(文書管理が可能)
        User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => '管理者',
                'password' => Hash::make('password'),
                'role' => User::ROLE_ADMIN,
            ],
        );

        // 一般スタッフ
        User::query()->firstOrCreate(
            ['email' => 'staff@example.com'],
            [
                'name' => 'スタッフ',
                'password' => Hash::make('password'),
                'role' => User::ROLE_STAFF,
            ],
        );

        // ダミー文書コーパスの取り込み
        $this->call(CorpusSeeder::class);
    }
}
