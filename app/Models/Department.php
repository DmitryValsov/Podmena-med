<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    /**
     * Разрешённые к массовому заполнению поля.
     */
    protected $fillable = [
        'name',
        'quantity_doctors',
    ];

    /**
     * Связь: одно отделение → много пользователей.
     */
    public function users()
    {
        return $this->hasMany(User::class, 'department_id');
    }
}
