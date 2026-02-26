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

@once
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
@endonce

@script
<script>
    const ctx = document.getElementById('{{ Str::slug($title) }}');
    let chartObj = null;

    const getLabels = () => $wire.chart_labels;
    const getValues = () => $wire.chart_values;
    const getColors = () => $wire.chart_colors;
    const getTooltips = () => $wire.chart_tooltip_labels;
    const getIDs = () => $wire.chart_ids;

    function initChart() {
        if (chartObj) {
            chartObj.destroy();
        }

        chartObj = new Chart(ctx, {
            type: '{{ $type }}',
            data: {
                labels: getLabels(),
                datasets: [{
                    label: '{!! $title !!}',
                    data: getValues(),
                    backgroundColor: getColors(),
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
                        const id = getIDs()[index];
                        $wire.dispatch('{{ $clickEvent }}', { categoryId: id });
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
                                const tooltips = getTooltips();
                                return ' ' + context.label + ': ' + tooltips[context.dataIndex];
                            }
                        }
                    }
                },
                cutout: '60%' // For doughnut feel
            }
        });
    }

    initChart();

    $wire.on("refresh-chart", () => {
        if (!chartObj) {
            initChart();
            return;
        }

        chartObj.data.labels = getLabels();
        chartObj.data.datasets[0].data = getValues();
        chartObj.data.datasets[0].backgroundColor = getColors();
        
        chartObj.update();
    });

    // Cleanup on destroy
    return () => {
        if (chartObj) {
            chartObj.destroy();
        }
    };
</script>
@endscript
