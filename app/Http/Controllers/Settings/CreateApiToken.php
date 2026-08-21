<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;

/**
 * Mint a Sanctum personal access token for the signed-in user, used to authenticate MCP clients. The plain
 * text token is shown once, here, and never again — only its hash is stored.
 */
final class CreateApiToken extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
        ];
    }

    public function __invoke(self $request): JsonResponse
    {
        $token = $request->user()->createToken($request->str('name')->toString());

        return response()->json([
            'token' => $token->plainTextToken,
            'accessToken' => [
                'id' => $token->accessToken->id,
                'name' => $token->accessToken->name,
                'created_at' => $token->accessToken->created_at?->toDayDateTimeString(),
                'last_used_at' => null,
            ],
        ]);
    }
}
