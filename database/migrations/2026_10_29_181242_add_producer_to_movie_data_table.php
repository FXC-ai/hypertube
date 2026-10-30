<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The subject asks for a casting holding at least the producer, the director and
     * the main cast: OMDb answers with a `Producer` field next to `Director`.
     */
    public function up(): void
    {
        Schema::table('movie_data', function (Blueprint $table) {
            $table->string('producer')->nullable()->after('director');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movie_data', function (Blueprint $table) {
            $table->dropColumn('producer');
        });
    }
};
