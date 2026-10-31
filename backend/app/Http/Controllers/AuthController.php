<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const TOKEN_TTL_DAYS = 30;

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create($data);

        return response()->json($this->tokenPayload($user), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['이메일 또는 비밀번호가 올바르지 않습니다.'],
            ]);
        }

        return response()->json($this->tokenPayload($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => '로그아웃되었습니다.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    private function tokenPayload(User $user): array
    {
        // 사용자 전체 권한 토큰. 서브 서비스용 범위 제한 토큰은 자비스 도구 호출 단계에서 발급한다.
        $token = $user->createToken('web', ['*'], now()->addDays(self::TOKEN_TTL_DAYS));

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
            'user' => $user,
        ];
    }
}
