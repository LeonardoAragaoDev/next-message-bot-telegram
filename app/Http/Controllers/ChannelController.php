<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use Telegram\Bot\Objects\Chat as TelegramChatObject;
use Illuminate\Support\Facades\Log;

class ChannelController extends Controller
{
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
}
