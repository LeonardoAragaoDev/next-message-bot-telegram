<?php

namespace App\Http\Controllers;

use App\Models\BotConfig;
use App\Models\UserState;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Keyboard\ReplyKeyboardRemove;

class TelegramBotController extends Controller
{
    protected Api $telegram;

    public function __construct(Api $telegram)
    {
        $this->telegram = $telegram;
    }

    // --- MÉTODOS AUXILIARES PARA EXTRAIR A MENSAGEM ---
    /**
     * Extrai a mensagem da atualização, seja ela message ou channel_post.
     * Necessário para lidar com diferentes tipos de updates do Telegram de forma segura.
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
     * Ponto de entrada do Webhook. Direciona a atualização.
     */
    public function handleWebhook(Request $request)
    {
        Log::info("--- NOVO WEBHOOK RECEBIDO ---");
        Log::info("Corpo da requisição:", $request->all());

        $update = $this->telegram->getWebhookUpdate();

        // 1. Usa a função auxiliar para obter a mensagem/postagem de forma segura
        $message = $this->getMessageFromUpdate($update);

        if ($message) {
            $chatType = $message->getChat()->getType();
            Log::info("Tipo de Chat: {$chatType}");

            // --- 1. Chat Privado (Configuração) ---
            if ($chatType === "private") {
                $this->handlePrivateChat($update);
            }

            // --- 2. Canal (Disparo Automático) ---
            elseif ($chatType === "channel") {
                // Passa a $message, que é o ChannelPost/Message, para o método de disparo
                $this->handleChannelUpdate($update, $message);
            }
        }
        // Tratamento de updates que não são mensagens (ex: my_chat_member, chat_member, etc.)
        else {
            Log::info("handleWebhook: Atualização ignorada (não é message ou channel_post).");
        }

        return response("OK", 200);
    }

    /**
     * Gerencia o fluxo de configuração de 3 etapas no chat privado.
     */
    protected function handlePrivateChat(Update $update)
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId();
        $text = strtolower($message->getText());

        // 1. Busca o estado atual do usuário
        $userState = UserState::firstOrCreate(["telegram_user_id" => $userId], ["state" => "idle", "data" => null]);

