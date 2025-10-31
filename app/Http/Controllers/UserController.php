<?php

namespace App\Http\Controllers;

use App\Models\BotConfig;
use App\Models\User;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\User as TelegramUserObject;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * Limite máximo de canais que um usuário pode configurar.
     */
    public const MAX_CHANNELS = 5;
    protected Api $telegram;

    public function __construct(Api $telegram)
    {
        $this->telegram = $telegram;
    }

    /**
     * Cria ou atualiza um usuário na base de dados com as informações do Telegram.
     * * @param TelegramUserObject $telegramUser
     * @return User
     */
    public function saveOrUpdateTelegramUser(TelegramUserObject $telegramUser): User
    {
        $telegramId = $telegramUser->getId();

        $data = [
            'telegram_user_id' => $telegramId,
            'first_name' => $telegramUser->getFirstName(),
            'last_name' => $telegramUser->getLastName(),
            'username' => $telegramUser->getUsername(),
            'language_code' => $telegramUser->getLanguageCode(),
            'name' => trim($telegramUser->getFirstName() . ' ' . $telegramUser->getLastName()),
        ];

        // 1. Usa o updateOrCreate para buscar ou criar o usuário
        $user = User::updateOrCreate(
            ['telegram_user_id' => $telegramId],
            $data
        );

        // 2. Toca no timestamp (updated_at) e salva novamente. 
        // Isso garante que o updated_at seja atualizado a cada interação.
        // O método 'touch()' apenas atualiza os timestamps.
        if ($user->wasRecentlyCreated === false) {
            $user->touch(); // Atualiza apenas o updated_at
            $user->save();
        }

        Log::info("Usuário Telegram ID: {$telegramId} salvo/atualizado.");

        return $user;
    }

    /**
     * Verifica se o usuário atingiu o limite máximo de canais configurados.
     *
     * @param int $localUserId O ID do usuário no banco de dados local.
     * @return bool Retorna true se o limite for atingido (>= MAX_CHANNELS), false caso contrário.
     */
    public function hasMaxChannelsConfigured(int $localUserId, int $chatId): bool
    {
        $retorno = false;
        // Conta quantas configurações existem para este user_id
        $count = BotConfig::where('user_id', $localUserId)->count();
        if ($count >= self::MAX_CHANNELS) {
            $retorno = true;
        }

        Log::debug("Contagem de canais configurados para o usuário {$localUserId}: {$count}");

        if ($retorno) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "🔒 *Máximo de canais configurados!* \n\nNo momento o máximo de canais que você pode configurar é *" . self::MAX_CHANNELS . "*.",
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);
        }

        return $retorno;
    }
}
