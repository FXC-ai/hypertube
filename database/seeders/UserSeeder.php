<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::factory()->create([
            'username' => 'fxc-ai',
            'firstname' => 'François-Xavier',
            'lastname' => 'Condreau',
            'email' => 'nimportequoi@gmail.com',
            'password' => 'password',
        ]);

        User::factory(100)->create();
    }
}
