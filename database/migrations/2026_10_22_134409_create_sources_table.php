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
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('imdb_id');
            $table->text('title');
            $table->json('titles')->nullable();
            $table->text('torrent_url')->nullable();
            $table->timestamps();

            $table->unique(['source', 'imdb_id']);
            // titles is JSON: PostgreSQL has no B-tree operator for it, and a search
            // with ILIKE '%...%' could not use such an index anyway.
            $table->index(['source', 'title']);
            $table->index('imdb_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
