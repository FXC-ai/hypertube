<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Ajouter les colonnes "Social Auth" à la table users existante
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('provider_token')->nullable();
            $table->string('provider_refresh_token')->nullable();

            $table->string('firstname')->nullable()->change();
            $table->string('lastname')->nullable()->change();

            // Modifier la colonne password pour la rendre optionnelle (Nullable)
            $table->string('password')->nullable()->change();

            // S'assurer qu'un compte Github = Un seul utilisateur chez nous
            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_id']);

            $table->string('firstname')->nullable(false)->change();
            $table->string('lastname')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();

            $table->dropColumn([
                'provider',
                'provider_id',
                'provider_token',
                'provider_refresh_token',
            ]);
        });
    }
};
