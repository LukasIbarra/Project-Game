<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'message',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Misma forma que ChatController::present() usaba inline -ahora vive
    // acá para que el evento de broadcasting (ChatMessageCreated) y la
    // respuesta HTTP nunca puedan desincronizarse en dos formatos
    // paralelos del mismo mensaje.
    public function toBroadcastArray(): array
    {
        return [
            'id' => $this->id,
            'user_name' => $this->user->name,
            'message' => $this->message,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
