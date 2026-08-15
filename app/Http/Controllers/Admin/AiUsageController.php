<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModelPrice;
use App\Models\AiSetting;
use App\Models\AiUsageEvent;
use App\Models\Recap;
use App\Models\World;
use App\Support\AiModels;
use App\Support\AiUsageType;
use App\Support\CreditWeights;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Admin analytics for AI usage and its cost to the business, overall and per world. */
class AiUsageController extends Controller
{
    /** Human labels for the feature tags recorded on each call. */
    private const FEATURES = [
        'assistant_ask' => 'Assistant chat',
        'assistant_draft' => 'Entry draft',
        'session_writeup' => 'Session write-up',
        'session_recap' => 'Session recap',
        'generator' => 'Content generators',
        'compendium_draft' => 'Compendium drafting',
        'compendium_admin' => 'Admin library drafting',
    ];

    /** How the breakdown table can be sliced. */
    private const GROUPS = ['type' => 'Content type', 'feature' => 'Prompt style', 'model' => 'Model'];

    /** Selectable look-back windows, in days (null = all time). */
    private const RANGES = ['7' => 7, '30' => 30, '90' => 90, 'all' => null];

    public function index(Request $request): Response
    {
        $range = array_key_exists((string) $request->query('range'), self::RANGES)
            ? (string) $request->query('range')
            : '30';
        $days = self::RANGES[$range];
        $since = $days !== null ? now()->subDays($days) : null;

        $group = array_key_exists((string) $request->query('group'), self::GROUPS)
            ? (string) $request->query('group')
            : 'type';

        $creditCents = in_array((int) $request->query('credit'), CreditWeights::CREDIT_CENT_PRESETS, true)
            ? (int) $request->query('credit')
            : CreditWeights::CREDIT_CENTS;

        $recapRate = in_array((int) $request->query('recapRate'), CreditWeights::RECAP_RATE_PRESETS, true)
            ? (int) $request->query('recapRate')
            : CreditWeights::RECAP_CREDITS_PER_HOUR;

        $base = fn (): Builder => AiUsageEvent::query()
            ->when($since, fn (Builder $query) => $query->where('created_at', '>=', $since));

        return Inertia::render('Admin/AiUsage', [
            'range' => $range,
            'totals' => $this->totals($base()),
            'allTimeCost' => $this->usd((int) AiUsageEvent::query()->sum('cost_nanos')),
            'group' => $group,
            'groups' => collect(self::GROUPS)->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'breakdown' => $this->breakdown($base(), $group),
            'creditCents' => $creditCents,
            'creditPresets' => CreditWeights::CREDIT_CENT_PRESETS,
            'creditReview' => $this->creditReview($base(), $creditCents),
            'recapRate' => $recapRate,
            'recapRatePresets' => CreditWeights::RECAP_RATE_PRESETS,
            'recapReview' => $this->recapReview($recapRate, $creditCents),
            'byWorld' => $this->byWorld($base()),
            'daily' => $this->daily($base()),
            'prices' => AiModelPrice::query()->orderBy('model')->get()
                ->map(fn (AiModelPrice $price) => [
                    'id' => $price->id,
                    'model' => $price->model,
                    'input_price' => $price->input_price,
                    'output_price' => $price->output_price,
                ])->all(),
            'settings' => [
                'default_model' => AiSetting::current()->default_model,
                'assistant_name' => AiSetting::current()->assistant_name,
            ],
            'availableModels' => AiModels::available(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'default_model' => ['required', 'string', 'in:'.implode(',', AiModels::available())],
            'assistant_name' => ['required', 'string', 'max:40'],
        ]);

        AiSetting::current()->update($data);

        return back()->with('success', 'AI settings saved.');
    }

    public function updatePricing(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'prices' => ['required', 'array', 'max:50'],
            'prices.*.id' => ['required', 'integer', 'exists:ai_model_prices,id'],
            'prices.*.input_price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'prices.*.output_price' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        foreach ($data['prices'] as $row) {
            AiModelPrice::query()->whereKey($row['id'])->update([
                'input_price' => (int) $row['input_price'],
                'output_price' => (int) $row['output_price'],
            ]);
        }

        return back()->with('success', 'Prices updated.');
    }

    /** @return array{calls: int, input_tokens: int, output_tokens: int, cost: string} */
    private function totals(Builder $query): array
    {
        $row = $query->selectRaw(
            'COUNT(*) as calls, COALESCE(SUM(input_tokens), 0) as input_tokens, '
            .'COALESCE(SUM(output_tokens), 0) as output_tokens, COALESCE(SUM(cost_nanos), 0) as cost'
        )->first();

        return [
            'calls' => (int) $row->calls,
            'input_tokens' => (int) $row->input_tokens,
            'output_tokens' => (int) $row->output_tokens,
            'cost' => $this->usd((int) $row->cost),
        ];
    }

