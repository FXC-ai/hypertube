<?php

use App\Enums\DownloadStatus;
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
        Schema::table('movies', function (Blueprint $table) {
            $table->string('torrent_url')->nullable();
            $table->string('download_status')->default(DownloadStatus::Pending->value);
            $table->uuid('download_id')->nullable();
            $table->unsignedInteger('download_file_index')->nullable();
            $table->text('download_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropColumn([
                'torrent_url',
                'download_status',
                'download_id',
                'download_file_index',
                'download_error',
            ]);
        });
    }
};
