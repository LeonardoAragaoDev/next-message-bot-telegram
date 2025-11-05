<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserState;
use App\Services\KeyboardService;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class CommandController extends Controller
{

    // Telegram API
    protected Api $telegram;

    // Variáveis globais
    protected string $storageChannelId;
    protected string $adminChannelInviteLink;

    public function __construct(Api $telegram)
    {
        // Telegram API
        $this->telegram = $telegram;

        // Variáveis globais
        $this->storageChannelId = env('TELEGRAM_STORAGE_CHANNEL_ID') ?? '';
        $this->adminChannelId = env('TELEGRAM_ADMIN_CHANNEL_ID') ?? '';
        $this->adminChannelInviteLink = env('TELEGRAM_ADMIN_CHANNEL_INVITE_PRIVATE_LINK') ?? '';
    }

    /**
     * Delega comandos simples ao CommandController.
     * Retorna true se um comando simples (não-fluxo) foi tratado, false caso contrário.
     */
    public function delegateCommand(string $text, User $dbUser, $chatId): bool
    {
        $localUserId = $dbUser->id;
        $command = str_replace('/', '', explode(' ', $text)[0]);

        switch (strtolower($command)) {
            case 'start':
                $this->handleStartCommand($localUserId, $chatId, $dbUser);
                return true;
            case 'commands':
                $this->handleCommandsCommand($chatId);
                return true;
            case 'status':
                $this->handleStatusCommand($chatId);
                return true;
            case 'cancel':
                $this->handleCancelCommand($localUserId, $chatId);
                return true;
            case 'configure':
                // Deixa o /configure ser tratado pelo fluxo logo abaixo no handlePrivateChat
                return false;
            default:
                $this->handleUnknownCommand($chatId);
                return false;
        }
    }

    /**
     * Executa a lógica do comando /start.
     * Envia uma mensagem de boas-vindas e instruções básicas, incluindo o teclado inline.
     *
     * @param int|string $localUserId O ID local do usuário no DB.
     * @param int|string $chatId O ID do chat privado.
     * @param User $dbUser O modelo User do banco de dados.
     */
    public function handleStartCommand($localUserId, $chatId, User $dbUser): void
    {
        Log::info("handleStartCommand: Iniciando para userId: {$localUserId}");

        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => "🤖 *Olá, " . $dbUser->first_name . "! Eu sou o NextMessageBot.*\n\nEnvie o comando /configure para iniciar a automação no seu canal *(o máximo de canais permitidos para se configurar no momento é " . UserController::MAX_CHANNELS . ")*.\n\nPara conferir todos os comandos digite /commands\n\nE caso esteja configurando e queira cancelar a qualquer momento basta digitar /cancel.\n\n*Para usar o bot, você deve estar inscrito no nosso* [Canal Oficial]({$this->adminChannelInviteLink}).",
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardService::start(),
        ]);
    }

    /**
     * Executa a lógica do comando /commands.
     * Lista todos os comandos disponíveis para o usuário.
     *
     * @param int|string $chatId O ID do chat privado.
     */
    public function handleCommandsCommand($chatId): void
    {
        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => "⚙️ *Comandos*\n\n /start - Iniciar o bot\n /configure - Configurar o bot para um canal\n /status - Verificar status do bot\n /commands - Lista os comandos\n /cancel - Cancelar qualquer fluxo de configuração ativo",
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardService::startConfig()
        ]);
    }

    /**
     * Executa a lógica do comando /status.
     *
     * @param int|string $chatId O ID do chat privado.
     */
    public function handleStatusCommand($chatId): void
    {
        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => "✅ *O Bot tá on!*",
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardService::startConfigListCommand()
        ]);
    }

    /**
     * Executa a lógica do comando /cancel.
     * Limpa o estado do usuário e exclui a mensagem temporária no canal drive, se houver.
     *
     * @param int|string $localUserId O ID local do usuário no DB.
     * @param int|string $chatId O ID do chat privado.
     */
    public function handleCancelCommand($localUserId, $chatId): void
    {
        // Busca o estado do usuário
        $userState = UserState::where("user_id", $localUserId)->first();

        if ($userState && $userState->state !== "idle") {
            // Lógica de limpeza da mensagem temporária (no canal drive)
            $messageIdToClean = null;
            if ($userState->data) {
                $tempData = json_decode($userState->data, true);
                $messageIdToClean = $tempData["response_message_id"] ?? null;
            }

            if ($messageIdToClean) {
                try {
                    $this->telegram->deleteMessage([
                        'chat_id' => $this->storageChannelId,
                        'message_id' => $messageIdToClean,
                    ]);
                } catch (\Exception $e) {
                    Log::warning("Falha ao excluir mensagem temporária ({$messageIdToClean}) do canal drive durante o cancelamento: " . $e->getMessage());
                }
            }

            // Limpa o estado do usuário
            $userState->state = "idle";
            $userState->data = null;
            $userState->save();

            $successMessage = "❌ *Configuração cancelada.* Você pode iniciar uma nova configuração a qualquer momento com o comando /configure.";

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => $successMessage,
                "parse_mode" => "Markdown",
                "reply_markup" => KeyboardService::startConfig()
            ]);
        } else {
            $noActiveMessage = "❌ *Nenhuma configuração ativa para cancelar.*";

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => $noActiveMessage,
                "parse_mode" => "Markdown",
                "reply_markup" => KeyboardService::startConfig()
            ]);
        }
    }
    /**
     * Executa a lógica do comando /cancel.
     * Limpa o estado do usuário e exclui a mensagem temporária no canal drive, se houver.
     *
     * @param int|string $localUserId O ID local do usuário no DB.
     * @param int|string $chatId O ID do chat privado.
     */
    public function handleUnknownCommand($chatId): void
    {
        $this->telegram->sendMessage([
            "chat_id" => $chatId,
            "text" => "Comando não reconhecido. Use /configure para iniciar ou /commands para ver a lista.",
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardService::startConfigListCommand()
        ]);
    }
}
