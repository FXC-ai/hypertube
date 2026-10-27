<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The popularity the listing is sorted on by default: archive.org reports a
     * download count for every item, which is where this catalog comes from.
     */
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('internet_archive_id')->nullable()->after('torrent_url');
            $table->unsignedInteger('popularity')->nullable()->after('internet_archive_id');

            $table->index('internet_archive_id');
            $table->index('popularity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['internet_archive_id', 'popularity']);
        });
    }
};
