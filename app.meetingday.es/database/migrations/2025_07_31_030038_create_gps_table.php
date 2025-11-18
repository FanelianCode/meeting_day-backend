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
        Schema::create('gps', function (Blueprint $table) {
            $table->integer('id_gps', true);
            $table->integer('id_evento');
            $table->string('location', 250);
            $table->string('place', 250);
            $table->string('fecha', 250);
            $table->string('hora', 250);
            $table->integer('marker')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gps');
    }
};
