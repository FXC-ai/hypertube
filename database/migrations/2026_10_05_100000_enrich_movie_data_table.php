<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movie_data', function (Blueprint $table) {
            $table->string('poster')->nullable()->after('imdb_id');
            $table->text('plot')->nullable()->after('poster');
            $table->json('genres')->nullable()->after('plot');
            $table->integer('runtime')->nullable()->after('genres');
            $table->text('actors')->nullable()->after('runtime');
            $table->text('director')->nullable()->after('actors');
            $table->text('writers')->nullable()->after('director');
            $table->string('rated')->nullable()->after('writers');
            $table->json('language')->nullable()->after('rated');
            $table->json('awards')->nullable()->after('language');
            $table->float('imdbRating')->nullable()->after('awards');
            $table->integer('imdbVotes')->nullable()->after('imdbRating');
            $table->float('boxOffice')->nullable()->after('imdbRating');

            $table->index('imdb_id');
        });
    }

    public function down(): void
    {
        Schema::table('movie_data', function (Blueprint $table) {
            $table->dropColumn([
                'poster', 'plot', 'genres', 'runtime',
                'actors', 'director', 'writers', 'rated', 'language', 'awards',
                'imdbRating', 'imdbVotes', 'boxOffice'
            ]);
        });
    }
};
