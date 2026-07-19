<?php

declare(strict_types=1);

namespace App\Actions\Reports\Concerns;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Shared daily/monthly/quarterly/yearly period bucketing used by every Reports trend action.
 */
trait BucketsIntoPeriods
{
    private const array GRANULARITIES = ['daily', 'monthly', 'quarterly', 'yearly'];

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
            'daily' => $from->copy()->startOfDay(),
            'monthly' => $from->copy()->startOfMonth(),
            'quarterly' => $from->copy()->startOfQuarter(),
            'yearly' => $from->copy()->startOfYear(),
            default => throw new InvalidArgumentException('Invalid granularity.'),
        };

        while ($cursor->lte($to)) {
            $label = match ($granularity) {
                'daily' => $cursor->format('M j, Y'),
                'monthly' => $cursor->format('M Y'),
                'quarterly' => 'Q'.$cursor->quarter.' '.$cursor->format('Y'),
                'yearly' => $cursor->format('Y'),
                default => throw new InvalidArgumentException('Invalid granularity.'),
            };

            $end = match ($granularity) {
                'daily' => $cursor->copy()->endOfDay(),
                'monthly' => $cursor->copy()->endOfMonth(),
                'quarterly' => $cursor->copy()->endOfQuarter(),
                'yearly' => $cursor->copy()->endOfYear(),
                default => throw new InvalidArgumentException('Invalid granularity.'),
            };

            $boundaries[] = [
                'label' => $label,
                'end' => $end->greaterThan($to) ? $to->copy() : $end,
            ];

            $cursor = match ($granularity) {
                'daily' => $cursor->copy()->addDay(),
                'monthly' => $cursor->copy()->addMonth(),
                'quarterly' => $cursor->copy()->addQuarter(),
                'yearly' => $cursor->copy()->addYear(),
                default => throw new InvalidArgumentException('Invalid granularity.'),
            };
        }

        return $boundaries;
    }
}
