<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\CampaignInviteMail;
use App\Models\Campaign;
use App\Models\CampaignInvite;
use App\Models\CampaignMember;
use App\Support\WorldNav;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class MembersController extends Controller
{
    /** GM players & invites page. */
    public function index(Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        return Inertia::render('Players/Index', [
            'world' => WorldNav::for($campaign->world),
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'code' => $campaign->code,
                'slug' => $campaign->slug,
                'invite_url' => url("/join/{$campaign->code}"),
            ],
            'members' => $campaign->members()->with('user:id,name,email')->get()->map(fn (CampaignMember $member) => [
                'id' => $member->id,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'role' => $member->role,
                'joined_at' => $member->created_at?->toFormattedDateString(),
            ]),
            'invites' => $campaign->invites()->where('status', 'pending')->latest()->get()->map(fn (CampaignInvite $invite) => [
                'id' => $invite->id,
                'email' => $invite->email,
                'role' => $invite->role,
            ]),
        ]);
    }

    /** Create + email an invitation. Mail uses the configured mailer (log driver in local dev). */
    public function invite(Request $request, Campaign $campaign)
    {
        $this->authorize('manage', $campaign);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:200'],
            'role' => ['sometimes', 'in:player,co-gm'],
        ]);

        $invite = $campaign->invites()->create([
            'email' => Str::lower($data['email']),
            'role' => $data['role'] ?? 'player',
            'token' => (string) Str::uuid(),
            'status' => 'pending',
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(30),
        ]);

        $acceptUrl = url("/join/{$campaign->code}?invite={$invite->token}");
        Mail::to($invite->email)->send(new CampaignInviteMail($campaign->name, $acceptUrl));

        return back();
    }

    public function revokeInvite(CampaignInvite $invite)
    {
        $this->authorize('manage', $invite->campaign);
        $invite->update(['status' => 'revoked']);

        return back();
    }

    public function removeMember(CampaignMember $member)
    {
        $this->authorize('manage', $member->campaign);
        $member->delete();

        return back();
    }

    /** Join page for /join/{code}. An ?invite=token accepts a direct invitation immediately. */
    public function show(Request $request, string $code)
    {
        $campaign = Campaign::where('code', $code)->firstOrFail();

        if ($token = $request->query('invite')) {
            return $this->accept($request, $campaign, $token);
        }

        abort_if($campaign->world->visibility === 'private', Response::HTTP_NOT_FOUND);

        return Inertia::render('Join/Show', [
            'campaign' => [
                'name' => $campaign->name,
                'code' => $campaign->code,
                'slug' => $campaign->slug,
                'description' => $campaign->description,
            ],
            'alreadyIn' => $campaign->world->user_id === $request->user()->id || $campaign->hasMember($request->user()),
        ]);
    }

    /** Join a non-private campaign by its code. */
    public function join(Request $request, string $code)
    {
        $campaign = Campaign::where('code', $code)->firstOrFail();
        abort_if($campaign->world->visibility === 'private', Response::HTTP_FORBIDDEN);

        return $this->attach($request, $campaign, $campaign->world->defaultJoinRole());
    }

    /** Accept an invitation by token — works even for a private campaign. */
    protected function accept(Request $request, Campaign $campaign, string $token)
    {
        $invite = $campaign->invites()->where('token', $token)->first();
        abort_if(! $invite, Response::HTTP_NOT_FOUND);
        abort_if($invite->status === 'revoked', Response::HTTP_FORBIDDEN, 'This invitation was revoked.');
        abort_if($invite->expires_at && $invite->expires_at->isPast(), Response::HTTP_GONE, 'This invitation has expired.');

        if ($invite->status !== 'accepted') {
            $invite->update(['status' => 'accepted']);
        }

        return $this->attach($request, $campaign, $invite->role);
    }

    /** Create the membership (idempotent) and route the user into the campaign. */
    protected function attach(Request $request, Campaign $campaign, string $role)
    {
        if ($campaign->world->user_id === $request->user()->id) {
            return redirect()->route('campaigns.show', [$campaign->world, $campaign]);
        }

        CampaignMember::firstOrCreate(
            ['campaign_id' => $campaign->id, 'user_id' => $request->user()->id],
            ['role' => $role],
        );

        return redirect("/w/{$campaign->world->slug}");
    }
}
