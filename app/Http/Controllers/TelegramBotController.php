<?php

namespace App\Http\Controllers;

use App\Models\BotConfig;
use App\Models\Channel;
use App\Models\UserState;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Keyboard\Keyboard;
use App\Http\Controllers\UserController;

class TelegramBotController extends Controller
{
    protected Api $telegram;
    protected string $storageChannelId;
    protected UserController $userController;
    protected ChannelController $channelController;

    // Injetamos o ChannelController no construtor
    public function __construct(Api $telegram, UserController $userController, ChannelController $channelController)
    {
        $this->telegram = $telegram;
        $this->userController = $userController;
        $this->channelController = $channelController;
        $this->storageChannelId = env('TELEGRAM_STORAGE_CHANNEL_ID') ?? '';
    }

    // --- MÉTODOS AUXILIARES PARA EXTRAIR A MENSAGEM ---
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
     * Ponto de entrada do Webhook. Direciona a atualização.
     */
    public function handleWebhook(Request $request)
    {
        Log::info("--- NOVO WEBHOOK RECEBIDO ---");
        Log::info("Corpo da requisição:", $request->all());

        $update = $this->telegram->getWebhookUpdate();

        // 1. Usa a função auxiliar para obter a mensagem/postagem de forma segura
        $message = $this->getMessageFromUpdate($update);
        $chatIdFromMessage = (string) $message->getChat()->getId();
        $idDiferenteCanalArmazenamento = $chatIdFromMessage != $this->storageChannelId;

        // Para não receber webhooks do próprio canal de armazenamento
        if ($idDiferenteCanalArmazenamento) {
            $chatType = $message->getChat()->getType();
            Log::info("Tipo de Chat: {$chatType}");

            // --- 1. Chat Privado (Configuração) ---
            if ($chatType === "private") {
                $this->handlePrivateChat($update);
            }

            // --- 2. Canal (Disparo Automático) ---
            elseif ($chatType === "channel") {
                $this->handleChannelUpdate($update, $message);
            }
        } else {
            Log::info("handleWebhook: Atualização ignorada (veio do canal de armazenamento).");
        }

        return response("OK", 200);
    }

    /**
     * Gerencia o fluxo de configuração em chat privado.
     */
    protected function handlePrivateChat(Update $update)
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $telegramUser = $message->getFrom();

        // O ID do Telegram. Será usado para salvar/atualizar o User.
        $telegramUserId = $telegramUser->getId();

        // --- MELHORIA: Salva/Atualiza o usuário antes de qualquer lógica ---
        // $dbUser agora é o objeto App\Models\User.
        $dbUser = $this->userController->saveOrUpdateTelegramUser($telegramUser);

        // Usamos o ID do Laravel ($dbUser->id) para salvar o estado.
        $localUserId = $dbUser->id;

        $text = $message->getText() ? strtolower($message->getText()) : '';

        // 1. Busca o estado atual do usuário usando o user_id local
        // MUDANÇA AQUI: de 'telegram_user_id' para 'user_id'
        $userState = UserState::firstOrCreate(
            ["user_id" => $localUserId],
            ["state" => "idle", "data" => null]
        );
        Log::info("User state " . ($userState ? $userState->state : 'null'));

