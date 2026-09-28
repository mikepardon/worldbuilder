<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TalentNode;
use App\Models\TalentWeb;

/**
 * Lays out a node's branch children so the builder places them consistently — the same fan whether a GM
 * grows a branch by hand or the Muse adds one. Children (layout_role 'child') spread evenly on an arc
 * that faces away from the web centre, so a branch reads as growing outward. Only 'child' nodes are
 * arranged; 'ring' nodes keep their cluster circle and 'adjacent'/'master' nodes are placed by hand.
 */
class TalentLayout
{
    /** How far a child sits from its parent. */
    private const BRANCH_RADIUS = 120;

    /** Degrees between adjacent siblings in the fan. */
    private const BRANCH_STEP = 40.0;

    /** How far a cluster master's ring sits from it (matches the ring the cluster is built with). */
    private const RING_RADIUS = 86;

    /**
     * Reposition a parent's children. A cluster master keeps its children as an even ring *inside* its
     * disc (a new child joins the ring, spaced with the rest); any other node fans its children outward
     * as a branch.
     */
    public function arrange(TalentWeb $web, TalentNode $parent): void
    {
        if ($parent->layout_role === 'master') {
            $this->arrangeRing($web, $parent);

            return;
        }

        $children = $web->nodes()
            ->where('parent_node_id', $parent->id)
            ->where('layout_role', 'child')
            ->orderBy('sort')
            ->get();

        if ($children->isEmpty()) {
            return;
        }

        $centre = $this->centre($web);
        $positions = $this->fan($parent->x, $parent->y, $centre['x'], $centre['y'], $children->count());

        foreach ($children->values() as $index => $child) {
            $child->update(['x' => $positions[$index]['x'], 'y' => $positions[$index]['y']]);
        }
    }

    /**
     * Space a master's ring and child nodes evenly around it in a full circle, so an added child sits
     * inside the cluster disc alongside the existing ring rather than floating outside it.
     */
    private function arrangeRing(TalentWeb $web, TalentNode $master): void
    {
        $ring = $web->nodes()
            ->where('parent_node_id', $master->id)
            ->whereIn('layout_role', ['ring', 'child'])
            ->orderBy('sort')
            ->get();

        $count = $ring->count();
        if ($count === 0) {
            return;
        }

        foreach ($ring->values() as $index => $node) {
            $angle = deg2rad($index * (360 / $count) - 90);
            $node->update([
                'x' => (int) round($master->x + cos($angle) * self::RING_RADIUS),
                'y' => (int) round($master->y + sin($angle) * self::RING_RADIUS),
            ]);
        }
    }

    /**
     * A single child's position when it is the next one to fan off a parent — used to give a freshly
     * created node a sensible spot before {@see self::arrange()} evens the whole set out.
     *
     * @return array{x: int, y: int}
     */
    public function nextChildPosition(TalentWeb $web, TalentNode $parent, int $existingChildren): array
    {
        $centre = $this->centre($web);

        return $this->fan($parent->x, $parent->y, $centre['x'], $centre['y'], $existingChildren + 1)[$existingChildren];
    }

    /**
     * Positions for `count` children fanned around a parent, centred on the direction pointing away from
     * the web centre.
     *
     * @return list<array{x: int, y: int}>
     */
    private function fan(int $px, int $py, int $cx, int $cy, int $count): array
    {
        $outward = ($px === $cx && $py === $cy)
            ? -M_PI / 2 // a parent sitting on the centre has no outward direction; grow upward.
            : atan2($py - $cy, $px - $cx);

        $step = deg2rad(self::BRANCH_STEP);
        $start = $outward - $step * ($count - 1) / 2;

        $positions = [];
        for ($i = 0; $i < $count; $i++) {
            $angle = $start + $step * $i;
            $positions[] = [
                'x' => (int) round($px + cos($angle) * self::BRANCH_RADIUS),
                'y' => (int) round($py + sin($angle) * self::BRANCH_RADIUS),
            ];
        }

        return $positions;
    }

    /**
     * @return array{x: int, y: int}
     */
    private function centre(TalentWeb $web): array
    {
        $centre = $web->layout['centre'] ?? [];

        return [
            'x' => (int) ($centre['x'] ?? 1300),
            'y' => (int) ($centre['y'] ?? 1300),
        ];
    }
}
