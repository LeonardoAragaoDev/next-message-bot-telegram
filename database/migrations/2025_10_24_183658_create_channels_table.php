<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();

            // O ID único do Telegram para o Canal. Usado como chave de busca principal.
            $table->string('channel_id')->unique();

            // Nome do Canal (title)
            $table->string('title')->nullable();

            // Username do Canal (se tiver)
            $table->string('username')->nullable();

            // Se for um canal privado, pode ser útil (embora o bot precise do ID e ser admin)
            $table->string('type')->default('channel');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
