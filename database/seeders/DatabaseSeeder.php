<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        User::factory()->create([
            'username' => 'bsolo',
            'firstname' => 'Ben',
            'lastname' => 'Solo',
            'email' => 'ben.solo@starwars.com',
            'password' => 'poirepoire',
        ]);

        $this->call([MovieSeeder::class, UserSeeder::class]);
    }
}
