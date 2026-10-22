<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const COLUMNS = [
        'movie_id',
        'imdb_id',
        'source',
        'release_date',
        'poster',
        'plot',
        'genres',
        'runtime',
        'actors',
        'director',
        'writers',
        'rated',
        'language',
        'awards',
        'imdbRating',
        'imdbVotes',
        'boxOffice',
        'created_at',
        'updated_at',
    ];

    public function up(): void
    {
        Schema::create('movie_data_rebuild', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->nullable()->constrained('movies')->nullOnDelete();
            $table->string('imdb_id');
            $table->string('source');
            $table->date('release_date')->nullable();
            $table->string('poster')->nullable();
            $table->text('plot')->nullable();
            $table->json('genres')->nullable();
            $table->integer('runtime')->nullable();
            $table->text('actors')->nullable();
            $table->text('director')->nullable();
            $table->text('writers')->nullable();
            $table->string('rated')->nullable();
            $table->json('language')->nullable();
            $table->json('awards')->nullable();
            $table->float('imdbRating')->nullable();
            $table->integer('imdbVotes')->nullable();
            $table->float('boxOffice')->nullable();
            $table->timestamps();

            $table->unique(['imdb_id', 'source']);
            $table->index('imdb_id');
        });

        DB::statement(sprintf(
            'INSERT INTO movie_data_rebuild (%s) SELECT %s FROM movie_data',
            implode(', ', self::COLUMNS),
            implode(', ', self::COLUMNS),
        ));

        Schema::drop('movie_data');
        Schema::rename('movie_data_rebuild', 'movie_data');
    }

    public function down(): void
    {
        Schema::create('movie_data_rebuild', function (Blueprint $table) {
            $table->foreignId('movie_id')->nullable()->constrained('movies')->nullOnDelete();
            $table->string('imdb_id');
            $table->string('source');
            $table->date('release_date')->nullable();
            $table->string('poster')->nullable();
            $table->text('plot')->nullable();
            $table->json('genres')->nullable();
            $table->integer('runtime')->nullable();
            $table->text('actors')->nullable();
            $table->text('director')->nullable();
            $table->text('writers')->nullable();
            $table->string('rated')->nullable();
            $table->json('language')->nullable();
            $table->json('awards')->nullable();
            $table->float('imdbRating')->nullable();
            $table->integer('imdbVotes')->nullable();
            $table->float('boxOffice')->nullable();
            $table->timestamps();

            $table->unique(['imdb_id', 'source']);
            $table->index('imdb_id');
        });

        DB::statement(sprintf(
            'INSERT INTO movie_data_rebuild (%s) SELECT %s FROM movie_data',
            implode(', ', self::COLUMNS),
            implode(', ', self::COLUMNS),
        ));

        Schema::drop('movie_data');
        Schema::rename('movie_data_rebuild', 'movie_data');
    }
};
