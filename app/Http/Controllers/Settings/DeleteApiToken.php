<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;

/**
 * Revoke one of the signed-in user's personal access tokens. Scoped through the user's own `tokens()`
 * relation, so a user can only ever delete their own token, never another account's.
 */
final class DeleteApiToken extends FormRequest
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
        return [];
    }

    public function __invoke(self $request, string $token): JsonResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return response()->json(['deleted' => true]);
    }
}
