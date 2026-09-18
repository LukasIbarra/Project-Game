<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// Sincrónico a propósito (ShouldBroadcastNow, no ShouldBroadcast): esta
// VPS no tiene un queue worker corriendo (QUEUE_CONNECTION=database sin
// worker activo) y no vamos a agregar Supervisor solo para esto. El
// payload es chico (mensaje <=150 caracteres a nivel de columna), el
// costo síncrono es despreciable.
class ChatMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(private readonly ChatMessage $message)
    {
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('chat');
    }

    public function broadcastAs(): string
    {
        return 'ChatMessageCreated';
    }

    public function broadcastWith(): array
    {
        return $this->message->toBroadcastArray();
    }
}
