<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            // Whether a personal D&D Beyond cobalt key is stored (never expose the value).
            'ddbSaved' => filled($request->user()->ddb_cobalt),
        ]);
    }

    /** Save or clear the user's personal D&D Beyond cobalt token. */
    public function ddb(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ddb_cobalt' => ['nullable', 'string', 'max:4000'],
        ]);

        $request->user()->update([
            'ddb_cobalt' => filled($data['ddb_cobalt'] ?? null) ? $data['ddb_cobalt'] : null,
        ]);

        return Redirect::route('profile.edit')->with(
            'success',
            filled($data['ddb_cobalt'] ?? null) ? 'D&D Beyond key saved.' : 'D&D Beyond key removed.',
        );
    }

    /** Upload a new profile image. Uppy posts the file here and expects JSON. */
    public function avatar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:'.implode(',', config('media.accept')), 'max:'.config('media.max_size')],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first('file') ?: 'That image could not be uploaded.'], 422);
        }

        $user = $request->user();
        $disk = config('media.disk');
        $file = $request->file('file');

        // The new avatar replaces the old one, so only the size difference counts against the quota.
        if (! $user->canStore((int) $file->getSize() - (int) $user->avatar_size)) {
            return response()->json(['message' => 'Storage limit reached — delete some media or upgrade your plan for more space.'], 422);
        }

        if (filled($user->avatar_path)) {
            Storage::disk($disk)->delete($user->avatar_path);
        }

        $user->avatar_path = $file->store('avatars', $disk);
        $user->avatar_size = (int) $file->getSize();
        $user->save();

        return response()->json(['avatar_url' => $user->fresh()->avatar_url]);
    }

    /** Remove the user's profile image. */
    public function removeAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (filled($user->avatar_path)) {
            Storage::disk(config('media.disk'))->delete($user->avatar_path);
            $user->avatar_path = null;
            $user->avatar_size = 0;
            $user->save();
        }

        return Redirect::route('profile.edit')->with('success', 'Profile image removed.');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
