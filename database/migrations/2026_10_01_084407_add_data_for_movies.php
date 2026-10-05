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
            $table->string('imdb_id');
            $table->foreignId('movie_id')
                ->nullable()
                ->after('id')
                ->constrained('movies')
                ->nullOnDelete();
            $table->unique(['imdb_id', 'movie_id']);
            $table->string('source');
            $table->date('release_date');
            $table->timestamps();
        });

        Schema::table('movies', function (Blueprint $table) {
            $table->string('imdb_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->removeColumn("imdb_id");
        });

        Schema::dropIfExists('movie_data');
    }
};
