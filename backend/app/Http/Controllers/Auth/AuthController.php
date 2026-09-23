<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        // Fase 10.5.1, sección 11: la app entera está en español, pero los
        // mensajes de validación por defecto de Laravel vienen en inglés
        // (APP_LOCALE=en) -esto hacía que un simple "ese correo ya existe"
        // se mostrara como "The email has already been taken.", rompiendo
        // la identidad de la primera pantalla que ve un usuario nuevo-.
        // Mensajes custom acá, contenidos a este controller: no se tocó el
        // locale global ni ningún otro validador de la app.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'name.required' => 'Elegí un nombre para tu personaje.',
            'email.required' => 'Ingresá un correo.',
            'email.email' => 'Ese correo no parece válido.',
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'password.required' => 'Elegí una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña necesita al menos 8 caracteres.',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Fase 6: todo usuario nuevo necesita un Character propio para que
        // inventario/equipamiento tengan dónde vivir -antes de esta fase
        // ningún flujo creaba uno-. Stats y appearance_json quedan en sus
        // defaults de columna (level 1, appearance {"body":"base"}, etc.).
        $character = $user->character()->create([
            'name' => $data['name'],
        ]);

        // F23: el registro YA NO crea una mascota automática ("Compañero"
        // quedó retirado, ver docs/PETS_EXPEDITIONS_SYSTEM.md y
        // migración 2026_09_23_000004). El usuario elige su primera
        // mascota explícitamente la primera vez que entra a /pet
        // (POST /v1/pet/adopt, gratis) — mismo principio de resolución
        // perezosa que el resto del proyecto, solo que acá la "resolución"
        // es una decisión real del jugador, no un cálculo automático.
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'Ingresá tu correo.',
            'email.email' => 'Ese correo no parece válido.',
            'password.required' => 'Ingresá tu contraseña.',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Ese correo o contraseña no coincide.'],
            ]);
        }

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
