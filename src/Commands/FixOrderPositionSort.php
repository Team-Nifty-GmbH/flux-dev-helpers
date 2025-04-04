<?php

namespace TeamNiftyGmbH\FluxDevHelpers\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixOrderPositionSort extends Command
{
    protected $description = 'Fix the sort order of order positions and recalculate slug positions';

    protected $signature = 'flux-dev:fix-order-positions-sort';

    public function handle(): int
    {
        $this->info('Starting to fix order positions sort order...');

        $orderIds = DB::table('order_positions')
            ->select('order_id')
            ->distinct()
            ->pluck('order_id');

        $progressBar = $this->output->createProgressBar(count($orderIds));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progressBar->start();

        foreach ($orderIds as $orderId) {
            $this->fixSortOrderForOrder($orderId);
            $this->recalculateOrderPositionSlugPositions($orderId);
            $progressBar->advance();
        }

        $progressBar->finish();

        $this->newLine(2);
        $this->info('Sort order fixed and slug positions recalculated successfully!');

        return Command::SUCCESS;
    }

    protected function fixSortOrderForOrder($orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $groups = DB::table('order_positions')
                ->select('parent_id')
                ->where('order_id', $orderId)
                ->groupBy('parent_id')
                ->get();

            foreach ($groups as $group) {
                $positions = DB::table('order_positions')
                    ->where('order_id', $orderId)
                    ->where(function ($query) use ($group): void {
                        if (is_null($group->parent_id)) {
                            $query->whereNull('parent_id');
                        } else {
                            $query->where('parent_id', $group->parent_id);
                        }
                    })
                    ->orderBy('sort_number')
                    ->pluck('id');

                $updates = [];
                $sortNumber = 1;

                foreach ($positions as $positionId) {
                    $updates[] = [
                        'id' => $positionId,
                        'sort_number' => $sortNumber++,
                    ];
                }

                if (! empty($updates)) {
                    $cases = [];
                    $ids = [];

                    foreach ($updates as $update) {
                        $cases[] = "WHEN {$update['id']} THEN {$update['sort_number']}";
                        $ids[] = $update['id'];
                    }

                    $ids = implode(',', $ids);
                    $cases = implode(' ', $cases);

                    DB::statement("
                        UPDATE order_positions
                        SET sort_number = CASE id
                            {$cases}
                        END
                        WHERE id IN ({$ids})
                    ");
                }
            }
        });
    }

    protected function recalculateOrderPositionSlugPositions($orderId): void
    {
        // Update root positions first
        DB::table('order_positions')
            ->where('order_id', $orderId)
            ->whereNull('parent_id')
            ->update([
                'slug_position' => DB::raw('CAST(sort_number AS CHAR)'),
            ]);

        // Then update all children with recursive CTE
        $query = "
        WITH RECURSIVE position_hierarchy AS (
            SELECT
                id,
                parent_id,
                order_id,
                slug_position,
                sort_number,
                0 AS level
            FROM
                order_positions
            WHERE
                order_id = ? AND parent_id IS NULL

            UNION ALL

            SELECT
                c.id,
                c.parent_id,
                c.order_id,
                CONCAT(p.slug_position, '.', c.sort_number) AS slug_position,
                c.sort_number,
                p.level + 1 AS level
            FROM
                position_hierarchy p
            JOIN
                order_positions c ON p.id = c.parent_id AND c.order_id = ?
        )

        UPDATE order_positions op,
               position_hierarchy ph
        SET op.slug_position = ph.slug_position
        WHERE op.id = ph.id
          AND op.order_id = ?
          AND op.parent_id IS NOT NULL;
        ";

        DB::statement($query, [
            $orderId,
            $orderId,
            $orderId,
        ]);
    }
}
