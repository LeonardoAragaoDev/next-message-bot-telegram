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
        Schema::table('bot_configs', function (Blueprint $table) {
            // Nova coluna para armazenar em quantas mensagens a resposta deve ser enviada (Ex: 1, 5, 10)
            $table->unsignedSmallInteger('send_every_x_messages')
                ->default(1)
                ->after('is_reply');

            // Nova coluna para armazenar o contador atual de mensagens recebidas desde o último envio
            $table->unsignedSmallInteger('messages_received_count')
                ->default(0)
                ->after('send_every_x_messages');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_configs', function (Blueprint $table) {
            $table->dropColumn('messages_received_count');
            $table->dropColumn('send_every_x_messages');
        });
    }
};
