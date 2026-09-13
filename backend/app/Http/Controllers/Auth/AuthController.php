<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PetProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly PetProvisioningService $petProvisioning)
    {
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
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

        // Fase 7: todo Character nuevo recibe una mascota, mismo
        // principio que el Character recién creado arriba.
        $this->petProvisioning->ensureForCharacter($character);

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
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no coinciden con ningún registro.'],
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
