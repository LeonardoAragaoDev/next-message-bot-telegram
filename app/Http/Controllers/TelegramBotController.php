<?php

namespace App\Http\Controllers;

use App\Models\BotConfig;
use App\Models\Channel;
use App\Models\UserState;
use Telegram\Bot\Api;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Objects\Update;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ChannelController;

class TelegramBotController extends Controller
{
    protected Api $telegram;
    protected string $storageChannelId;
    protected string $adminChannelId;
    protected string $adminChannelInviteLink;
    protected UserController $userController;
    protected ChannelController $channelController;

    /**
     * Construtor para injeção de dependências.
     */
    public function __construct(Api $telegram, UserController $userController, ChannelController $channelController)
    {
        $this->telegram = $telegram;
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

        // 1. Prioriza o from do CallbackQuery (O USUÁRIO que clicou)
        if ($callbackQuery = $update->getCallbackQuery()) {
            $user = $callbackQuery->getFrom();
        }
        // 2. Em seguida, verifica o from da Message (O USUÁRIO que enviou a mensagem)
        elseif ($message = $update->getMessage()) {
            $user = $message->getFrom();
        }
        // Se for outro tipo de atualização, $user será null

        if ($user) {
            // Log para debug. O objeto $user AQUI é Telegram\Bot\Objects\User
            Log::info("User info from update (ID): " . $user->getId());

            // A checagem de bot é importante, mas o from de um CallbackQuery
            // geralmente é o usuário humano (is_bot: false)
            if ($user->getIsBot()) {
                Log::warning("resolveDbUserFromUpdate: Ignorando usuário bot ID: " . $user->getId());
                return null;
            }

            // $user é um Telegram\Bot\Objects\User, resolvendo o TypeError
            return $this->userController->saveOrUpdateTelegramUser($user);
        }

        return null;
    }