        // --- Lógica para o Comando /cancelar (Prioridade) ---
        if ($text === "/cancelar") {
            if ($userState->state !== "idle") {
                // --- Lógica de Limpeza de Mensagem Temporária (ao cancelar) ---
                $messageIdToClean = null;

                if ($userState->data) {
                    $tempData = json_decode($userState->data, true);
                    $messageIdToClean = $tempData["response_message_id"] ?? null;
                }

                // Se houver um ID de mensagem para limpar, tente excluir do canal de armazenamento
                if ($messageIdToClean) {
                    try {
                        $this->telegram->deleteMessage([
                            'chat_id' => $this->storageChannelId,
                            'message_id' => $messageIdToClean,
                        ]);
                        Log::info("Mensagem temporária ID: {$messageIdToClean} excluída do canal drive após cancelamento.");
                    } catch (\Exception $e) {
                        // Logamos o warning, mas não paramos o cancelamento.
                        Log::warning("Falha ao excluir mensagem temporária ({$messageIdToClean}) do canal drive durante o cancelamento: " . $e->getMessage());
                    }
                }
                // --- Fim da Lógica de Limpeza ---

                $userState->state = "idle";
                $userState->data = null;
                $userState->save();

                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "❌ *Configuração cancelada.* Você pode iniciar uma nova configuração a qualquer momento com o comando /configurar.",
                    "parse_mode" => "Markdown",
                    // "reply_markup" => new ReplyKeyboardRemove(),
                ]);
                return;
            } else {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "❌ *Nenhuma configuração ativa para cancelar.*",
                    "parse_mode" => "Markdown",
                ]);
            }
        }

        // --- Lógica para o Comando /configurar (Início do Fluxo) ---
        if ($text === "/configurar") {
            $userState->state = "awaiting_channel_message";
            $userState->save();
            $keyboard = [
                ["/cancelar"],
            ];

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🛠️ *Etapa 1:* Para configurar, *encaminhe uma mensagem recente* do canal que você deseja automatizar. O bot precisa ser Admin nesse canal.\n\n Para cancelar, digite /cancelar.",
                "parse_mode" => "Markdown",
                "reply_markup" => new Keyboard([
                    "keyboard" => $keyboard,
                    "resize_keyboard" => true,
                    "one_time_keyboard" => true,
                ]),
            ]);
            return;
        }

        // --- Lógica de Fluxo (Etapa 1: Aguardando Mensagem do Canal) ---
        elseif ($userState->state === "awaiting_channel_message") {
            if ($message->getForwardFromChat() && $message->getForwardFromChat()->getType() === "channel") {
                $forwardedChat = $message->getForwardFromChat();
                $forwardedChatId = (string) $forwardedChat->getId();

                // 1. Salva/Atualiza as informações do Canal
                $dbChannel = $this->channelController->saveOrUpdateTelegramChannel($forwardedChat);
                $channelName = $dbChannel->title ?: 'Canal Sem Título'; // Nome amigável

                // 2. NOVIDADE: Verifica as permissões do bot
                $permissions = $this->channelController->checkBotPermissions($forwardedChatId);

                if (!$permissions['is_admin']) {
                    // $userState->state = "idle";
                    // $userState->data = null;
                    // $userState->save();

                    $this->telegram->sendMessage([
                        "chat_id" => $chatId,
                        "text" => "❌ *Configuração Falhou!* O bot não é administrador do canal *{$channelName}* (`{$forwardedChatId}`). Por favor, promova o bot a administrador e tente novamente.",
                        "parse_mode" => "Markdown",
                    ]);
                    return;
                }

                if (!$permissions['can_post']) {
                    $userState->state = "idle";
                    $userState->data = null;
                    $userState->save();

                    $this->telegram->sendMessage([
                        "chat_id" => $chatId,
                        "text" => "❌ *Configuração Falhou!* O bot é administrador do canal *{$channelName}* (`{$forwardedChatId}`), mas *não tem permissão* para enviar mensagens. Por favor, edite as permissões do bot (deve ter a permissão *Post Messages*) e tente novamente.",
                        "parse_mode" => "Markdown",
                    ]);
                    return;
                }

                // Se a validação passou, continua para a próxima etapa (Etapa 2)
                $userState->state = "awaiting_response_message";
                $userState->data = $forwardedChatId;
                $userState->save();
                $keyboard = [
                    ["/cancelar"],
                ];

                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "✅ Canal *{$channelName}* (`{$forwardedChatId}`) registrado e permissões OK! \n\n🛠️ *Etapa 2:* Agora, *encaminhe a mensagem EXATA* (texto, foto, foto com texto, sticker, vídeo, etc.) que o bot deve enviar em resposta a cada nova publicação. **Encaminhe-a como recebida, sem edição.**\n\n Para cancelar, digite /cancelar.",
                    "parse_mode" => "Markdown",
                    "reply_markup" => new Keyboard([
                        "keyboard" => $keyboard,
                        "resize_keyboard" => true,
                        "one_time_keyboard" => true,
                    ]),
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

            // 1. Encaminha a mensagem do usuário (que pode ser qualquer coisa: foto, vídeo, texto) para o canal de armazenamento.
            try {
                $copied = $this->telegram->copyMessage([
                    'chat_id' => $this->storageChannelId,
                    'from_chat_id' => $chatId,
                    'message_id' => $message->getMessageId(),
                ]);

                // O ID da mensagem SALVA no seu canal drive
                $responseMessageId = $copied->getMessageId();
            } catch (\Exception $e) {
                // Log de erro
                Log::error('*❌ Erro ao salvar a mensagem.* Verifique se o bot é administrador do canal drive (' . $this->storageChannelId . '). Por favor, tente novamente. Erro: ', ['exception' => $e->getMessage()]);

                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "*❌ Erro ao salvar a mensagem.* Problema com o canal do bot.",
                    "parse_mode" => "Markdown",
                ]);
                return;
            }

            // Salva os dados temporariamente
            $tempData = [
                "channel_id" => $userState->data, // O ID do canal de destino (salvo na etapa 1)
                "response_message_id" => $responseMessageId, // O ID da mensagem no canal drive
            ];

            $userState->state = "awaiting_reply_mode";
            $userState->data = json_encode($tempData);
            $userState->save();

            // Envia a pergunta com botões
            $keyboard = [
                ["Enviar como Resposta"],
                ["Enviar como Nova Mensagem"],
            ];

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "✅ Mensagem salva com sucesso. \n\n*🛠️ Etapa 3:* Como o bot deve enviar a mensagem automática?\n\n Para cancelar, digite /cancelar.",
                "parse_mode" => "Markdown",
                "reply_markup" => new Keyboard([
                    "keyboard" => $keyboard,
                    "resize_keyboard" => true,
                    "one_time_keyboard" => true,
                ]),
            ]);
            return;
        }

        // --- Lógica de Fluxo (Etapa 3: Aguardando Modo de Resposta) ---
        elseif ($userState->state === "awaiting_reply_mode") {

            $tempData = json_decode($userState->data, true);

            $channelId = $tempData["channel_id"];
            // NOVIDADE: Busca o nome do canal para a mensagem de sucesso
            $dbChannel = Channel::where('channel_id', $channelId)->first();
            $channelName = $dbChannel ? $dbChannel->title : "Canal Desconhecido";
            $responseMessageId = $tempData["response_message_id"]; // ID da mensagem no canal drive

            // --- Lógica de EXCLUSÃO DA MENSAGEM ANTERIOR ---
            // 1. Busca a configuração existente para este canal
            $oldConfig = BotConfig::where("channel_id", $channelId)->first();

            // 2. Verifica se existia uma mensagem de resposta salva ANTERIORMENTE
            if ($oldConfig && $oldConfig->response_message_id) {
                $oldMessageId = $oldConfig->response_message_id;

                // 3. Tenta excluir a mensagem antiga do canal de armazenamento
                try {
                    $this->telegram->deleteMessage([
                        'chat_id' => $this->storageChannelId,
                        'message_id' => $oldMessageId,
                    ]);
                    Log::info("Mensagem anterior ID: {$oldMessageId} excluída do canal drive.");
                } catch (\Exception $e) {
                    // É comum falhar a exclusão se a mensagem for muito antiga ou já foi excluída.
                    // Logamos o erro, mas não paramos o fluxo de configuração, pois a nova mensagem já foi salva.
                    Log::warning("Falha ao excluir mensagem antiga ({$oldMessageId}) do canal drive: " . $e->getMessage());
                }
            }
            // --- Fim da Lógica de EXCLUSÃO ---

            // Determina a preferência: se o texto contém "resposta", é reply (true)
            $isReply = (strpos($text, "resposta") !== false);

            // Salva a configuração FINAL no BotConfig
            BotConfig::updateOrCreate(
                ["channel_id" => $channelId],
                [
                    "user_id" => $telegramUserId,
                    "response_message_id" => $responseMessageId,
                    "is_reply" => $isReply,
                ]
            );

            // Limpa o estado e remove o teclado
            $userState->state = "idle";
            $userState->data = null;
            $userState->save();
            $keyboard = [
                ["/start"],
                ["/configurar"],
                ["/commands"],
            ];

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🎉 *Configuração Concluída!* O bot está ativo no canal *{$channelName}* (`{$channelId}`).\n\n ✅ Modo de Envio: *" . ($isReply ? "Resposta" : "Nova Mensagem") . "*",
                "parse_mode" => "Markdown",
                "reply_markup" => new Keyboard([
                    "keyboard" => $keyboard,
                    "resize_keyboard" => true,
                    "one_time_keyboard" => true,
                ]),
            ]);
            return;
        }

        // --- Outros comandos e Resposta Padrão ---
        elseif ($text === "/start") {
            // O usuário já foi salvo/atualizado pelo UserController no início do método.
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🤖 *Olá, " . $dbUser->first_name . "! Eu sou o NextMessageBot.* Envie o comando /configurar para iniciar a automação no seu canal, para conferir todos os comandos digite /commands e caso esteja configurando e queira cancelar a qualquer momento basta digitar /cancelar.",
                "parse_mode" => "Markdown",
            ]);
        }

        // --- Lógica para o Comando /status ---'
        elseif ($text === "/status") {
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "✅ *O Bot tá on!*",
                "parse_mode" => "Markdown",
            ]);
        }

        // --- Lógica para o Comando /commands ---'
        elseif ($text === "/commands") {
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "⚙️ /start - Iniciar o bot\n\n /configurar - Configurar o bot para um canal\n\n /status - Verificar status do bot",
                "parse_mode" => "Markdown",
            ]);
        }
    }

    /**
     * Executa a função principal do bot: encaminhar a mensagem configurada no canal.
     */
    protected function handleChannelUpdate(Update $update, $message)
    {
        $channelId = (string) $message->getChat()->getId();
        $messageId = $message->getMessageId();

        Log::info("handleChannelUpdate: Processando atualização do canal ID: {$channelId}");

        // Verifica se a postagem contém conteúdo que queremos reagir (qualquer coisa)
        // Se a mensagem for válida (Message ou ChannelPost), podemos reagir.
        if ($message->getEffectiveType() !== 'service' && $message->getEffectiveType() !== 'new_chat_members' /* etc. */) {

            Log::info("handleChannelUpdate: Tipo de conteúdo suportado detectado. Buscando configuração...");

            // 2. Busca a configuração de resposta para este canal
            $config = BotConfig::where("channel_id", $channelId)->first();

            if ($config && $config->response_message_id) { // Verifica se há uma mensagem ID configurada
                Log::info("handleChannelUpdate: Configuração ENCONTRADA para o canal {$channelId}. Disparando resposta (Copy).");

                $params = [
                    'chat_id' => $channelId, // Canal de destino
                    'from_chat_id' => $this->storageChannelId, // Canal de origem (drive)
                    'message_id' => $config->response_message_id, // ID da mensagem no canal drive
                    'disable_notification' => false,
                ];

                // Condição para enviar como resposta ou nova mensagem
                if ($config->is_reply) {
                    $params["disable_notification"] = true; // Geralmente se desativa a notificação para replies automáticos
                    $params["reply_to_message_id"] = $messageId;
                }

                // 3. Dispara a mensagem configurada usando copyMessage
                $this->telegram->copyMessage($params);

            } else {
                Log::warning("handleChannelUpdate: Configuração NÃO ENCONTRADA ou response_message_id ausente para o canal ID: {$channelId}.");
            }
        } else {
            Log::info("handleChannelUpdate: Conteúdo ignorado (postagem de serviço ou tipo ignorado).");
        }
    }
}
