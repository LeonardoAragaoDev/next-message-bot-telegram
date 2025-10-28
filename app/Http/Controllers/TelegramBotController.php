<?php

namespace App\Http\Controllers;

use App\Models\BotConfig;
use App\Models\Channel;
use App\Models\User;
use App\Models\UserState;
use App\Services\KeyboardService;
use App\Services\LogService;
use Psr\Log\LogLevel;
use Telegram\Bot\Api;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Objects\Update;
use Illuminate\Http\Request;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\CommandController;

class TelegramBotController extends Controller
{
    // Telegram API
    protected Api $telegram;

    // Controllers
    protected CommandController $commandController;
    protected UserController $userController;
    protected ChannelController $channelController;

    // Variáveis globais
    protected string $storageChannelId;
    protected string $adminChannelId;
    protected string $adminChannelInviteLink;

    /**
     * Construtor para injeção de dependências.
     */
    public function __construct(
        Api $telegram,
        CommandController $commandController,
        UserController $userController,
        ChannelController $channelController,
    ) {
        // Telegram API
        $this->telegram = $telegram;

        // Controllers
        $this->commandController = $commandController;
        $this->userController = $userController;
        $this->channelController = $channelController;

        // IDs e links de canais obtidos das variáveis de ambiente
        $this->storageChannelId = env('TELEGRAM_STORAGE_CHANNEL_ID') ?? '';
        $this->adminChannelId = env('TELEGRAM_ADMIN_CHANNEL_ID') ?? '';
        $this->adminChannelInviteLink = env('TELEGRAM_ADMIN_CHANNEL_INVITE_PRIVATE_LINK') ?? '';
    }

    /**
     * Extrai o objeto Message ou ChannelPost da atualização.
     */
    private function getMessageFromUpdate(Update $update)
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
    private function resolveDbUserFromUpdate(Update $update)
    {
        $user = null;

        if ($callbackQuery = $update->getCallbackQuery()) {
            $user = $callbackQuery->getFrom();
        } elseif ($message = $update->getMessage()) {
            $user = $message->getFrom();
        }

        if ($user) {
            // Logs de fluxo principal devem rodar em produção (devOnly = false)
            LogService::inserir("User info from update (ID): " . $user->getId(), [], LogLevel::INFO, false);

            if ($user->getIsBot()) {
                LogService::inserir("resolveDbUserFromUpdate: Ignorando usuário bot ID: " . $user->getId(), [], LogLevel::WARNING, false);
                return null;
            }

            return $this->userController->saveOrUpdateTelegramUser($user);
        }

        return null;
    }

    /**
     * Ponto de entrada do Webhook. Direciona a atualização e trata exceções.
     */
    public function handleWebhook(Request $request)
    {
        // Logs de início e corpo da requisição
        LogService::inserir("--- NOVO WEBHOOK RECEBIDO ---", [], LogLevel::INFO, false);
        // Logs verbosos como o corpo da requisição são mantidos dev-only (usando o default devOnly=true)
        LogService::inserir("Corpo da requisição:", $request->all(), LogLevel::DEBUG, true);

        try {
            $update = $this->telegram->getWebhookUpdate();

            if ($update->getCallbackQuery()) {
                $this->handleCallbackQuery($update);
                return response("OK", 200);
            }

            $message = $this->getMessageFromUpdate($update);

            if (!$message) {
                LogService::inserir("handleWebhook: Atualização ignorada (sem mensagem/postagem processável).", [], LogLevel::INFO, false);
                return response("OK", 200);
            }

            $chatIdFromMessage = (string) $message->getChat()->getId();

            if ($chatIdFromMessage != $this->storageChannelId) {
                $chatType = $message->getChat()->getType();
                LogService::inserir("Tipo de Chat: {$chatType}", [], LogLevel::INFO, false);

                if ($chatType === "private") {
                    $this->handlePrivateChat($update);
                } elseif ($chatType === "channel") {
                    $this->channelController->handleChannelUpdate($update, $message);
                }
            } else {
                LogService::inserir("handleWebhook: Atualização ignorada (veio do canal de armazenamento).", [], LogLevel::INFO, false);
            }

        } catch (\Exception $e) {
            // Erro CRÍTICO deve ser logado em produção (devOnly = false)
            LogService::inserir(
                "ERRO CRÍTICO NO WEBHOOK: " . $e->getMessage(),
                ['exception' => $e->getMessage()],
                LogLevel::ERROR,
                false
            );
        }

        return response("OK", 200);
    }

