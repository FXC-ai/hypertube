<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The criteria a catalog entry can be filtered and sorted on, so the listing is
     * narrowed inside every source query instead of after the sources have been merged.
     */
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->unsignedSmallInteger('release_year')->nullable()->after('title');
            $table->decimal('imdb_rating', 3, 1)->nullable()->after('release_year');
            // Lowercase genre labels, pipe delimited and wrapped in pipes: "|drama film|comedy film|".
            $table->text('genres')->nullable()->after('imdb_rating');

            $table->index('release_year');
            $table->index('imdb_rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['release_year', 'imdb_rating', 'genres']);
        });
    }
};
