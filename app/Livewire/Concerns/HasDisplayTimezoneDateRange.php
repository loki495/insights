<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Carbon\Carbon;

/**
 * Bridges a date-range filter's storage/query representation (in config('app.timezone'), which
 * stays UTC — transaction dates are date-only, so retroactively changing app.timezone would
 * reinterpret every existing timestamp) and what a <input type="datetime-local"> should actually
 * show the user: their own wall-clock time (config('app.display_timezone')).
 *
 * Host components keep their real filter properties (e.g. date_from/date_to) untouched for
 * querying, and add a "_local" counterpart bound to the input via wire:model.live, kept in sync
 * with an updated{Property}Local() hook that converts back before assigning the real property.
 */
trait HasDisplayTimezoneDateRange
{
    private function toDisplayTimezone(string $value): string
    {
        return Carbon::parse($value)
            ->setTimezone(config('app.display_timezone'))
            ->format('Y-m-d\TH:i');
    }

    private function fromDisplayTimezone(string $value): string
    {
        return Carbon::parse($value, config('app.display_timezone'))
            ->setTimezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * A year-to-date default range (start of the *current* year through now, never a hardcoded
     * year), computed directly in the display timezone so "start of year" means Jan 1 local time.
     * The query-facing values are converted straight from the full-precision Carbon instances,
     * not through the minute-truncated "_local" strings — those only hold what
     * <input type="datetime-local"> can represent.
     *
     * @return array{from: string, from_local: string, to: string, to_local: string}
     */
    private function defaultYearToDateRange(): array
    {
        $nowLocal = now(config('app.display_timezone'));
        $fromLocal = $nowLocal->copy()->startOfYear();

        return [
            'from' => $fromLocal->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'from_local' => $fromLocal->format('Y-m-d\TH:i'),
            'to' => $nowLocal->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'to_local' => $nowLocal->format('Y-m-d\TH:i'),
        ];
    }
}
