<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Boutiques d'un propriétaire (tenant), créées uniquement par l'administrateur
        Schema::create('boutiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('nom', 150);
            $table->string('slug')->unique();
            $table->string('telephone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('adresse')->nullable();
            $table->string('quartier', 100)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('statut', 20)->default('actif');
            $table->timestamps();
            $table->softDeletes();
        });

        // Un gérant travaille dans une seule boutique ; null pour l'administrateur et le propriétaire
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('boutique_id')->nullable()->after('tenant_id')
                ->constrained('boutiques')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('boutique_id');
        });

        Schema::dropIfExists('boutiques');
    }
};
