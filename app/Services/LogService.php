<?php

namespace App\Services;

use App\Utils\Utils;
use Illuminate\Support\Facades\Log;
use Psr\Log\LogLevel;

class LogService
{
  /**
   * Registra uma mensagem no canal de log padrão (laravel.log), opcionalmente
   * restrita a ambientes de desenvolvimento.
   *
   * @param string $message A mensagem a ser logada.
   * @param array $context Dados de contexto a serem incluídos no log.
   * @param string $level O nível do log (info por padrão vindo de Psr\Log\LogLevel).
   * @param bool $devOnly Se true, só registra o log em ambientes 'local' ou 'developer'.
   * @return void
   */
  public static function inserir(
    string $message,
    array $context = [],
    string $level = LogLevel::INFO,
    bool $devOnly = true
  ): void {
    if ($devOnly && !Utils::isDevelopment()) {
      return;
    }

    Log::log($level, $message, $context);
  }
}
