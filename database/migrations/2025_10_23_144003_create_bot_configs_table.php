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
            $table->string('channel_id')->unique()->comment('ID do canal a ser monitorado (destino).');
            $table->bigInteger('user_id')->nullable()->comment('ID do usuário que configurou o bot.');
            $table->bigInteger('response_message_id')->comment('ID da mensagem no canal de armazenamento que será encaminhada.');
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