        // --- Lógica para o Comando /configurar ---
        if ($text === "/configurar") {
            $userState->state = "awaiting_channel_message";
            $userState->save();

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🛠️ *Etapa 1:* Para configurar, *encaminhe uma mensagem recente* do canal que você deseja automatizar. O bot precisa ser Admin nesse canal.",
                "parse_mode" => "Markdown",
            ]);
            return;
        }

        // --- Lógica para o Comando /cancelar ---"
        elseif ($text === "/cancelar") {
            // Limpa o estado e remove o teclado
            $userState->state = "idle";
            $userState->data = null;
            $userState->save();

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "*✅ Cancelamento realizado.* Você pode iniciar uma nova configuração a qualquer momento com /configurar.",
                "parse_mode" => "Markdown",
            ]);
            return;
        }

        // --- Lógica de Fluxo (Etapa 1: Aguardando Mensagem do Canal) ---
        elseif ($userState->state === "awaiting_channel_message") {
            if ($message->getForwardFromChat() && $message->getForwardFromChat()->getType() === "channel") {
                $forwardedChatId = $message->getForwardFromChat()->getId();

                $userState->state = "awaiting_response_message";
                $userState->data = (string) $forwardedChatId;
                $userState->save();

                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "✅ Canal ID `{$forwardedChatId}` registrado. \n\n🛠️ Etapa 2: Agora, *envie a mensagem EXATA* que o NextMessageBot deve enviar em resposta a cada nova publicação.",
                    "parse_mode" => "Markdown",
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

        // --- Lógica de Fluxo (Etapa 2: Aguardando Mensagem de Resposta) ---
        elseif ($userState->state === "awaiting_response_message") {
            $responseMessage = $message->getText();

            if (empty($responseMessage)) {
                $this->telegram->sendMessage([
                    "chat_id" => $chatId,
                    "text" => "*❌ Mensagem de resposta vazia.* Por favor, envie o texto completo da mensagem automática.",
                    "parse_mode" => "Markdown",
                ]);
                return;
            }

            // Salva os dados temporariamente para a PRÓXIMA etapa (agora usa JSON)
            $tempData = [
                "channel_id" => $userState->data, // O ID salvo na etapa 1
                "response_message" => $responseMessage
            ];

            $userState->state = "awaiting_reply_mode"; // Novo estado
            $userState->data = json_encode($tempData);
            $userState->save();

            // Envia a pergunta com botões
            $keyboard = [
                ["Enviar como Resposta (Recomendado)"],
                ["Enviar como Nova Mensagem (Sem resposta)"],
            ];

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "*🛠️ Etapa 3:* Como o bot deve enviar a mensagem automática?",
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

            // É CRUCIAL que a coluna "data" do user_states seja TEXT ou JSON no seu DB!
            $tempData = json_decode($userState->data, true);

            $channelId = $tempData["channel_id"];
            $responseMessage = $tempData["response_message"];

            // Determina a preferência: se o texto contém "resposta", é reply (true)
            $isReply = (strpos($text, "resposta") !== false);

            // Salva a configuração FINAL no BotConfig
            BotConfig::updateOrCreate(
                ["channel_id" => $channelId],
                [
                    "user_id" => $userId,
                    "response_message" => $responseMessage,
                    "is_reply" => $isReply,
                ]
            );

            // Limpa o estado e remove o teclado
            $userState->state = "idle";
            $userState->data = null;
            $userState->save();

            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "🎉 *Configuração Concluída!* O bot está ativo no canal `{$channelId}`.\n\nModo de Envio: *" . ($isReply ? "Resposta" : "Nova Mensagem") . "*",
                "parse_mode" => "Markdown",
                "reply_markup" => new ReplyKeyboardRemove(),
            ]);
            return;
        }

        // --- Lógica para o Comando /start ---'
        elseif ($text === "/start") {
            $this->telegram->sendMessage([
                "chat_id" => $chatId,
                "text" => "*Olá! Eu sou o NextMessageBot.* Envie o comando /configurar para iniciar a automação no seu canal.",
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
                "text" => "/start - Iniciar o bot\n\n /configurar - Configurar o bot para um canal\n\n /status - Verificar status do bot",
                "parse_mode" => "Markdown",
            ]);
        }
    }

    /**
     * Executa a função principal do bot: enviar a mensagem configurada no canal.
     * Recebe o objeto $message (que é o ChannelPost)
     */
    protected function handleChannelUpdate(Update $update, $message)
    {
        $channelId = (string) $message->getChat()->getId();
        $messageId = $message->getMessageId();

        Log::info("handleChannelUpdate: Processando atualização do canal ID: {$channelId}");

        // Verifica se a postagem contém conteúdo que queremos reagir (texto, foto, vídeo, etc.)
        if ($message->getText() || $message->getPhoto() || $message->getVideo()) {

            Log::info("handleChannelUpdate: Tipo de conteúdo suportado detectado. Buscando configuração...");

            // 2. Busca a configuração de resposta para este canal
            $config = BotConfig::where("channel_id", $channelId)->first();

            if ($config) {
                Log::info("handleChannelUpdate: Configuração ENCONTRADA para o canal {$channelId}. Disparando resposta.");

                $params = [
                    "chat_id" => $channelId,
                    "text" => $config->response_message,
                ];

                // Condição para enviar como resposta ou nova mensagem
                if ($config->is_reply) {
                    $params["reply_to_message_id"] = $messageId;
                }

                // 3. Dispara a mensagem configurada
                $this->telegram->sendMessage($params);
            } else {
                Log::warning("handleChannelUpdate: Configuração NÃO ENCONTRADA no DB para o canal ID: {$channelId}.");
            }
        } else {
            Log::info("handleChannelUpdate: Conteúdo ignorado (postagem sem texto/foto/vídeo).");
        }
    }
}
