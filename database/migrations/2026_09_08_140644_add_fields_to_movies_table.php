<?php

use App\Enums\ConversionStatus;
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
            $table->string('conversion_status')->default(ConversionStatus::Pending->value);
            $table->uuid('conversion_attempt')->nullable();
            $table->text('conversion_error')->nullable();
            $table->timestamp('conversion_started_at')->nullable();
            $table->timestamp('conversion_playable_at')->nullable();
            $table->timestamp('conversion_completed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            $table->dropColumn([
                'conversion_status',
                'conversion_attempt',
                'conversion_error',
                'conversion_started_at',
                'conversion_playable_at',
                'conversion_completed_at',
            ]);
        });
    }
};