    /**
     * The breakdown table for the selected slice: by content type, prompt style (feature), or model.
     *
     * @return list<array{label: string, calls: int, input_tokens: int, output_tokens: int, cost: string, avg_cost: string}>
     */
    private function breakdown(Builder $query, string $group): array
    {
        return match ($group) {
            'feature' => $this->byColumn($query, 'feature', fn (string $value): string => self::FEATURES[$value] ?? $value),
            'model' => $this->byColumn($query, 'model', fn (string $value): string => $value),
            default => $this->byType($query),
        };
    }

    /**
     * Aggregate usage grouped by a single whitelisted column, labelling each bucket via $label.
     *
     * @param  callable(string): string  $label
     * @return list<array{label: string, calls: int, input_tokens: int, output_tokens: int, cost: string, avg_cost: string}>
     */
    private function byColumn(Builder $query, string $column, callable $label): array
    {
        return $query->selectRaw(
            "{$column} as bucket, COUNT(*) as calls, COALESCE(SUM(input_tokens), 0) as input_tokens, "
            .'COALESCE(SUM(output_tokens), 0) as output_tokens, COALESCE(SUM(cost_nanos), 0) as cost'
        )
            ->groupBy($column)
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($row): array => [
                'label' => $label((string) $row->bucket),
                'calls' => (int) $row->calls,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost' => $this->usd((int) $row->cost),
                'avg_cost' => $this->avgUsd((int) $row->cost, (int) $row->calls),
            ])
            ->all();
    }

    /**
     * Usage grouped by the kind of content produced (Recap, Location, Monster, …), derived from each
     * event's feature + detail. This is the view for comparing what different content types actually cost,
     * ahead of any per-type credit pricing.
     *
     * @return list<array{label: string, calls: int, input_tokens: int, output_tokens: int, cost: string, avg_cost: string}>
     */
    private function byType(Builder $query): array
    {
        $rows = $query->selectRaw(
            'feature, detail, COUNT(*) as calls, COALESCE(SUM(input_tokens), 0) as input_tokens, '
            .'COALESCE(SUM(output_tokens), 0) as output_tokens, COALESCE(SUM(cost_nanos), 0) as cost'
        )
            ->groupBy('feature', 'detail')
            ->get();

        /** @var array<string, array{type: string, calls: int, input_tokens: int, output_tokens: int, cost_nanos: int}> $grouped */
        $grouped = [];
        foreach ($rows as $row) {
            $label = AiUsageType::label((string) $row->feature, $row->detail);
            $grouped[$label] ??= ['type' => $label, 'calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_nanos' => 0];
            $grouped[$label]['calls'] += (int) $row->calls;
            $grouped[$label]['input_tokens'] += (int) $row->input_tokens;
            $grouped[$label]['output_tokens'] += (int) $row->output_tokens;
            $grouped[$label]['cost_nanos'] += (int) $row->cost;
        }

        return collect($grouped)
            ->sortByDesc('cost_nanos')
            ->map(fn (array $group): array => [
                'label' => $group['type'],
                'calls' => $group['calls'],
                'input_tokens' => $group['input_tokens'],
                'output_tokens' => $group['output_tokens'],
                'cost' => $this->usd($group['cost_nanos']),
                'avg_cost' => $this->avgUsd($group['cost_nanos'], $group['calls']),
            ])
            ->values()
            ->all();
    }

    /**
     * The credit-weighting review: the proposed per-type credit schedule at a given credit price, plus the
     * blended margin those weights would have earned against this period's real AI cost. Read-only — this
     * models pricing; it does not change how credits are actually spent.
     *
     * @return array{
     *     credit_value: string,
     *     schedule: list<array{type: string, credits: int, price: string}>,
     *     usage: array{credits: int, revenue: string, cost: string, markup: string},
     * }
     */
    private function creditReview(Builder $query, int $creditCents): array
    {
        $rows = $query->selectRaw('feature, detail, COUNT(*) as calls, COALESCE(SUM(cost_nanos), 0) as cost')
            ->groupBy('feature', 'detail')
            ->get();

        $credits = 0;
        $costNanos = 0;
        foreach ($rows as $row) {
            $credits += CreditWeights::for(AiUsageType::label((string) $row->feature, $row->detail)) * (int) $row->calls;
            $costNanos += (int) $row->cost;
        }

        $revenueNanos = $credits * $creditCents * 10_000_000;

        $schedule = collect(CreditWeights::all())
            ->map(fn (int $weight, string $type): array => [
                'type' => $type,
                'credits' => $weight,
                'price' => $this->usd($weight * $creditCents * 10_000_000),
            ])
            ->sortByDesc('credits')
            ->values()
            ->all();

        return [
            'credit_value' => $this->usd($creditCents * 10_000_000),
            'schedule' => $schedule,
            'usage' => [
                'credits' => $credits,
                'revenue' => $this->usd($revenueNanos),
                'cost' => $this->usd($costNanos),
                'markup' => $this->markup($revenueNanos, $costNanos),
            ],
        ];
    }

    /**
     * The recap pricing model, billed by audio hour (as GM Assistant does) rather than a flat weight: the
     * total audio uploaded, what a session of a given length costs at the chosen per-hour rate, and a
     * reference against GM Assistant's ~$0.72/hour headline. Read-only.
     *
     * @return array{
     *     per_hour: int, price_per_hour: string, gm_price_per_hour: string,
     *     total_hours: string, total_credits: int, total_price: string,
     *     examples: list<array{label: string, credits: int, price: string}>,
     * }
     */
    private function recapReview(int $recapRate, int $creditCents): array
    {
        $totalSeconds = (int) Recap::query()->sum('duration_seconds');

        $example = fn (string $label, int $seconds): array => [
            'label' => $label,
            'credits' => CreditWeights::recapCredits($seconds, $recapRate),
            'price' => $this->usd(CreditWeights::recapCredits($seconds, $recapRate) * $creditCents * 10_000_000),
        ];

        $totalCredits = CreditWeights::recapCredits($totalSeconds, $recapRate);

        return [
            'per_hour' => $recapRate,
            'price_per_hour' => $this->usd($recapRate * $creditCents * 10_000_000),
            'gm_price_per_hour' => '$0.72',
            'total_hours' => number_format($totalSeconds / 3600, 1),
            'total_credits' => $totalCredits,
            'total_price' => $this->usd($totalCredits * $creditCents * 10_000_000),
            'examples' => [
                $example('1 hour', 3600),
                $example('2.5 hours', 9000),
                $example('5 hours', 18000),
            ],
        ];
    }

    /** The revenue-to-cost multiple as an "N×" string, or "—" when there is no cost to compare against. */
    private function markup(int $revenueNanos, int $costNanos): string
    {
        if ($costNanos === 0) {
            return '—';
        }

        return BigInteger::of($revenueNanos)->toBigDecimal()->dividedBy($costNanos, 1, RoundingMode::HalfUp).'×';
    }

    /** @return list<array{world_id: ?int, name: string, calls: int, cost: string, cost_nanos: int}> */
    private function byWorld(Builder $query): array
    {
        $rows = $query->selectRaw('world_id, COUNT(*) as calls, COALESCE(SUM(cost_nanos), 0) as cost')
            ->groupBy('world_id')
            ->orderByDesc('cost')
            ->limit(20)
            ->get();

        $names = World::query()->whereIn('id', $rows->pluck('world_id')->filter()->all())
            ->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'world_id' => $row->world_id !== null ? (int) $row->world_id : null,
            'name' => $row->world_id !== null ? ($names[$row->world_id] ?? 'Deleted world') : 'Admin / global',
            'calls' => (int) $row->calls,
            'cost' => $this->usd((int) $row->cost),
            'cost_nanos' => (int) $row->cost,
        ])->all();
    }

    /** @return list<array{day: string, calls: int, cost_nanos: int}> */
    private function daily(Builder $query): array
    {
        // DATE() is supported by both SQLite and Postgres, so the grouping stays engine-agnostic.
        return $query->selectRaw('DATE(created_at) as day, COUNT(*) as calls, COALESCE(SUM(cost_nanos), 0) as cost')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'calls' => (int) $row->calls,
                'cost_nanos' => (int) $row->cost,
            ])
            ->all();
    }

    /** Format a nanodollar total (1e-9 USD) as a USD string. 1 cent = 1e7 nanodollars; round half up. */
    private function usd(int $nanos): string
    {
        $cents = BigInteger::of($nanos)->plus(5_000_000)->quotient(10_000_000)->toInt();

        return Money::ofMinor($cents, 'USD')->formatToLocale('en_US');
    }

    /** Cost per call as a USD string to a tenth-of-a-cent, so cheap content types stay distinguishable. */
    private function avgUsd(int $costNanos, int $calls): string
    {
        if ($calls === 0) {
            return '$0.0000';
        }

        $perCallDollars = BigInteger::of($costNanos)
            ->toBigDecimal()
            ->dividedBy(BigInteger::of($calls)->multipliedBy(1_000_000_000), 4, RoundingMode::HalfUp);

        return '$'.$perCallDollars;
    }
}
