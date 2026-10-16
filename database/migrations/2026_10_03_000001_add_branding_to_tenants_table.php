<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('address')->nullable()->after('description');
            $table->string('email')->nullable()->after('address');
            $table->string('trade', 100)->nullable()->after('email');
            $table->string('ninea', 50)->nullable()->after('trade');
            $table->string('rccm', 50)->nullable()->after('ninea');

            // Charte graphique de l'application mobile
            $table->string('primary_color', 7)->default('#1D4ED8')->after('logo_url');
            $table->string('secondary_color', 7)->default('#0F172A')->after('primary_color');
            $table->string('accent_color', 7)->default('#F59E0B')->after('secondary_color');

            $table->dropColumn('color_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('color_code')->nullable();
            $table->dropColumn([
                'address', 'email', 'trade', 'ninea', 'rccm',
                'primary_color', 'secondary_color', 'accent_color',
            ]);
        });
    }
};
