<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

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

        // migrate:fresh just emptied oauth_clients: put back the API client .env still names.
        if (filled(config('passport.api_client.id'))) {
            Artisan::call('api:client');
        }
    }
}
