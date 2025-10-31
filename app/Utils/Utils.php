<?php

namespace App\Utils;

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\Update;

class Utils
{
  /**
   * Verifica se o ambiente é o de Produção.
   * Retorna true se APP_ENV for "production".
   * @return bool
   */
  public static function isProduction(): bool
  {
    return App::isProduction();
  }

  /**
   * Verifica se o ambiente é o de Desenvolvimento (padrão local).
   * Retorna true se APP_ENV for "local".
   * @return bool
   */
  public static function isLocal(): bool
  {
    return App::isLocal();
  }

  /**
   * Verifica se o ambiente é de Desenvolvimento (local ou developer).
   * Útil para englobar todos os ambientes que não são de produção/staging.
   * @return bool
   */
  public static function isDevelopment(): bool
  {
    return App::isProduction() === false;
  }

  /**
   * Extrai o objeto Message ou ChannelPost da atualização.
   */
  public static function getMessageFromUpdate(Update $update)
  {
    if ($update->getMessage()) {
      return $update->getMessage();
    }
    if ($update->getChannelPost()) {
      return $update->getChannelPost();
    }
    return null;
  }

  /**
   * Resolve o usuário do banco de dados a partir do Update,
   * garantindo que o objeto retornado seja Telegram\Bot\Objects\User.
   */
  public static function resolveDbUserFromUpdate(Update $update)
  {
    $user = null;

    if ($callbackQuery = $update->getCallbackQuery()) {
      $user = $callbackQuery->getFrom();
    } elseif ($message = $update->getMessage()) {
      $user = $message->getFrom();
    }

    if ($user) {
      $userController = app(UserController::class);

      Log::info("User info from update (ID): " . $user->getId());

      if ($user->getIsBot()) {
        Log::warning("resolveDbUserFromUpdate: Ignorando usuário bot ID: " . $user->getId());
        return null;
      }

      return $userController->saveOrUpdateTelegramUser($user);
    }

    return null;
  }
}
