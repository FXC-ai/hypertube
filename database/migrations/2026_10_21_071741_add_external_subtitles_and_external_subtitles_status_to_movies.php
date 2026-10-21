<?php

use App\Enums\ExternalSubtitleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $table->json('external_subtitles')->nullable();
            $table->string('external_subtitles_status')->default(ExternalSubtitleStatus::None->value);
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $table->dropColumn([
                'external_subtitles',
                'external_subtitles_status',
            ]);
        });
    }
};
