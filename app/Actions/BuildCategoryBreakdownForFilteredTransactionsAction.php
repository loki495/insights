<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Buckets a filtered transaction query into one chart segment per top-level category (or, when a
 * specific category is currently selected, its immediate children) for the transaction list's
 * drill-down category chart.
 *
 * Deliberately not scoped to Transaction::reportable() — this chart shows everything matching the
 * current filters, transfers included; that's what categories are for. Excluding transfers from
 * aggregate income/expense totals is the dedicated Reports pages' job (see
 * BuildIncomeExpenseTrendAction), not this one.
 */
final class BuildCategoryBreakdownForFilteredTransactionsAction
{
    /**
     * @param  Builder<Transaction>  $query
     * @return array{ids: array<int, int>, labels: array<int, string>, values: array<int, float>, colors: array<int, string>, tooltipLabels: array<int, string>}
     */
    public static function run(Builder $query, ?int $categoryId): array
    {
        $transactions = $query
            ->clone()
            ->with(['categories' => function ($q): void {
                $q->select('categories.id', 'categories.name', 'categories.color', 'categories.parent_id');
            }])
            ->get();

        $chart_data = [];
        $total_sum = 0;

        // Pre-fetch categories into a map for fast parent lookup
        $all_categories = Category::all()->keyBy('id');

        foreach ($transactions as $transaction) {
            $categories = $transaction->categories;

            if ($categories->isEmpty()) {
                $id = 0;
                $name = 'Uncategorized';
                $color = '#9ca3af';

                if (! isset($chart_data[$id])) {
                    $chart_data[$id] = ['id' => $id, 'label' => $name, 'color' => $color, 'total' => 0];
                }
                $chart_data[$id]['total'] += $transaction->amount;
                $total_sum += abs($transaction->amount);

                continue;
            }

            foreach ($categories as $category) {
                $current_filtered_id = $categoryId ?: 0;
                $target = null;

                if ($current_filtered_id === 0) {
                    // Find top level ancestor
                    $target = $category;
                    while ($target && $target->parent_id != 0) {
                        $target = $all_categories->get($target->parent_id);
                    }
                } else {
                    // Is this category a descendant of the current filter?
                    $temp = $category;
                    $path = [];
                    while ($temp) {
                        $path[] = $temp->id;
                        if ($temp->id == $current_filtered_id) {
                            break;
                        }
                        $temp = $temp->parent_id ? $all_categories->get($temp->parent_id) : null;
                    }

                    if (! $temp || $temp->id != $current_filtered_id) {
                        continue; // Not under current filter
                    }

                    // Find the child of current_filtered_id in this path
                    if ($category->id == $current_filtered_id) {
                        $target = $category;
                    } else {
                        // The path is [leaf, ..., child_of_filter, filter]
                        // We want child_of_filter
                        $filter_index = array_search($current_filtered_id, $path);
                        $target = $filter_index !== false && $filter_index > 0 ? $all_categories->get($path[$filter_index - 1]) : $category;
                    }
                }

                if ($target) {
                    if (! isset($chart_data[$target->id])) {
                        $chart_data[$target->id] = [
                            'id' => $target->id,
                            'label' => $target->name,
                            'color' => $target->color ?: '#3b82f6',
                            'total' => 0,
                        ];
                    }
                    $chart_data[$target->id]['total'] += $transaction->amount;
                    $total_sum += abs($transaction->amount);
                }

                break; // Categorize by first category
            }
        }

        $chart_data = collect($chart_data)->values();
        $abs_total = $total_sum;

        return [
            'ids' => $chart_data->pluck('id')->toArray(),
            'labels' => $chart_data->pluck('label')->toArray(),
            'values' => $chart_data->pluck('total')->map(fn ($v): float => round(abs($v), 2))->toArray(),
            'colors' => $chart_data->pluck('color')->toArray(),
            'tooltipLabels' => $chart_data->map(function (array $item) use ($abs_total): string {
                $val = abs($item['total']);
                $percent = $abs_total > 0 ? round(($val / $abs_total) * 100, 1) : 0;

                return currency($item['total'], flat: true)." ({$percent}%)";
            })->toArray(),
        ];
    }
}
