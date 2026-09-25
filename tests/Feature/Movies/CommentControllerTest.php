<?php

use App\Models\Comment;
use App\Models\Movie;
use App\Models\User;

describe('movie show page', function () {
    it('can display comments for a movie', function () {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        Comment::factory()->count(3)->create([
            'movie_id' => $movie->id,
            'user_id' => $user->id,
        ]);


        $this->actingAs($user);

        $response = $this->get(route('movies.show', $movie));

        $response->assertOk();
        $response->assertInertia(fn ($assert) => $assert
            ->component('movies/show')
            ->has('comments.data', 3)
            ->has('comments.data.0', fn ($assert) => $assert
                ->has('id')
                ->has('movie_id')
                ->has('user_id')
                ->has('content')
                ->has('created_at')
                ->has('user')
                ->has('can_delete')
            )
        );
    });

    it('can store a new comment', function () {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actingAs($user);

        $response = $this->post(route('movies.comments.store', $movie), [
            'content' => 'This is a test comment',
        ]);

        $response->assertRedirect();
        expect($movie->fresh()->comments)->toHaveCount(1);
        expect($movie->fresh()->comments[0]->content)->toBe('This is a test comment');
        expect($movie->fresh()->comments[0]->user_id)->toBe($user->id);
    });

    it('cannot store a comment without content', function () {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();

        $this->actingAs($user);

        $response = $this->post(route('movies.comments.store', $movie), [
            'content' => '',
        ]);

        $response->assertSessionHasErrors('content');
    });

    it('can delete own comment', function () {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        $comment = Comment::factory()->create([
            'movie_id' => $movie->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user);

        $response = $this->delete(route('comments.destroy', [
            'comment' => $comment,
        ]));

        $response->assertRedirect();
        expect(Comment::find($comment->id))->toBeNull();
    });

    it('cannot delete others comment', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $movie = Movie::factory()->create();
        $comment = Comment::factory()->create([
            'movie_id' => $movie->id,
            'user_id' => $other->id,
        ]);

        $this->actingAs($user);

        $response = $this->delete(route('comments.destroy', [
            'comment' => $comment,
        ]));

        $response->assertForbidden();
        expect(Comment::find($comment->id))->not->toBeNull();
    });

    it('set can_delete to true when the comment is owned by the user', function () {
        $user = User::factory()->create();
        $movie = Movie::factory()->create();
        $comment = Comment::factory()->create([
            'movie_id' => $movie->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('movies.show', $movie));

        $response->assertInertia(fn ($assert) => $assert
            ->component('movies/show')
            ->where('comments.data.0.can_delete', true)
        );
    });

    it('set can_delete to false when the comment isn\'t owned by the user', function () {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $movie = Movie::factory()->create();
        $comment = Comment::factory()->create([
            'movie_id' => $movie->id,
            'user_id' => $other->id,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('movies.show', $movie));

        $response->assertInertia(fn ($assert) => $assert
            ->component('movies/show')
            ->where('comments.data.0.can_delete', false)
        );
    });
});
