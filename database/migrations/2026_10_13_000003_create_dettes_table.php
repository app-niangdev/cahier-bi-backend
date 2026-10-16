<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dettes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('reference', 30);
            $table->decimal('montant', 12, 2);
            // Recalculés à chaque paiement enregistré ou annulé
            $table->decimal('montant_paye', 12, 2)->default(0);
            $table->decimal('montant_restant', 12, 2);
            $table->date('date_creation');
            $table->date('date_echeance')->nullable();
            $table->string('statut', 20)->default('en_attente');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['boutique_id', 'reference']);
            $table->index(['boutique_id', 'statut']);
            $table->index(['client_id', 'statut']);
        });

        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dette_id')->constrained('dettes')->cascadeOnDelete();
            $table->foreignId('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            // Gérant ou propriétaire qui a encaissé
            $table->foreignId('enregistre_par')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('montant', 12, 2);
            $table->date('date_paiement');
            $table->string('methode', 20)->default('especes');
            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['boutique_id', 'date_paiement']);
        });

        Schema::create('rappels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dette_id')->constrained('dettes')->cascadeOnDelete();
            $table->foreignId('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $table->string('canal', 20)->default('whatsapp');
            $table->string('ton', 20)->default('normal');
            $table->string('statut', 20)->default('en_attente');
            $table->timestamp('envoye_le')->nullable();
            $table->text('message');
            $table->text('erreur')->nullable();
            $table->timestamps();

            $table->index(['dette_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rappels');
        Schema::dropIfExists('paiements');
        Schema::dropIfExists('dettes');
    }
};
