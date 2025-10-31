<?php

namespace App\Http\Controllers;

use App\Models\BotConfig;
use App\Models\Channel;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Chat as TelegramChatObject;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Objects\Update;

class ChannelController extends Controller
{
    protected Api $telegram;
    protected string $storageChannelId;

    public function __construct(Api $telegram)
    {
        $this->telegram = $telegram;
        $this->storageChannelId = env('TELEGRAM_STORAGE_CHANNEL_ID') ?? '';
    }

    /**
     * Executa a função principal do bot: encaminhar a mensagem configurada no canal.
     */
    public function handleChannelUpdate(Update $update, $message)
    {
        $channelId = (string) $message->getChat()->getId();
        $messageId = $message->getMessageId();

        Log::info("handleChannelUpdate: Processando atualização do canal ID: {$channelId}");
        $effectiveType = $message->getEffectiveType();
        if (!in_array($effectiveType, ['service', 'new_chat_members', 'left_chat_member', 'channel_chat_created' /* etc. */])) {

            Log::info("handleChannelUpdate: Tipo de conteúdo suportado detectado. Buscando configuração...");
            $config = BotConfig::where("channel_id", $channelId)->first();

            if ($config && $config->response_message_id) {
                Log::info("handleChannelUpdate: Configuração ENCONTRADA para o canal {$channelId}. Verificando frequência de envio...");
                // 1. Incrementa o contador de mensagens recebidas
                $config->messages_received_count++;

                // 2. Verifica se é hora de enviar a mensagem
                if ($config->messages_received_count >= $config->send_every_x_messages) {

                    Log::info("handleChannelUpdate: Frequência atingida ({$config->messages_received_count} de {$config->send_every_x_messages}). Disparando resposta (Copy) e zerando contador.");
                    $params = [
                        'chat_id' => $channelId,
                        'from_chat_id' => $this->storageChannelId,
                        'message_id' => $config->response_message_id,
                        'disable_notification' => false,
                    ];

                    if ($config->is_reply) {
                        $params["disable_notification"] = true;
                        $params["reply_to_message_id"] = $messageId;
                    }

                    try {
                        $this->telegram->copyMessage($params);

                        // Zera o contador APÓS o envio bem-sucedido
                        $config->messages_received_count = 0;

                    } catch (\Exception $e) {
                        Log::error("ERRO ao disparar copyMessage no canal {$channelId}: " . $e->getMessage());
                        // Em caso de erro, o contador não é zerado. Ele tentará novamente na próxima mensagem.
                    }
                } else {
                    Log::info("handleChannelUpdate: Frequência não atingida ({$config->messages_received_count} de {$config->send_every_x_messages}). Apenas atualizando contador.");
                }

                // 3. Salva o contador atualizado (se foi zerado ou incrementado)
                $config->save();

            } else {
                Log::warning("handleChannelUpdate: Configuração NÃO ENCONTRADA ou response_message_id ausente para o canal ID: {$channelId}.");
            }
        } else {
            Log::info("handleChannelUpdate: Conteúdo ignorado (postagem de serviço ou tipo ignorado: {$effectiveType}).");
        }
    }

    /**
     * Cria ou atualiza um canal na base de dados com as informações do Telegram Chat.
     * @param TelegramChatObject $telegramChat
     * @return Channel
     */
    public function saveOrUpdateTelegramChannel(TelegramChatObject $telegramChat): Channel
    {
        $channelId = (string) $telegramChat->getId();

        $data = [
            "channel_id" => $channelId,
            "title" => $telegramChat->getTitle(),
            "username" => $telegramChat->getUsername(),
            "type" => $telegramChat->getType(),
        ];

        // Busca o canal pelo ID do Telegram ou cria um novo registro
        $channel = Channel::updateOrCreate(
            ["channel_id" => $channelId],
            $data
        );

        Log::info("Canal Telegram ID: {$channelId} salvo/atualizado.");
        return $channel;
    }

    /**
     * Verifica se o bot é administrador do canal e tem permissão para postar/enviar.
     * @param string $channelId O ID do chat/canal.
     * @return array Retorna ["is_admin" => bool, "can_post" => bool]
     */
    public function checkBotPermissions(string $channelId): array
    {
        try {
            $botUsername = $this->telegram->getMe()->getUsername();
            $administrators = $this->telegram->getChatAdministrators(["chat_id" => $channelId]);

            $botMember = null;
            $is_admin = false;
            $can_post = false;

            // Encontra o bot na lista de administradores
            foreach ($administrators as $member) {
                if ($member->getUser()->getUsername() === $botUsername) {
                    $botMember = $member;
                    $is_admin = true;
                    break;
                }
            }

            if ($botMember) {
                // "can_post_messages" é a permissão mais crucial para posts de canal.
                // Usaremos "can_post_messages" para verificar se ele pode enviar posts no canal.
                // Assumindo que você usará copyMessage, ele só precisa ser admin com essa permissão.
                // Em canais, "can_post_messages" geralmente significa que ele pode criar novos posts.
                // Para reply/copia, "can_delete_messages" pode ser útil, mas "can_post_messages" é o mínimo.
                $can_post = $botMember->getCanPostMessages() === true;
            }

            return ["is_admin" => $is_admin, "can_post" => $can_post];

        } catch (\Exception $e) {
            // Se o bot não for admin, getChatAdministrators falha com erro 400.
            // O bot deve ser admin para esta verificação funcionar.
            Log::error("Falha ao verificar permissões no canal {$channelId}: " . $e->getMessage());
            return ["is_admin" => false, "can_post" => false];
        }
    }

    /**
     * Verifica se o usuário é membro do canal de administração.
     * @param string $adminChannelId O ID do canal de admin.
     * @param int $userId O ID do Telegram do usuário.
     * @return bool
     */
    public function isUserAdminChannelMember(string $adminChannelId, int $userId): bool
    {
        // Se o ID do canal admin não estiver configurado, assume-se que a verificação não é necessária.
        if (empty($adminChannelId)) {
            return true;
        }

        try {
            // Usa getChatMember para verificar o status
            $chatMember = $this->telegram->getChatMember([
                "chat_id" => $adminChannelId,
                "user_id" => $userId,
            ]);
            Log::info("Verificação de membro do canal admin para usuário {$userId} no canal {$adminChannelId}: Status - " . $chatMember->get("status"));
            $status = $chatMember->get("status");

            // O usuário é membro se o status for "member", "administrator" ou "creator".
            return in_array($status, ["member", "administrator", "creator"]);

        } catch (\Exception $e) {
            // Isso pode falhar se o bot não estiver no canal admin ou se o ID for inválido.
            // O tratamento padrão é negar o acesso ou logar e retornar false.
            Log::error("Falha ao verificar a inscrição do usuário {$userId} no canal admin {$adminChannelId}: " . $e->getMessage());
            // Em caso de falha na API, o mais seguro é impedir o uso.
            return false;
        }
    }
}
