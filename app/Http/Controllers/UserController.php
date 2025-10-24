<?php

namespace App\Http\Controllers;

use App\Models\User;
use Telegram\Bot\Objects\User as TelegramUserObject;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
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
}
