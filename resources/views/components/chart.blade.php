@props([
    'type' => 'doughnut',
    'title' => '',
    'clickEvent' => ''
])

<div class="w-full relative rounded-xl border border-neutral-200 dark:border-neutral-700 p-4 bg-white dark:bg-neutral-800 shadow-sm">
    <div class="h-64 relative">
        <div id="{{ Str::slug($title) }}-legend-container" class="mb-4"></div>
        <canvas id="{{ Str::slug($title) }}"></canvas>
    </div>
</div>

@script
<script>
    const ctx = document.getElementById('{{ Str::slug($title) }}');
    let chartObj = null;

    // Use functions to get fresh data from Livewire without making the chart object itself reactive
    const getData = () => ({
        labels: $wire.chart_labels,
        values: $wire.chart_values,
        colors: $wire.chart_colors,
        tooltips: $wire.chart_tooltip_labels,
        ids: $wire.chart_ids
    });

    function initChart() {
        const data = getData();
        
        if (chartObj) {
            chartObj.destroy();
        }

        if (!data.labels || data.labels.length === 0) {
            return;
        }

        chartObj = new Chart(ctx, {
            type: '{{ $type }}',
            data: {
                labels: data.labels,
                datasets: [{
                    label: '{!! $title !!}',
                    data: data.values,
                    backgroundColor: data.colors,
                    borderWidth: 1,
                    borderColor: 'transparent'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onClick: (event, elements) => {
                    if (elements.length > 0 && '{{ $clickEvent }}') {
                        const index = elements[0].index;
                        const data = getData();
                        const id = data.ids[index];
                        
                        if ('{{ $clickEvent }}' === 'chart-clicked') {
                             $wire.handleChartClick(id);
                        } else {
                             $wire.dispatch('{{ $clickEvent }}', { categoryId: id });
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const data = getData();
                                return ' ' + context.label + ': ' + data.tooltips[context.dataIndex];
                            }
                        }
                    }
                },
                cutout: '60%'
            }
        });
    }

    initChart();

    $wire.on("refresh-chart", () => {
        const data = getData();
        
        if (!chartObj) {
            initChart();
            return;
        }

        if (!data.labels || data.labels.length === 0) {
            chartObj.destroy();
            chartObj = null;
            return;
        }

        chartObj.data.labels = data.labels;
        chartObj.data.datasets[0].data = data.values;
        chartObj.data.datasets[0].backgroundColor = data.colors;
        
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
