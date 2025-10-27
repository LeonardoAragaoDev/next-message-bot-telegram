<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserState;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Keyboard\Keyboard;
use App\Http\Controllers\KeyboardController;

class CommandController extends Controller
{

    protected Api $telegram;
    protected string $storageChannelId;
    protected string $adminChannelInviteLink;

    public function __construct(Api $telegram)
    {
        $this->telegram = $telegram;
        $this->adminChannelId = env('TELEGRAM_ADMIN_CHANNEL_ID') ?? '';
        $this->adminChannelInviteLink = env('TELEGRAM_ADMIN_CHANNEL_INVITE_PRIVATE_LINK') ?? '';
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
            "text" => "🤖 *Olá, " . $dbUser->first_name . "! Eu sou o NextMessageBot.*\n\nEnvie o comando /configure para iniciar a automação no seu canal, para conferir todos os comandos digite /commands e caso esteja configurando e queira cancelar a qualquer momento basta digitar /cancel.\n\nPara usar o bot, você deve estar inscrito no nosso [Canal Oficial]({$this->adminChannelInviteLink}).",
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardController::start(),
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
            "text" => "⚙️ *Comandos*\n\n /start - Iniciar o bot\n /configure - Configurar o bot para um canal\n /status - Verificar status do bot\n /cancel - Cancelar qualquer fluxo de configuração ativo",
            "parse_mode" => "Markdown",
            "reply_markup" => KeyboardController::startConfig()
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
            "reply_markup" => KeyboardController::startConfigListCommand()
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
                "reply_markup" => KeyboardController::startConfig()
            ]);
        } else {
            $noActiveMessage = "❌ *Nenhuma configuração ativa para cancelar.*";

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => $noActiveMessage,
                "parse_mode" => "Markdown",
                "reply_markup" => KeyboardController::startConfig()
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
            "reply_markup" => KeyboardController::startConfigListCommand()
        ]);
    }
}
