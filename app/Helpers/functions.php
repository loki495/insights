<?php

declare(strict_types=1);

use App\Models\OriginalCategory;
use App\Services\Plaid\PlaidService;
use Illuminate\Support\Number;

if (! function_exists('plaid')) {
    function plaid(string $force_environment = ''): PlaidService
    {
        $environment = $force_environment ?: config('plaid.environment');

        return app(PlaidService::class, ['environment' => $environment]);
    }

    function currency(int|float|null $amount = null, string $currency = 'USD', ?bool $flat = false): string
    {
        if ($amount === null) {
            return '';
        }

        $formatted = Number::currency($amount, in: $currency);

        if ($flat) {
            return $formatted;
        }

        $color = $amount < 0 ? 'red-700' : 'zinc-700';
        $darkColor = $amount < 0 ? 'red-400' : 'white';

        return '<span class="text-'.$color.' dark:text-'.$darkColor.'">'.$formatted.'</span>';
    }

    function htmlQuotes(string $string): string
    {
        $result = trim($string);
        $result = str_replace('"', '&quot;', $result);

        return str_replace("'", '&apos;', $result);
    }

    /**
     * @param  array<int, mixed>  $path
     * @param  array<string, mixed>  $pf
     */
    function upsertPlaidCategory(array $path, string $plaidId, array $pf): OriginalCategory
    {
        $parentId = null;
        $category = null;

        foreach ($path as $index => $segment) {
            if (! is_string($segment) || $segment === '') {
                continue; // guard against weird Plaid data
            }

            $isLeaf = $index === array_key_last($path);

            $category = OriginalCategory::firstOrCreate(
                [
                    'name' => $segment,
                    'parent_id' => $parentId,
                ],
                [] // don't set leaf data here yet
            );

            // Only apply Plaid + PF data on leaf nodes
            if ($isLeaf) {
                $needsUpdate =
                $category->plaid_id === null ||
                    $category->pf_primary === null ||
                    $category->pf_detailed === null;

                if ($needsUpdate) {
                    $category->update([
                        'plaid_id' => $plaidId,
                        'pf_primary' => $pf['primary'] ?? null,
                        'pf_detailed' => $pf['detailed'] ?? null,
                        'pf_confidence' => $pf['confidence_level'] ?? null,
                    ]);
                }
            }

            $parentId = $category->id;
        }

        if ($category === null) {
            throw new InvalidArgumentException('upsertPlaidCategory(): $path contained no usable segments.');
        }

        return $category;
    }
}
