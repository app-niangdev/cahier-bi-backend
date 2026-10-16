<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Types d'abonnement (forfaits). Le prix et la durée ne changent jamais : pour un nouveau
        // tarif, l'administrateur désactive l'ancien forfait et en crée un autre.
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedSmallInteger('duration_months');
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('XOF');
            // Gérants autorisés dans chaque boutique du propriétaire ; null = illimité
            $table->unsignedSmallInteger('max_gerants_par_boutique')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Forfait choisi ; null pour l'essai et les abonnements saisis avant les forfaits.
        // Le nom et le montant restent recopiés dans l'abonnement (historique).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('subscription_plan_id')->nullable()->after('tenant_id')
                ->constrained('subscription_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_plan_id');
        });

        Schema::dropIfExists('subscription_plans');
    }
};
