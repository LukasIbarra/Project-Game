<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendChatMessageRequest;
use App\Models\ChatMessage;
use Illuminate\Http\Request;

// Chat global de la demo: polling HTTP (ver docs/RENDER_DEPLOYMENT_ANALYSIS.md
// y GlobalChat.astro), sin WebSockets todavía. El usuario SIEMPRE se
// deriva de Sanctum (`$request->user()`), nunca de un nombre que mande
// el cliente -mismo principio que el resto de la API (CLAUDE.md #1)-.
class ChatController extends Controller
{
    private const MAX_MESSAGES = 50;

    // GET /v1/chat/messages[?after_id=N]
    //
    // Sin after_id: los últimos 50 mensajes, en orden cronológico (carga
    // inicial). Con after_id: solo los posteriores a ese id, también
    // cronológico y con el mismo tope -el polling nunca vuelve a pedir lo
    // que ya tiene, y un backlog inusualmente grande tampoco manda un
    // payload sin límite-.
    public function index(Request $request)
    {
        $afterId = (int) $request->query('after_id', 0);

        $query = ChatMessage::query()->with('user:id,name');

        if ($afterId > 0) {
            $messages = $query->where('id', '>', $afterId)
                ->orderBy('id')
                ->take(self::MAX_MESSAGES)
                ->get();
        } else {
            $messages = $query->orderByDesc('id')
                ->take(self::MAX_MESSAGES)
                ->get()
                ->sortBy('id')
                ->values();
        }

        return response()->json($messages->map($this->present(...)));
    }

    // POST /v1/chat/messages { message }
    public function store(SendChatMessageRequest $request)
    {
        $message = ChatMessage::create([
            'user_id' => $request->user()->id,
            'message' => $request->validated()['message'],
        ]);

        $message->setRelation('user', $request->user());

        return response()->json($this->present($message), 201);
    }

    // Forma mínima expuesta al cliente -nunca email ni ningún otro campo
    // de User (CLAUDE.md/Fase Deploy: no exponer información sensible).
    private function present(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'user_name' => $message->user->name,
            'message' => $message->message,
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }
}
