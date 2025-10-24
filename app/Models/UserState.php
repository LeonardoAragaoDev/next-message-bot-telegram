<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserState extends Model
{
    protected $fillable = ['user_id', 'state', 'data'];

    // Adicione a propriedade $casts para que 'data' seja tratado como array/json
    protected $casts = [
        'data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
