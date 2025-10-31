<?php

namespace App\Http\Controllers;

use App\Models\BotConfig;
use App\Models\Channel;
use App\Models\User;
use App\Models\UserState;
use App\Services\KeyboardService;
use App\Utils\Utils;
use Illuminate\Support\Facades\Log;
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
     * Ponto de entrada do Webhook. Direciona a atualização e trata exceções.
     */
    public function handleWebhook(Request $request)
    {
        Log::info("--- NOVO WEBHOOK RECEBIDO ---");
        Log::debug("Corpo da requisição:", $request->all());

        try {
            $update = $this->telegram->getWebhookUpdate();
            $isCallbackQuery = $update->getCallbackQuery();

            if ($isCallbackQuery) {
                $this->handleCallbackQuery($update);
                return response("OK", 200);
            }

            $message = Utils::getMessageFromUpdate($update);

            if (!$message) {
                Log::warning("handleWebhook: Atualização ignorada (sem mensagem/postagem processável).");
                return response("OK", 200);
            }

            $chatIdFromMessage = (string) $message->getChat()->getId();

            if ($chatIdFromMessage != $this->storageChannelId) {
                $chatType = $message->getChat()->getType();
                Log::info("Tipo de Chat: {$chatType}");

                if ($chatType === "private") {
                    $this->handlePrivateChat($update);
                } elseif ($chatType === "channel") {
                    $this->channelController->handleChannelUpdate($update, $message);
                }
            } else {
                Log::warning("handleWebhook: Atualização ignorada (veio do canal de armazenamento).");
            }

        } catch (\Exception $e) {
            Log::error(
                "ERRO CRÍTICO NO WEBHOOK: " . $e->getMessage(),
                ['exception' => $e->getMessage()]
            );
        }

        return response("OK", 200);
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
        $dbUser = Utils::resolveDbUserFromUpdate($update);

        if (!$dbUser) {
            return; // Ignora se não conseguir identificar o usuário
        }
        $localUserId = $dbUser->id;
        $telegramUserId = $dbUser->telegram_user_id;

        // 1. Envia uma notificação temporária para o usuário
        $this->telegram->answerCallbackQuery([
            'callback_query_id' => $callbackQuery->getId(),
            'text' => 'Processando sua escolha...',
            'show_alert' => false
        ]);

        $isSubscribed = $this->channelController->isUserAdminChannelMember($this->adminChannelId, $telegramUserId, $localUserId, $chatId);
        $returnCommand = $this->commandController->delegateCommand($callbackData, $dbUser, $chatId);

        if (!$isSubscribed || $returnCommand) {
            return;
        }

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

            // Determina a preferência baseada no callback data
            $mode = str_replace('set_reply_mode_', '', $callbackData);
            $isReply = ($mode === 'reply');

            // Salva o modo de resposta nos dados temporários
            $tempData["is_reply"] = $isReply;

            // Transição de estado para a nova Etapa 4
            $userState->state = "awaiting_message_frequency"; // <--- NOVO ESTADO
            $userState->data = json_encode($tempData);
            $userState->save();

            // Envia a Etapa 4 (Solicitar a Frequência)
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "✅ Modo de envio salvo para o canal *{$channelName}* (`{$channelId}`). \n\n*🛠️ Etapa 4 (Final):* Digite o número de mensagens recebidas no seu canal após o qual o bot deve enviar a resposta automática. \n\n*Ex:* Digite `1` para enviar em *TODA* mensagem, `5` para enviar a cada *5ª* mensagem, etc. \n\n*O padrão será 1 se você não configurar.*",
                "parse_mode" => "Markdown",
                "reply_markup" => KeyboardService::cancel()
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
        $localUserId = $dbUser->id;

        $text = $message->getText() ? strtolower($message->getText()) : '';

        // Se for um texto vindo de um botão inline (callback) mas que caiu aqui, ignora.
        if ($update->getCallbackQuery()) {
            return;
        }

        if ($text === "/start") {
            $this->commandController->delegateCommand($text, $dbUser, $chatId);
            return;
        }

        $isSubscribed = $this->channelController->isUserAdminChannelMember($this->adminChannelId, $telegramUserId, $localUserId, $chatId);
        $returnCommand = $this->commandController->delegateCommand($text, $dbUser, $chatId);

        if (!$isSubscribed || $returnCommand) {
            return;
        }

        // Busca ou cria o estado do usuário, usando o ID Local do DB.
        $userState = UserState::firstOrCreate(
            ["user_id" => $localUserId],
            ["state" => "idle", "data" => null]
        );

        Log::info("User state " . ($userState ? $userState->state : 'null'));

        // --- Lógica para o Comando /configure (Início do Fluxo) ---
        if ($text === "/configure") {
            $userState->state = "awaiting_channel_message";
            $userState->data = null;
            $userState->save();

            // Usando botões INLINE (InlineKeyboard) para o cancelamento
            $inlineKeyboard = KeyboardService::cancel();

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🛠️ *Etapa 1:* Para configurar, *encaminhe uma mensagem recente* do canal que você deseja automatizar. O bot precisa ser Admin nesse canal.",
                "parse_mode" => "Markdown",
                "reply_markup" => $inlineKeyboard
            ]);
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
            } else {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "❌ Mensagem inválida. Por favor, *encaminhe uma mensagem de um CANAL* para que eu possa identificar o ID.",
                    "parse_mode" => "Markdown",
                ]);
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
                Log::error('Erro ao salvar a mensagem no canal drive', ['exception' => $e->getMessage()]);

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
                        ['text' => 'Cancelar', 'callback_data' => '/cancel'],
                    ]
                ]
            ]);

            // Envia a pergunta com botões INLINE
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "✅ Mensagem salva com sucesso para o canal. \n\n*🛠️ Etapa 3:* Como o bot deve enviar a mensagem automática?",
                "parse_mode" => "Markdown",
                "reply_markup" => $inlineKeyboard,
            ]);
        }

        // --- Lógica de Fluxo (Etapa 3: Aguardando Modo de Resposta) ---
        elseif ($userState->state === "awaiting_reply_mode") {
            // Se o usuário digitou texto em vez de clicar no botão inline, informa o erro.
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "❌ Opção inválida. Por favor, *clique em um dos botões* na mensagem acima para selecionar o modo de envio. Se quiser cancelar, digite /cancel.",
                "parse_mode" => "Markdown",
            ]);
        }

        // --- Lógica de Fluxo (Etapa 4: Aguardando Frequência de Mensagem) ---
        elseif ($userState->state === "awaiting_message_frequency") {
            $frequency = intval($message->getText());

            if ($frequency <= 0) {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "❌ Número inválido. Por favor, digite um número inteiro maior ou igual a 1 (Ex: 1, 5, 10).",
                    "parse_mode" => "Markdown",
                ]);
                return;
            }

            $tempData = json_decode($userState->data, true);
            $channelId = $tempData["channel_id"];
            $responseMessageId = $tempData["response_message_id"];
            $isReply = $tempData["is_reply"];

            $dbChannel = Channel::where('channel_id', $channelId)->first();
            $channelName = $dbChannel ? $dbChannel->title : "Canal Desconhecido";

            // --- Lógica de EXCLUSÃO DA MENSAGEM ANTERIOR (Configuração Antiga) ---
            $oldConfig = BotConfig::where("channel_id", $channelId)->first();
            if ($oldConfig && $oldConfig->response_message_id) {
                $oldMessageId = $oldConfig->response_message_id;
                try {
                    $this->telegram->deleteMessage([
                        'chat_id' => $this->storageChannelId,
                        'message_id' => $oldMessageId,
                    ]);
                    Log::info("Mensagem anterior ID: {$oldMessageId} excluída do canal drive.");
                } catch (\Exception $e) {
                    Log::error("Falha ao excluir mensagem antiga ({$oldMessageId}) do canal drive: " . $e->getMessage());
                }
            }
            // --- Fim da Lógica de EXCLUSÃO ---

            // Salva a configuração FINAL no BotConfig
            BotConfig::updateOrCreate(
                ["channel_id" => $channelId],
                [
                    "user_id" => $localUserId,
                    "response_message_id" => $responseMessageId,
                    "is_reply" => $isReply,
                    "send_every_x_messages" => $frequency,
                    "messages_received_count" => 0,
                ]
            );

            // Limpa o estado
            $userState->state = "idle";
            $userState->data = null;
            $userState->save();

            // Mensagem Final de Sucesso
            $replyModeText = $isReply ? "Resposta" : "Nova Mensagem";
            $frequencyText = $frequency == 1 ? "TODA mensagem" : "a cada *{$frequency}* mensagens";

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                "text" => "🎉 *Configuração Concluída!* O bot está ativo no canal *{$channelName}* (`{$channelId}`).\n\n ✅ Modo de Envio: *{$replyModeText}*\n ⏱️ Frequência: *{$frequencyText}*",
                "parse_mode" => "Markdown",
                'reply_markup' => KeyboardService::startConfig()
            ]);
        }
    }
}
