<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder; // Importar para usar o Scopes

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * Adicionamos os campos do Telegram aqui.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        // --- Novos Campos do Telegram ---
        'telegram_user_id',
        'first_name',
        'last_name',
        'username',
        'language_code',
        // -------------------------------
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function state()
    {
        return $this->hasOne(UserState::class);
    }
}
