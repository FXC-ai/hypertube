<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * The API is reached through one confidential password grant client whose id and secret live in
 * .env. `migrate:fresh` empties oauth_clients but leaves .env alone, after which every token
 * request fails with invalid_client: this command puts back the client .env names, or creates
 * one and writes it to .env when .env names none.
 */
#[Signature('api:client')]
#[Description('Make sure the OAuth2 client of the API exists and matches PASSPORT_API_CLIENT_* in .env')]
final class EnsureApiClient extends Command
{
    private const NAME = 'Hypertube API';

    public function handle(ClientRepository $clients): int
    {
        $id = config('passport.api_client.id');
        $secret = config('passport.api_client.secret');

        if (filled($id) && filled($secret)) {
            if (Passport::client()->newQuery()->whereKey($id)->exists()) {
                $this->components->info("The API client {$id} already exists.");

                return self::SUCCESS;
            }

            // Same id and secret as .env, so whoever already holds them keeps working.
            Passport::client()->forceFill([
                'id' => $id,
                'name' => self::NAME,
                'secret' => $secret,
                'provider' => 'users',
                'redirect_uris' => [],
                'grant_types' => ['password', 'refresh_token'],
                'revoked' => false,
            ])->save();

            $this->components->info("The API client {$id} was missing from the database: recreated.");

            return self::SUCCESS;
        }

        $client = $clients->createPasswordGrantClient(self::NAME, provider: 'users', confidential: true);
        $this->writeToEnvironmentFile((string) $client->id, (string) $client->plainSecret);
        $this->components->info("Created the API client {$client->id} and wrote it to .env.");

        return self::SUCCESS;
    }

    /**
     * Replaces any PASSPORT_API_CLIENT_* line. The file is rewritten in place rather than
     * replaced, since Docker bind mounts follow the original file.
     */
    private function writeToEnvironmentFile(string $id, string $secret): void
    {
        $path = $this->laravel->environmentFilePath();
        $lines = file_exists($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $lines = array_filter(
            $lines,
            fn (string $line): bool => preg_match('/^PASSPORT_API_CLIENT_(ID|SECRET)=/', $line) !== 1,
        );

        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        $lines[] = '';
        $lines[] = "PASSPORT_API_CLIENT_ID={$id}";
        $lines[] = "PASSPORT_API_CLIENT_SECRET={$secret}";

        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
    }
}
