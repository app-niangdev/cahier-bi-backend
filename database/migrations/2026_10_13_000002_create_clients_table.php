<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained('boutiques')->cascadeOnDelete();
            $table->string('nom', 150);
            $table->string('telephone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('adresse')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['boutique_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
