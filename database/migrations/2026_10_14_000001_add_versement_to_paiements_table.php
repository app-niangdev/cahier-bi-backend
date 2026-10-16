<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un versement du client (« Modou a payé 7 000 ») peut être réparti sur plusieurs dettes,
        // de la plus ancienne à la plus récente : une ligne de paiement par dette, même versement.
        Schema::table('paiements', function (Blueprint $table) {
            $table->uuid('versement')->nullable()->after('enregistre_par')->index();
            $table->foreignId('client_id')->nullable()->after('boutique_id')->constrained('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('versement');
        });
    }
};
