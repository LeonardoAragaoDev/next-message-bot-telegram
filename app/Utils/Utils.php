<?php

namespace App\Utils;

use Illuminate\Support\Facades\App;

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
}
