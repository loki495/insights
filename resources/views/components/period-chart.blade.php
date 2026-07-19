@props([
    'type' => 'line',
    'title' => '',
])

{{--
    Sibling to <x-chart> rather than an extension of it: <x-chart> is hard-wired to a single
    dataset off fixed Livewire property names (chart_values/chart_labels/...) and already carries
    click-to-drill logic for the category doughnut. This component instead reads chart_periods
    (labels) + chart_series (an array of {label, values, color}) so callers can plot one or more
    named series (e.g. Income vs Expense, or a single Net Cash line) as a line/area/bar chart.
--}}
<div {{ $attributes->merge(['class' => 'relative rounded-xl border border-neutral-200 dark:border-neutral-700 p-4 bg-white dark:bg-neutral-800 shadow-sm']) }}>
    <div class="h-64 relative">
        <canvas id="{{ Str::slug($title) }}-period-chart"></canvas>
    </div>
</div>

@script
<script>
    let chartObj = null;

    // Use functions to get fresh data from Livewire without making the chart object itself
    // reactive — Chart.js must never be handed a live $wire proxy (it recursively walks it trying
    // to diff for animation, which blows the call stack), so everything is spread into plain
    // arrays first.
    const getData = () => ({
        periods: [...$wire.chart_periods],
        series: $wire.chart_series.map((s) => ({ label: s.label, color: s.color, values: [...s.values] })),
    });

    const isArea = '{{ $type }}' === 'area';
    const chartJsType = isArea ? 'line' : '{{ $type }}';

    function buildDatasets(series) {
        return series.map((s) => ({
            label: s.label,
            data: s.values,
            backgroundColor: isArea ? s.color + '33' : s.color,
            borderColor: s.color,
            borderWidth: 2,
            fill: isArea,
            tension: 0.3,
        }));
    }

    function initChart() {
        const data = getData();

        if (chartObj) {
            chartObj.destroy();
            chartObj = null;
        }

        if (!data.periods || data.periods.length === 0) {
            return;
        }

        // Re-query rather than caching the canvas at script-setup time: the caller conditionally
        // removes/re-adds this element (no data for the current range), leaving a brand new
        // canvas node in place of the old one.
        const ctx = document.getElementById('{{ Str::slug($title) }}-period-chart');

        if (!ctx) {
            return;
        }

        chartObj = new Chart(ctx, {
            type: chartJsType,
            data: {
                labels: data.periods,
                datasets: buildDatasets(data.series),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: data.series.length > 1,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            padding: 16,
                            font: {
                                size: 12,
                            },
                        },
                    },
                },
            },
        });
    }

    initChart();

    $wire.$watch('chart_series', () => {
        const data = getData();

        // wire:key on the wrapping element changes whenever the date range/granularity changes,
        // so Livewire swaps in a brand new (same-id) canvas node rather than reusing the old one.
        // chartObj would still be bound to the old, now-detached canvas in that case.
        if (!chartObj || !chartObj.canvas.isConnected) {
            initChart();
            return;
        }

        if (!data.periods || data.periods.length === 0) {
            chartObj.destroy();
            chartObj = null;
            return;
        }

        chartObj.data.labels = data.periods;
        chartObj.data.datasets = buildDatasets(data.series);
        chartObj.options.plugins.legend.display = data.series.length > 1;

        // Livewire's DOM morph can leave the canvas at a stale size before the ResizeObserver
        // fires again, so force it back to the container size.
        chartObj.resize();
        chartObj.update();
    });

    // Cleanup on destroy
    return () => {
        if (chartObj) {
            chartObj.destroy();
            chartObj = null;
        }
    };
</script>
@endscript
