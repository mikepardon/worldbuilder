<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Models\World;

/**
 * Who is looking at a world, and what they are allowed to see. The single source of truth for the
 * "GM sees everything, players see only public content" rule — resolved once at the controller
 * boundary and passed into the query layer so visibility scoping never gets re-derived (or forgotten).
 */
final readonly class Viewer
{
    public function __construct(
        public ?int $userId,
        public bool $isOwner,
        public bool $isMember,
        public bool $isGm,
    ) {}

    /**
     * Resolve the viewer for a world. The owner is the GM unless they have explicitly switched to the
     * player's-eye view (`?as=player`), which lets them preview exactly what players receive.
     */
    public static function for(World $world, ?User $user, bool $asPlayer = false): self
    {
        $isOwner = $user !== null && $world->user_id === $user->id;

        return new self(
            userId: $user?->id,
            isOwner: $isOwner,
            isMember: $user !== null && $world->hasMember($user),
            isGm: $isOwner && ! $asPlayer,
        );
    }

    public function authed(): bool
    {
        return $this->userId !== null;
    }

    /** Whether this viewer may see private (GM-only) content. */
    public function seesPrivate(): bool
    {
        return $this->isGm;
    }
}
