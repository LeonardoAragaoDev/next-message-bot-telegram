<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserState extends Model
{
    protected $fillable = ['telegram_user_id', 'state', 'data'];
}
