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
        Schema::create('bot_configs', function (Blueprint $table) {
            $table->id();
            // Armazena o ID do canal (o ID negativo do chat/canal)
            $table->string('channel_id')->unique()->comment('ID do canal a ser monitorado, obtido via encaminhamento.');
            // Armazena o ID do usuário que configurou (para gerenciamento)
            $table->bigInteger('user_id')->nullable()->comment('ID do usuário que configurou o bot.');
            // A mensagem que o bot irá enviar
            $table->text('response_message')->comment('Mensagem a ser enviada em resposta a novas publicações.');
            $table->boolean('is_reply')->default(true)->comment('Define se o bot deve responder à postagem (true) ou enviar uma mensagem nova (false).');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_configs');
    }
};
