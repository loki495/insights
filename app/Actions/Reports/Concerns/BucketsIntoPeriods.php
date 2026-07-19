<?php

declare(strict_types=1);

namespace App\Actions\Reports\Concerns;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Shared monthly/quarterly/yearly period bucketing used by every Reports trend action.
 */
trait BucketsIntoPeriods
{
    private const array GRANULARITIES = ['monthly', 'quarterly', 'yearly'];

    private static function assertValidGranularity(string $granularity): void
    {
        if (! in_array($granularity, self::GRANULARITIES, true)) {
            throw new InvalidArgumentException('Invalid granularity.');
        }
    }

    /**
     * @return array<int, array{label: string, end: CarbonInterface}>
     */
    private static function periodBoundaries(CarbonInterface $from, CarbonInterface $to, string $granularity): array
    {
        $boundaries = [];

        $cursor = match ($granularity) {
            'monthly' => $from->copy()->startOfMonth(),
            'quarterly' => $from->copy()->startOfQuarter(),
            'yearly' => $from->copy()->startOfYear(),
        };

        while ($cursor->lte($to)) {
            $label = match ($granularity) {
                'monthly' => $cursor->format('M Y'),
                'quarterly' => 'Q'.$cursor->quarter.' '.$cursor->format('Y'),
                'yearly' => $cursor->format('Y'),
            };

            $end = match ($granularity) {
                'monthly' => $cursor->copy()->endOfMonth(),
                'quarterly' => $cursor->copy()->endOfQuarter(),
                'yearly' => $cursor->copy()->endOfYear(),
            };

            $boundaries[] = [
                'label' => $label,
                'end' => $end->greaterThan($to) ? $to->copy() : $end,
            ];

            $cursor = match ($granularity) {
                'monthly' => $cursor->copy()->addMonth(),
                'quarterly' => $cursor->copy()->addQuarter(),
                'yearly' => $cursor->copy()->addYear(),
            };
        }

        return $boundaries;
    }
}
