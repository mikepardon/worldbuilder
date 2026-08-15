<?php

declare(strict_types=1);

namespace App\Models;

use App\Jobs\AnalyseRecap;
use App\Jobs\TranscribeRecap;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The post-play analysis of a {@see Session}, built from an uploaded audio recording: the raw transcript
 * plus AI-derived recap variants, memorable moments, a scene outline, and the entities that appeared.
 * One per session. Built in the background by {@see TranscribeRecap} then
 * {@see AnalyseRecap}; distinct from the session's own prep document.
 *
 * @property-read Session $session
 * @property-read User|null $user
 * @property-read Collection<int, RecapEntity> $entities
 */
class Recap extends Model
{
    /** Memorable-moment flavours a GM can tag on a highlight. */
    public const MOMENT_TYPES = ['funny', 'epic', 'story', 'combat', 'roleplay', 'twist'];

    /** Entity kinds the analysis may tag, mirroring the compendium/document kinds we can import into. */
    public const ENTITY_TYPES = ['npc', 'location', 'faction', 'item', 'monster', 'spell'];

    protected $fillable = [
        'session_id', 'user_id', 'disk', 'path', 'transcription_request_id', 'original_name',
        'duration_seconds', 'size_bytes', 'detail_level', 'status', 'published_at', 'rating', 'transcript', 'recap_full', 'recap_short',
        'recap_stylized', 'moments', 'outline', 'next_steps', 'facts', 'share_token', 'error',
    ];

    protected $casts = [
        'session_id' => 'int',
        'user_id' => 'int',
        'duration_seconds' => 'int',
        'size_bytes' => 'int',
        'rating' => 'int',
        'published_at' => 'datetime',
        'moments' => 'array',
        'outline' => 'array',
        'next_steps' => 'array',
        'facts' => 'array',
    ];

    /** Whether players may see this finished recap: the GM published it, or the world auto-publishes. */
    public function isPublishedFor(World $world): bool
    {
        return $this->published_at !== null || $world->recapAutoPublish();
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }

    public function markStatus(string $status): void
    {
        $this->update(['status' => $status]);
    }

    public function markFailed(string $message): void
    {
        $this->update(['status' => 'failed', 'error' => $message]);
    }

    /** @return HasMany<RecapEntity, $this> */
    public function entities(): HasMany
    {
        return $this->hasMany(RecapEntity::class)->chaperone();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
