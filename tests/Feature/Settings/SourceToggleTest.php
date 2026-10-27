<?php

use App\Models\User;

describe('source preferences', function () {
    it('shows the catalog sources with their state', function () {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->get(route('sources.edit'));

        $response->assertOk();
        $response->assertInertia(
            fn($assert) => $assert
                ->component('settings/sources')
                ->has('sources', 2)
                ->where('sources.0.value', 'wikidata')
                ->where('sources.0.enabled', true)
                ->where('sources.0.legal', true)
                ->where('sources.1.value', 'whitelist')
                ->where('sources.1.enabled', true)
                ->where('sources.1.legal', true)
        );
    });

    it('persists the toggles for the user', function () {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->patch(route('sources.update'), [
            'sources' => ['wikidata' => true, 'whitelist' => false],
        ]);

        $response->assertRedirect(route('sources.edit'));
        expect($user->fresh()->sources)->toBe(['wikidata' => true, 'whitelist' => false]);
    });

    it('refuses an unknown source key', function () {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->patch(route('sources.update'), [
            'sources' => ['wikidata' => true, 'omdb' => true],
        ]);

        $response->assertSessionHasErrors('sources');
        expect($user->fresh()->sources)->toBe(['wikidata' => true, 'whitelist' => true]);
    });

    it('uses the default preferences for a user without stored preferences', function () {
        $user = User::factory()->create(['sources' => null]);

        expect(array_map(fn($source): string => $source->value, $user->enabledSources()))
            ->toBe(['wikidata', 'whitelist']);
    });

    it('skips the sources the server disabled', function () {
        $user = User::factory()->create(['sources' => ['wikidata' => true, 'whitelist' => true]]);

        config(['services.sources.whitelist.enabled' => false]);

        expect(array_map(fn($source): string => $source->value, $user->enabledSources()))
            ->toBe(['wikidata']);
    });

    it('skips the sources the user disabled', function () {
        $user = User::factory()->create(['sources' => ['wikidata' => false, 'whitelist' => true]]);

        expect(array_map(fn($source): string => $source->value, $user->enabledSources()))
            ->toBe(['whitelist']);
    });
});
