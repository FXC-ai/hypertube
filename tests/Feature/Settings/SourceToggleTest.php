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
                ->where('sources.1.value', 'yts')
                ->where('sources.1.enabled', false)
                ->where('sources.1.legal', false)
        );
    });

    it('persists the toggles for the user', function () {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->patch(route('sources.update'), [
            'sources' => ['wikidata' => true, 'yts' => true],
        ]);

        $response->assertRedirect(route('sources.edit'));
        expect($user->fresh()->sources)->toBe(['wikidata' => true, 'yts' => true]);
    });

    it('refuses an unknown source key', function () {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->patch(route('sources.update'), [
            'sources' => ['wikidata' => true, 'yts' => true, 'omdb' => true],
        ]);

        $response->assertSessionHasErrors('sources');
    });

    it('uses the default preferences for a user without stored preferences', function () {
        $user = User::factory()->create(['sources' => null]);

        expect($user->enabledSources())->toHaveCount(1);
        expect($user->enabledSources()[0]->value)->toBe('wikidata');
    });
});
