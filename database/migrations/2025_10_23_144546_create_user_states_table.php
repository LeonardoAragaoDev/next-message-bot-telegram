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
        Schema::create('user_states', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique() // Garante 1 estado por usuário local
                ->constrained() // Cria a restrição de chave estrangeira para a tabela 'users'
                ->onDelete('cascade'); // Opcional: Se o usuário for deletado, o estado também é

            $table->string('state'); // Ex: 'awaiting_channel_message', 'awaiting_response_message'
            $table->text('data')->nullable(); // Para armazenar temporariamente o channel_id
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_states');
    }
};
