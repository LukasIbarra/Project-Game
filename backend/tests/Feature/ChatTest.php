<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithToken(string $name = 'Tester'): array
    {
        $user = User::factory()->create(['name' => $name]);
        $token = $user->createToken('test')->plainTextToken;

        return [$user, $token];
    }

    public function test_usuario_autenticado_puede_enviar_un_mensaje(): void
    {
        [$user, $token] = $this->userWithToken('Lukas');

        $response = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => 'hola a todos',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'hola a todos');
        $response->assertJsonPath('user_name', 'Lukas');
        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $user->id,
            'message' => 'hola a todos',
        ]);
    }

    public function test_no_autenticado_no_puede_enviar_mensajes(): void
    {
        $response = $this->postJson('/api/v1/chat/messages', ['message' => 'hola']);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_no_autenticado_no_puede_leer_mensajes(): void
    {
        $response = $this->getJson('/api/v1/chat/messages');

        $response->assertUnauthorized();
    }

    public function test_mensaje_vacio_es_rechazado(): void
    {
        [, $token] = $this->userWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/chat/messages', ['message' => '   ']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('message');
    }

    public function test_mensaje_mayor_a_150_caracteres_es_rechazado(): void
    {
        [, $token] = $this->userWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => str_repeat('a', 151),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('message');
    }

    public function test_mensaje_se_recorta_con_trim(): void
    {
        [, $token] = $this->userWithToken();

        $response = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => '  con espacios  ',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('message', 'con espacios');
    }

    public function test_no_confia_en_un_nombre_de_usuario_mandado_por_el_cliente(): void
    {
        [, $token] = $this->userWithToken('NombreReal');

        $response = $this->withToken($token)->postJson('/api/v1/chat/messages', [
            'message' => 'hola',
            'user_name' => 'Suplantador',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('user_name', 'NombreReal');
    }

    public function test_get_devuelve_mensajes_en_orden_cronologico(): void
    {
        [$user, $token] = $this->userWithToken();
        $m1 = ChatMessage::create(['user_id' => $user->id, 'message' => 'primero']);
        $m2 = ChatMessage::create(['user_id' => $user->id, 'message' => 'segundo']);
        $m3 = ChatMessage::create(['user_id' => $user->id, 'message' => 'tercero']);

        $response = $this->withToken($token)->getJson('/api/v1/chat/messages');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->values()->all();
        $this->assertSame([$m1->id, $m2->id, $m3->id], $ids);
    }

    public function test_get_con_after_id_solo_trae_mensajes_posteriores(): void
    {
        [$user, $token] = $this->userWithToken();
        $m1 = ChatMessage::create(['user_id' => $user->id, 'message' => 'viejo']);
        $m2 = ChatMessage::create(['user_id' => $user->id, 'message' => 'nuevo 1']);
        $m3 = ChatMessage::create(['user_id' => $user->id, 'message' => 'nuevo 2']);

        $response = $this->withToken($token)->getJson("/api/v1/chat/messages?after_id={$m1->id}");

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->values()->all();
        $this->assertSame([$m2->id, $m3->id], $ids);
    }

    public function test_get_nunca_devuelve_mas_de_50_mensajes(): void
    {
        [$user, $token] = $this->userWithToken();
        ChatMessage::factory()->count(55)->create(['user_id' => $user->id]);

        $response = $this->withToken($token)->getJson('/api/v1/chat/messages');

        $response->assertOk();
        $this->assertCount(50, $response->json());
    }

    public function test_respuesta_no_expone_email_del_usuario(): void
    {
        [$user, $token] = $this->userWithToken();
        ChatMessage::create(['user_id' => $user->id, 'message' => 'hola']);

        $response = $this->withToken($token)->getJson('/api/v1/chat/messages');

        $response->assertOk();
        $response->assertJsonMissingPath('0.email');
        $response->assertJsonMissingPath('0.user.email');
    }
}
