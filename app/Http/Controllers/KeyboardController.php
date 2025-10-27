<?php

namespace App\Http\Controllers;

class KeyboardController extends Controller
{
    public static function start(): string
    {
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Entrar no Canal', 'url' => env('TELEGRAM_ADMIN_CHANNEL_INVITE_PRIVATE_LINK') ?? ''],
                ],
                [
                    ['text' => 'Iniciar Configuração', 'callback_data' => '/configure'],
                ],
            ]
        ]);
    }

    // ---

    public static function startConfig(): string
    {
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Iniciar Configuração', 'callback_data' => '/configure'],
                ],
            ],
        ]);
    }

    // ---

    public static function cancel(): string
    {
        return json_encode([
            'inline_keyboard' => [
                [['text' => 'Cancelar', 'callback_data' => '/cancel']],
            ]
        ]);
    }
    public static function startConfigListCommand(): string
    {
        return json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Iniciar Configuração', 'callback_data' => '/configure'],
                ],
                [
                    ['text' => 'Listar Comandos', 'callback_data' => '/commands'],
                ],
            ]
        ]);
    }
}
