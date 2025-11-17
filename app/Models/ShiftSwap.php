<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftSwap extends Model
{
    use HasFactory;

    /**
     * Массово заполняемые поля.
     */
    protected $fillable = [
        'requester_id',      // кто просит подмену
        'responder_id',      // кто подменяет (коллега)
        'target_user_id',    // если заявка личная — кому адресована

        'date',
        'shift_type',        // '12h', '24h', '12n' и т.п.
        'note',

        'target_type',       // all | direct
        'status',            // await_colleagues | await_head | approved | declined

        'responded_at',      // когда коллега согласился подменить
        'head_id',           // какая старшая утвердила/отклонила
        'head_approved_at',  // когда старшая утвердила
    ];

    /**
     * Касты типов.
     */
    protected $casts = [
        'date'             => 'date',
        'responded_at'     => 'datetime',
        'head_approved_at' => 'datetime',
    ];

    /* ============================
     *  СВЯЗИ
     * ============================
     */

    // Кто просит подмену
    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    // Кто согласился подменить
    public function responder()
    {
        return $this->belongsTo(User::class, 'responder_id');
    }

    // Кому адресована (если личная заявка)
    public function target()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    // Старшая медсестра, которая утвердила/отклонила
    public function head()
    {
        return $this->belongsTo(User::class, 'head_id');
    }

    /* ============================
     *  УДОБНЫЕ ACCESSORS / HELPERS
     * ============================
     */

    public function isPendingForColleagues(): bool
    {
        return $this->status === 'await_colleagues';
    }

    public function isAwaitingHead(): bool
    {
        return $this->status === 'await_head';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isDeclined(): bool
    {
        return $this->status === 'declined';
    }
}