    /**
     * Delega comandos simples ao CommandController.
     * Retorna true se um comando simples (não-fluxo) foi tratado, false caso contrário.
     */
    protected function delegateCommand(string $text, User $dbUser, $chatId): bool
    {
        $localUserId = $dbUser->id;
        $command = str_replace('/', '', explode(' ', $text)[0]);

        switch (strtolower($command)) {
            case 'start':
                $this->commandController->handleStartCommand($localUserId, $chatId, $dbUser);
                return true;
            case 'commands':
                $this->commandController->handleCommandsCommand($chatId);
                return true;
            case 'status':
                $this->commandController->handleStatusCommand($chatId);
                return true;
            case 'cancel':
                $this->commandController->handleCancelCommand($localUserId, $chatId);
                return true;
            case 'configure':
                // Deixa o /configure ser tratado pelo fluxo logo abaixo no handlePrivateChat
                return false;
            default:
                return false;
        }
    }

    /**
     * Gerencia a resposta aos botões inline (Etapa 3 do fluxo e comandos de callback).
     */
    protected function handleCallbackQuery(Update $update)
    {
        $callbackQuery = $update->getCallbackQuery();
        $callbackData = $callbackQuery->getData();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();

        // Resolve o usuário do DB (garantindo consistência com o handlePrivateChat)
        $dbUser = $this->resolveDbUserFromUpdate($update);
        if (!$dbUser) {
            return; // Ignora se não conseguir identificar o usuário
        }
        $localUserId = $dbUser->id;

        // 1. Envia uma notificação temporária para o usuário
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text' => 'Processando sua escolha...',
            'show_alert' => false
        ]);

        // --- Lógica de Comando /configure (Início via botão) ---
        if ($callbackData === '/configure') {
            $userState = UserState::firstOrCreate(
                ["user_id" => $localUserId],
                ["state" => "idle", "data" => null]
            );

            // Transição de estado para o início do fluxo de configuração
            $userState->state = "awaiting_channel_message";
            $userState->data = null;
            $userState->save();

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🛠️ *Etapa 1:* Para configurar, *encaminhe uma mensagem recente* do canal que você deseja automatizar. O bot precisa ser Admin nesse canal.",
                "parse_mode" => "Markdown",
                "reply_markup" => KeyboardService::cancel()
            ]);

            return;
        }

        // --- Lógica de Cancelamento (Comando /cancel via botão inline) ---
        if ($callbackData === '/cancel') {
            $this->commandController->handleCancelCommand($localUserId, $chatId);
        }

        // 2. Verifica se a callback é sobre o modo de resposta (Etapa 3)
        if (strpos($callbackData, 'set_reply_mode_') === 0) {
            $userState = UserState::where("user_id", $localUserId)->first();

            // Apenas permite se o estado for o esperado (awaiting_reply_mode)
            if (!$userState || $userState->state !== "awaiting_reply_mode") {
                // Edita a mensagem para remover os botões e informar o erro
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "❌ Ação expirada ou inválida. Por favor, comece o fluxo com /configure.",
                    'parse_mode' => 'Markdown',
                    'reply_markup' => Keyboard::remove()
                ]);
                return;
            }

            $tempData = json_decode($userState->data, true);
            $channelId = $tempData["channel_id"];
            $dbChannel = Channel::where('channel_id', $channelId)->first();
            $channelName = $dbChannel ? $dbChannel->title : "Canal Desconhecido";
            $responseMessageId = $tempData["response_message_id"];

            // Determina a preferência baseada no callback data
            $mode = str_replace('set_reply_mode_', '', $callbackData);
            $isReply = ($mode === 'reply');

            // --- Lógica de EXCLUSÃO DA MENSAGEM ANTERIOR (Configuração Antiga) ---
            $oldConfig = BotConfig::where("channel_id", $channelId)->first();
            if ($oldConfig && $oldConfig->response_message_id) {
                $oldMessageId = $oldConfig->response_message_id;
                try {
                    $this->telegram->deleteMessage([
                        'chat_id' => $this->storageChannelId,
                        'message_id' => $oldMessageId,
                    ]);
                    LogService::inserir("Mensagem anterior ID: {$oldMessageId} excluída do canal drive.", [], LogLevel::INFO, false);
                } catch (\Exception $e) {
                    LogService::inserir("Falha ao excluir mensagem antiga ({$oldMessageId}) do canal drive: " . $e->getMessage(), [], LogLevel::WARNING, false);
                }
            }
            // --- Fim da Lógica de EXCLUSÃO ---

            // Salva a configuração FINAL no BotConfig
            BotConfig::updateOrCreate(
                ["channel_id" => $channelId],
                [
                    "user_id" => $localUserId, // ID Local do DB
                    "response_message_id" => $responseMessageId,
                    "is_reply" => $isReply,
                ]
            );

            // Limpa o estado
            $userState->state = "idle";
            $userState->data = null;
            $userState->save();

            // Mensagem Final de Sucesso (Editando a mensagem original e removendo os botões)
            $replyModeText = $isReply ? "Resposta " : "Nova Mensagem";

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                "text" => "🎉 *Configuração Concluída!* O bot está ativo no canal *{$channelName}* (`{$channelId}`).\n\n ✅ Modo de Envio: *{$replyModeText}*",
                "parse_mode" => "Markdown",
                'reply_markup' => KeyboardService::startConfig()
            ]);

            return;
        }

        // Se for uma callback não mapeada (exceto as tratadas acima)
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text' => 'Ação não reconhecida.',
            'show_alert' => false
        ]);
    }

    /**
     * Gerencia o fluxo de configuração em chat privado.
     */
    protected function handlePrivateChat(Update $update)
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $telegramUser = $message->getFrom();
        $telegramUserId = $telegramUser->getId();

        // Resolve e salva/atualiza o usuário do DB
        $dbUser = $this->userController->saveOrUpdateTelegramUser($telegramUser);
        $localUserId = $dbUser->id; // ID Local do Banco de Dados

        $text = $message->getText() ? strtolower($message->getText()) : '';

        // Se for um texto vindo de um botão inline (callback) mas que caiu aqui, ignora.
        if ($update->getCallbackQuery()) {
            return;
        }

        if ($text === "/start") {
            $this->delegateCommand($text, $dbUser, $chatId);
            return;
        }

        // --- Checagem de Membro do Canal Admin (Se configurado) ---
        if (!empty($this->adminChannelId)) {
            $isMember = $this->channelController->isUserAdminChannelMember($this->adminChannelId, $telegramUserId);

            if (!$isMember) {
                // Limpa o estado ativo, se houver
                $userState = UserState::where("user_id", $localUserId)->first();
                if ($userState && $userState->state !== 'idle') {
                    $userState->state = "idle";
                    $userState->data = null;
                    $userState->save();
                }

                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "🔒 *Acesso Negado!* Para usar o bot, você deve estar inscrito no nosso canal oficial. \n\n Por favor, inscreva-se em: [Clique aqui para entrar]({$this->adminChannelInviteLink}) \n\n*⚠️ Alerta:* A não-inscrição fará com que o bot *NÃO envie* as mensagens automáticas configuradas em seus canais.",
                    "parse_mode" => "Markdown",
                    "disable_web_page_preview" => true,
                ]);
                return;
            }
        }

        // Delega outros comandos simples (/commands, /status, /cancel)
        if ($this->delegateCommand($text, $dbUser, $chatId)) {
            return;
        }

        // Busca ou cria o estado do usuário, usando o ID Local do DB.
        $userState = UserState::firstOrCreate(
            ["user_id" => $localUserId],
            ["state" => "idle", "data" => null]
        );

        LogService::inserir("User state " . ($userState ? $userState->state : 'null'), [], LogLevel::INFO, false);

        // --- Lógica para o Comando /configure (Início do Fluxo) ---
        if ($text === "/configure") {
            $userState->state = "awaiting_channel_message";
            $userState->data = null;
            $userState->save();

            // Usando botões INLINE (InlineKeyboard) para o cancelamento
            $inlineKeyboard = Keyboard::inlineButton([
                'inline_keyboard' => [
                    [
                        [
                            'text' => 'Cancelar',
                            'callback_data' => '/cancel' // O dado de callback é '/cancel'
                        ],
                    ],
                ]
            ]);

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🛠️ *Etapa 1:* Para configurar, *encaminhe uma mensagem recente* do canal que você deseja automatizar. O bot precisa ser Admin nesse canal.",
                "parse_mode" => "Markdown",
                "reply_markup" => $inlineKeyboard
            ]);
            return;
        }

        // --- Lógica de Fluxo (Etapa 1: Aguardando Mensagem do Canal) ---
        elseif ($userState->state === "awaiting_channel_message") {
            if ($message->getForwardFromChat() && $message->getForwardFromChat()->getType() === "channel") {
                $forwardedChat = $message->getForwardFromChat();
                $forwardedChatId = (string) $forwardedChat->getId();
                $dbChannel = $this->channelController->saveOrUpdateTelegramChannel($forwardedChat);
                $channelName = $dbChannel->title ?: 'Canal Sem Título';
                $permissions = $this->channelController->checkBotPermissions($forwardedChatId);

                if (!$permissions['is_admin'] || !$permissions['can_post']) {
                    // Limpa o estado e informa o erro
                    $userState->state = "idle";
                    $userState->data = null;
                    $userState->save();

                    $errorText = (!$permissions['is_admin'])
                        ? "❌ *Configuração Falhou!* O bot não é administrador do canal *{$channelName}* (`{$forwardedChatId}`). Por favor, promova o bot a administrador e tente novamente."
                        : "❌ *Configuração Falhou!* O bot é administrador do canal *{$channelName}* (`{$forwardedChatId}`), mas *não tem permissão* para enviar mensagens. Por favor, edite as permissões do bot (deve ter a permissão *Post Messages*) e tente novamente.";

                    $this->telegram->sendMessage([
                        "chat_id" => $chatId,
                        "text" => $errorText,
                        "parse_mode" => "Markdown",
                    ]);
                    return;
                }

                $userState->state = "awaiting_response_message";
                $userState->data = $forwardedChatId; // Armazena o Channel ID temporariamente
                $userState->save();

                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "✅ Canal *{$channelName}* (`{$forwardedChatId}`) registrado e permissões OK! \n\n🛠️ *Etapa 2:* Agora, *encaminhe a mensagem EXATA* (texto, foto, foto com texto, sticker, vídeo, etc.) que o bot deve enviar em resposta a cada nova publicação. **Encaminhe-a como recebida, sem edição.**",
                    "parse_mode" => "Markdown",
                    "reply_markup" => KeyboardService::cancel()
                ]);
                return;
            } else {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "❌ Mensagem inválida. Por favor, *encaminhe uma mensagem de um CANAL* para que eu possa identificar o ID.",
                    "parse_mode" => "Markdown",
                ]);
                return;
            }
        }

        // --- Lógica de Fluxo (Etapa 2: Aguardando MENSAGEM de Resposta) ---
        elseif ($userState->state === "awaiting_response_message") {
            // 1. Encaminha a mensagem do usuário para o canal de armazenamento (drive).
            try {
                $copied = $this->telegram->copyMessage([
                    'chat_id' => $this->storageChannelId,
                    'from_chat_id' => $chatId,
                    'message_id' => $message->getMessageId(),
                ]);
                $responseMessageId = $copied->getMessageId();
            } catch (\Exception $e) {
                LogService::inserir('Erro ao salvar a mensagem no canal drive', ['exception' => $e->getMessage()]);

                $userState->state = "idle"; // Limpa o estado
                $userState->data = null;
                $userState->save();

                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "*❌ Erro ao salvar a mensagem.* Não consegui copiar a mensagem para o canal drive. O bot deve ser administrador do canal drive: `{$this->storageChannelId}`. Fluxo cancelado.",
                    "parse_mode" => "Markdown",
                ]);
                return;
            }

            // Salva os dados temporariamente
            $tempData = [
                "channel_id" => $userState->data, // ID do canal de destino (Etapa 1)
                "response_message_id" => $responseMessageId, // ID da mensagem salva no canal drive
            ];

            $userState->state = "awaiting_reply_mode";
            $userState->data = json_encode($tempData);
            $userState->save();

            // --- Usando botões INLINE (InlineKeyboard) para a Etapa 3 ---
            $inlineKeyboard = Keyboard::inlineButton([
                'inline_keyboard' => [
                    [
                        ['text' => 'Enviar como Resposta', 'callback_data' => 'set_reply_mode_reply'],
                    ],
                    [
                        ['text' => 'Enviar como Nova Mensagem', 'callback_data' => 'set_reply_mode_new'],
                    ],
                    [
                        ['text' => 'Cancelar', 'callback_data' => '/cancel'], // Botão de cancelamento
                    ]
                ]
            ]);

            // Envia a pergunta com botões INLINE
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "✅ Mensagem salva com sucesso. \n\n*🛠️ Etapa 3:* Como o bot deve enviar a mensagem automática?\n\n Para cancelar, digite /cancel.",
                "parse_mode" => "Markdown",
                "reply_markup" => $inlineKeyboard, // Usa botões inline
            ]);
            return;
        }

        // --- Lógica de Fluxo (Etapa 3: Aguardando Modo de Resposta) ---
        elseif ($userState->state === "awaiting_reply_mode") {
            // Se o usuário digitou texto em vez de clicar no botão inline, informa o erro.
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "❌ Opção inválida. Por favor, *clique em um dos botões* na mensagem acima para selecionar o modo de envio. Se quiser cancelar, digite /cancel.",
                "parse_mode" => "Markdown",
            ]);
            return;
        }

        // --- Lógica para Comandos Simples (Idle state) ---
        elseif ($userState->state === "idle") {
            if ($text === "/status") {
                $this->delegateCommand($text, $dbUser, $chatId);
            } elseif ($text === "/commands") {
                $this->delegateCommand($text, $dbUser, $chatId);
            }
            // Se a mensagem for texto simples e não for um comando, mas o bot está ocioso, apenas envia uma mensagem padrão.
            else {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "Comando não reconhecido. Use /configure para iniciar ou /commands para ver a lista.",
                    "parse_mode" => "Markdown",
                ]);
            }
        }
    }
}
