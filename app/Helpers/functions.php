<?php

declare(strict_types=1);
use App\Services\Plaid\PlaidService;
use Carbon\Carbon;

if (! function_exists('plaid')) {
    function plaid($force_environment = ''): PlaidService
    {
        $environment = $force_environment ?: config('plaid.environment');

        return app(PlaidService::class, ['environment' => $environment]);
    }

    function currency($amount = null, $currency = 'USD', ?bool $flat = false): string
    {
        if ($amount === null) {
            return '';
        }

        match ($currency) {
            'USD' => $symbol = '$',
        };

        $color = 'zinc-700';
        $darkColor = 'white';
        if ($amount < 0) {
            $amount *= -1;
            $symbol = '-'.$symbol;
            $color = 'red-700';
            $darkColor = 'red-400';
        }

        if ($flat) {
            return $symbol.number_format($amount, 2, '.', ',');
        }

        return '<span class="text-'.$color.' dark:text-'.$darkColor.'">'.$symbol.number_format($amount, 2, '.', ',').'</span>';
    }

    function carbon($date = 'now'): Carbon
    {
        return Carbon::parse($date);
    }

    function htmlQuotes($string): string
    {
        $result = trim($string);
        $result = str_replace('"', '&quot;', $result);
        $result = str_replace("'", '&apos;', $result);

        return $result;
    }

    function upsertPlaidCategory(array $path, string $plaidId, array $pf): App\Models\OriginalCategory
    {
        $parentId = null;

        foreach ($path as $index => $segment) {
            if (! is_string($segment) || $segment === '') {
                continue; // guard against weird Plaid data
            }

            $isLeaf = $index === array_key_last($path);

            /** @var OriginalCategory $category */
            $category = App\Models\OriginalCategory::firstOrCreate(
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

        return $category;
    }
}
