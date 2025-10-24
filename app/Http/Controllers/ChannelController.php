<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Chat as TelegramChatObject;
use Illuminate\Support\Facades\Log;

class ChannelController extends Controller
{
    // A API do Telegram precisa ser injetada para fazer chamadas de permissão.
    protected Api $telegram;

    public function __construct(Api $telegram)
    {
        $this->telegram = $telegram;
    }

    /**
     * Cria ou atualiza um canal na base de dados com as informações do Telegram Chat.
     * * @param TelegramChatObject $telegramChat
     * @return Channel
     */
    public function saveOrUpdateTelegramChannel(TelegramChatObject $telegramChat): Channel
    {
        $channelId = (string) $telegramChat->getId();

        $data = [
            'channel_id' => $channelId,
            'title' => $telegramChat->getTitle(),
            'username' => $telegramChat->getUsername(),
            'type' => $telegramChat->getType(),
        ];

        // Busca o canal pelo ID do Telegram ou cria um novo registro
        $channel = Channel::updateOrCreate(
            ['channel_id' => $channelId],
            $data
        );

        Log::info("Canal Telegram ID: {$channelId} salvo/atualizado.");

        return $channel;
    }

    /**
     * Verifica se o bot é administrador do canal e tem permissão para postar/enviar.
     * @param string $channelId O ID do chat/canal.
     * @return array Retorna ['is_admin' => bool, 'can_post' => bool]
     */
    public function checkBotPermissions(string $channelId): array
    {
        try {
            $botUsername = $this->telegram->getMe()->getUsername();
            $administrators = $this->telegram->getChatAdministrators(['chat_id' => $channelId]);

            $botMember = null;
            $is_admin = false;
            $can_post = false;

            // Encontra o bot na lista de administradores
            foreach ($administrators as $member) {
                if ($member->getUser()->getUsername() === $botUsername) {
                    $botMember = $member;
                    $is_admin = true;
                    break;
                }
            }

            if ($botMember) {
                // 'can_post_messages' é a permissão mais crucial para posts de canal.
                // Usaremos 'can_post_messages' para verificar se ele pode enviar posts no canal.
                // Assumindo que você usará copyMessage, ele só precisa ser admin com essa permissão.
                // Em canais, 'can_post_messages' geralmente significa que ele pode criar novos posts.
                // Para reply/copia, 'can_delete_messages' pode ser útil, mas 'can_post_messages' é o mínimo.
                $can_post = $botMember->getCanPostMessages() === true;
            }

            return ['is_admin' => $is_admin, 'can_post' => $can_post];

        } catch (\Exception $e) {
            // Se o bot não for admin, getChatAdministrators falha com erro 400.
            // O bot deve ser admin para esta verificação funcionar.
            Log::error("Falha ao verificar permissões no canal {$channelId}: " . $e->getMessage());
            return ['is_admin' => false, 'can_post' => false];
        }
    }
}
