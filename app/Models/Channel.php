<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'title',
        'username',
        'type',
    ];

    // Se a sua coluna 'channel_id' for a chave de busca para as relações
    public function botConfig()
    {
        return $this->hasOne(BotConfig::class, 'channel_id', 'channel_id');
    }
}
