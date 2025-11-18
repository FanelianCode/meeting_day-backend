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
        Schema::create('eventos', function (Blueprint $table) {
            $table->integer('id_evento', true);
            $table->integer('id_user');
            $table->integer('estado');
            $table->string('titulo', 250);
            $table->longText('descripcion');
            $table->integer('tipo');
            $table->integer('meeting');
            $table->integer('confirm')->default(0);
            $table->string('flimit', 200);
            $table->string('hlimit', 250)->nullable();
            $table->string('time_zone', 200)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eventos');
    }
};