    /**
     * Ponto de entrada do Webhook. Direciona a atualização e trata exceções.
     */
    public function handleWebhook(Request $request)
    {
        Log::info("--- NOVO WEBHOOK RECEBIDO ---");
        Log::info("Corpo da requisição:", $request->all());

        try {
            $update = $this->telegram->getWebhookUpdate();

            // 0. Trata Callback Query (Botões Inline)
            if ($update->getCallbackQuery()) {
                $this->handleCallbackQuery($update);
                return response("OK", 200);
            }

            // Verifica se a atualização tem uma mensagem/postagem que podemos processar
            $message = $this->getMessageFromUpdate($update);

            if (!$message) {
                Log::info("handleWebhook: Atualização ignorada (sem mensagem/postagem processável).");
                return response("OK", 200);
            }

            $chatIdFromMessage = (string) $message->getChat()->getId();

            // Para não receber webhooks do próprio canal de armazenamento
            if ($chatIdFromMessage != $this->storageChannelId) {
                $chatType = $message->getChat()->getType();
                Log::info("Tipo de Chat: {$chatType}");

                // 1. Chat Privado (Configuração)
                if ($chatType === "private") {
                    $this->handlePrivateChat($update);
                }

                // 2. Canal (Disparo Automático)
                elseif ($chatType === "channel") {
                    $this->channelController->handleChannelUpdate($update, $message);
                }
            } else {
                Log::info("handleWebhook: Atualização ignorada (veio do canal de armazenamento).");
            }

        } catch (\Exception $e) {
            Log::error(
                "ERRO CRÍTICO NO WEBHOOK: " . $e->getMessage(),
                //['exception' => $e]
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
        $messageId = $callbackQuery->getMessage()->getMessageId();

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

            // Edita a mensagem original para remover os botões iniciais
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => "✅ Configuração iniciada! Preparando a primeira etapa...",
                'parse_mode' => 'Markdown',
                'reply_markup' => Keyboard::inlineButton(['inline_keyboard' => []])
            ]);

            // Envia a mensagem da Etapa 1
            $inlineKeyboard = Keyboard::inlineButton([
                'inline_keyboard' => [
                    [['text' => 'Cancelar', 'callback_data' => '/cancelar']],
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

        // --- Lógica de Cancelamento (Comando /cancelar via botão inline) ---
        if ($callbackData === '/cancelar') {
            // Usa o ID local do DB para buscar o estado
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
                        Log::info("Mensagem temporária ID: {$messageIdToClean} excluída do canal drive após cancelamento via callback.");
                    } catch (\Exception $e) {
                        Log::warning("Falha ao excluir mensagem temporária ({$messageIdToClean}) do canal drive durante o cancelamento via callback: " . $e->getMessage());
                    }
                }

                // Limpa o estado
                $userState->state = "idle";
                $userState->data = null;
                $userState->save();

                // Edita a mensagem original para confirmar o cancelamento e remover botões
                $this->telegram->editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    "text" => "❌ *Configuração cancelada.* Você pode iniciar uma nova configuração a qualquer momento com o comando /configure.",
                    "parse_mode" => "Markdown",
                ]);
            } else {
                // Edita a mensagem se não houver estado ativo
                $this->telegram->editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    "text" => "❌ *Nenhuma configuração ativa para cancelar.*",
                    "parse_mode" => "Markdown",
                ]);
            }
            return;
        }

        // 2. Verifica se a callback é sobre o modo de resposta (Etapa 3)
        if (strpos($callbackData, 'set_reply_mode_') === 0) {
            $userState = UserState::where("user_id", $localUserId)->first();

            // Apenas permite se o estado for o esperado (awaiting_reply_mode)
            if (!$userState || $userState->state !== "awaiting_reply_mode") {
                // Edita a mensagem para remover os botões e informar o erro
                $this->telegram->editMessageText([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => "❌ Ação expirada ou inválida. Por favor, comece o fluxo com /configure.",
                    'parse_mode' => 'Markdown',
                    'reply_markup' => Keyboard::inlineButton(['inline_keyboard' => []])
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
                    Log::info("Mensagem anterior ID: {$oldMessageId} excluída do canal drive.");
                } catch (\Exception $e) {
                    Log::warning("Falha ao excluir mensagem antiga ({$oldMessageId}) do canal drive: " . $e->getMessage());
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

            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                "text" => "🎉 *Configuração Concluída!* O bot está ativo no canal *{$channelName}* (`{$channelId}`).\n\n ✅ Modo de Envio: *{$replyModeText}*",
                "parse_mode" => "Markdown",
                'reply_markup' => Keyboard::inlineButton(['inline_keyboard' => []])
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
            $inlineKeyboard = Keyboard::inlineButton([
                'inline_keyboard' => [
                    [
                        ['text' => 'Entrar no Canal', 'url' => $this->adminChannelInviteLink],
                        // ['text' => 'Iniciar Configuração', 'callback_data' => '/configure'],
                    ],
                ]
            ]);

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🤖 *Olá, " . $dbUser->first_name . "! Eu sou o NextMessageBot.*\n\nEnvie o comando /configure para iniciar a automação no seu canal, para conferir todos os comandos digite /commands e caso esteja configurando e queira cancelar a qualquer momento basta digitar /cancelar.\n\nPara usar o bot, você deve estar inscrito no nosso [canal oficial]({$this->adminChannelInviteLink}).",
                "parse_mode" => "Markdown",
                "reply_markup" => $inlineKeyboard,
            ]);
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

        // Busca ou cria o estado do usuário, usando o ID Local do DB.
        $userState = UserState::firstOrCreate(
            ["user_id" => $localUserId],
            ["state" => "idle", "data" => null]
        );
        Log::info("User state " . ($userState ? $userState->state : 'null'));

        // --- Lógica para o Comando /cancelar (Prioridade) ---
        if ($text === "/cancelar") {
            if ($userState->state !== "idle") {
                // Lógica de Limpeza de Mensagem Temporária ao cancelar
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
                        Log::info("Mensagem temporária ID: {$messageIdToClean} excluída do canal drive após cancelamento.");
                    } catch (\Exception $e) {
                        Log::warning("Falha ao excluir mensagem temporária ({$messageIdToClean}) do canal drive durante o cancelamento: " . $e->getMessage());
                    }
                }

                // Limpa o estado
                $userState->state = "idle";
                $userState->data = null;
                $userState->save();

                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "❌ *Configuração cancelada.* Você pode iniciar uma nova configuração a qualquer momento com o comando /configure.",
                    "parse_mode" => "Markdown",
                ]);
                return;
            } else {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "❌ *Nenhuma configuração ativa para cancelar.*",
                    "parse_mode" => "Markdown",
                ]);
            }
            return;
        }

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
                            'callback_data' => '/cancelar' // O dado de callback é '/cancelar'
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
                    "text" => "✅ Canal *{$channelName}* (`{$forwardedChatId}`) registrado e permissões OK! \n\n🛠️ *Etapa 2:* Agora, *encaminhe a mensagem EXATA* (texto, foto, foto com texto, sticker, vídeo, etc.) que o bot deve enviar em resposta a cada nova publicação. **Encaminhe-a como recebida, sem edição.**\n\n Para cancelar, digite /cancelar.",
                    "parse_mode" => "Markdown",
                ]);
                return;
            } else {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "❌ Mensagem inválida. Por favor, *encaminhe uma mensagem de um CANAL* para que eu possa identificar o ID. Para cancelar, digite /cancelar.",
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
                Log::error('*❌ Erro ao salvar a mensagem.* Verifique se o bot é administrador do canal drive (' . $this->storageChannelId . '). Erro: ', ['exception' => $e->getMessage()]);

                $userState->state = "idle"; // Limpa o estado
                $userState->data = null;
                $userState->save();

                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "*❌ Erro ao salvar a mensagem.* Não consegui copiar a mensagem para o canal drive. O bot deve ser administrador do canal drive: `{$this->storageChannelId}`. Fluxo cancelado. Tente novamente com /configure.",
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
                        ['text' => 'Cancelar', 'callback_data' => '/cancelar'], // Botão de cancelamento
                    ]
                ]
            ]);

            // Envia a pergunta com botões INLINE
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "✅ Mensagem salva com sucesso. \n\n*🛠️ Etapa 3:* Como o bot deve enviar a mensagem automática?\n\n Para cancelar, digite /cancelar.",
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
                "text" => "❌ Opção inválida. Por favor, *clique em um dos botões* na mensagem acima para selecionar o modo de envio. Se quiser cancelar, digite /cancelar.",
                "parse_mode" => "Markdown",
            ]);
            return;
        }

        // --- Lógica para Comandos Simples (Idle state) ---
        elseif ($userState->state === "idle") {
            if ($text === "/status") {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "✅ *O Bot tá on!*",
                    "parse_mode" => "Markdown",
                ]);
            } elseif ($text === "/commands") {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "⚙️ Comandos\n\n /start - Iniciar o bot\n /configure - Configurar o bot para um canal\n /status - Verificar status do bot\n /cancelar - Cancelar qualquer fluxo de configuração ativo",
                    "parse_mode" => "Markdown",
                ]);
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
