<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->string('source')->default('wikidata')->after('title');
            $table->string('source_reference')->nullable()->after('source');
            $table->integer('seeds')->nullable()->after('torrent_url');
            $table->integer('peers')->nullable()->after('seeds');
            $table->string('torrent_quality')->nullable()->after('peers');
            $table->integer('torrent_size_bytes')->nullable()->after('torrent_quality');
        });

        Schema::table('movies', function (Blueprint $table) {
            $table->dropUnique('movies_imdb_id_unique');
            $table->unique(['imdb_id', 'source']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropUnique(['imdb_id', 'source']);
            $table->dropIndex(['source']);
            $table->unique('imdb_id');
            $table->dropColumn(['source', 'source_reference', 'seeds', 'peers', 'torrent_quality', 'torrent_size_bytes']);
        });
    }
};
