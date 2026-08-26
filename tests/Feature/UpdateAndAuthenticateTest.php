<?php

use App\Models\User;

test(
    'update username and authenticate',
    function () {
        $user = User::factory()->create(
            [
                'username' => 'test',
                'password' => 'password'
            ]
        );

        $responseUpdate = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'username' => 'test2',
            ]);

        $responseUpdate
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        expect($user->username)->toBe('test2');

        $this->post(route('logout'))->assertRedirect(route('home'));


        $this->assertGuest();

        $response = $this->post(route('login.store'), [
            'username' => 'test2',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }
);
