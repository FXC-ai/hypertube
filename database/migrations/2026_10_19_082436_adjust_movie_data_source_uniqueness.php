<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('movie_data', function (Blueprint $table) {
            $table->dropUnique('movie_data_imdb_id_movie_id_unique');
            $table->unique(['imdb_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('movie_data', function (Blueprint $table) {
            $table->dropUnique(['imdb_id', 'source']);
            $table->unique(['imdb_id', 'movie_id']);
        });
    }
};
