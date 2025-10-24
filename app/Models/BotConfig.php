<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BotConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'channel_id',
        'user_id',
        'response_message_id',
        'is_reply',
    ];

}
