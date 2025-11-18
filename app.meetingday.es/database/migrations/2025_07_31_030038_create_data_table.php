<?php

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
        Schema::create('data', function (Blueprint $table) {
            $table->integer('id_data', true);
            $table->string('nombre', 250);
            $table->string('apellido', 250);
            $table->string('nick', 250);
            $table->longText('img_profile');
            $table->integer('method');
            $table->string('indicativo', 250)->nullable();
            $table->string('number', 250);
            $table->string('mail', 250);
            $table->longText('token_movil');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data');
    }
};
