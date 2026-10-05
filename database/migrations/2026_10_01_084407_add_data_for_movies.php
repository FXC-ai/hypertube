<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('movie_data', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->date('release_date');
            $table->string('imdb_id');
            $table->index('imdb_id', "imdb_id_hash", 'hash');
            $table->timestamps();
        });

        Schema::table('movies', function (Blueprint $table) {
            $table->removeColumn("release_date");
            $table->string('imdb_id');
            $table->index('imdb_id', "imdb_id_hash", 'hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->removeColumn("release_date");
            $table->removeColumn("imdb_id");
        });

        Schema::dropIfExists('movie_data');
    }
};
