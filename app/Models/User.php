<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Поля, разрешённые для массового заполнения.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'isAdmin',
        'job_id',          // должность (постовая / процедурная и т.д.)
        'department_id',   // связь с departments
        'standart_hours',  // норма часов (в месяц)
    ];

    /**
     * Поля, скрытые при сериализации.
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Приведение типов.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'password'              => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Связь: пользователь относится к отделению.
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Связь: пользователь имеет много смен.
     */
    public function shifts()
    {
        return $this->hasMany(Shift::class, 'user_id');
    }
}
