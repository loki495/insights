<div class="resizable-box w-full relative rounded-xl border border-neutral-200 dark:border-neutral-700 p-4">
    <div {{ $attributes->merge(['class' => 'relative w-full h-full inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20']) }} >
        <div id="{{ Str::slug($title) }}-legend-container"></div>
        <canvas id="{{ Str::slug($title) }}"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
@if ($tooltipLabels ?? false)

if (!window.tooltip_labels) window.tooltip_labels = {};
window.tooltip_labels['{{ Str::slug($title) }}'] = {!! $tooltipLabels !!};

if (!window.dataIDs) window.dataIDs = {};
window.dataIDs['{{ Str::slug($title) }}'] = {!! $dataIDs!!};
@endif

document.addEventListener('livewire:init', function () {
    const ctx = document.getElementById('{{ Str::slug($title) }}');

    if (!window.charts) {
        window.charts = {};
    }

    const chart = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: {!! $labels !!},
        datasets: [{
            label: '{!! $title !!}',
            data: {!! $values !!},
            borderWidth: 1
          }]
    },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            events: ['click', 'mousemove'],
            onClick: (event, elements) => clickHandler('{{ $title }}', event, elements),
            plugins: {
                htmlLegend: {
                    // ID of the container to put the legend in
                    containerID: '{{ Str::slug($title) }}-legend-container',
                },
                legend: {
                    display: false,
                },
                @if ($tooltipLabels ?? false)

                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return ' ' + window.tooltip_labels['{{ Str::slug($title) }}'][context.dataIndex];
                        },
                        labelTextColor: function(context) {
                            if (context.parsed < 0) {
                                return '#ff0000';
                            }
                        },
                    },
                },
                @endif
            },
        },
        plugins: ['htmlLegendPlugin'],
    });

    window.charts['{{ $title }}'] = chart;
});
</script>

@once
<script>
function clickHandler(title, evt, elements) {
    const chart = window.charts[title];
    if (elements.length > 0) {
        const index = elements[0].index;

        Livewire.dispatch('{{ $clickEvent ?? ''}}', { category: window.dataIDs[title][index] });
    }
}
const getOrCreateLegendList = (chart, id) => {
    debugger;
    const legendContainer = document.getElementById(id);
    let listContainer = legendContainer.querySelector('ul');

    if (!listContainer) {
        listContainer = document.createElement('ul');
        listContainer.style.display = 'flex';
        listContainer.style.flexDirection = 'row';
        listContainer.style.margin = 0;
        listContainer.style.padding = 0;

        legendContainer.appendChild(listContainer);
    }

    return listContainer;
}

const htmlLegendPlugin = {
  id: 'htmlLegend',
  afterUpdate(chart, args, options) {
        debugger;
    const ul = getOrCreateLegendList(chart, options.containerID);

    // Remove old legend items
    while (ul.firstChild) {
      ul.firstChild.remove();
    }

    // Reuse the built-in legendItems generator
    const items = chart.options.plugins.legend.labels.generateLabels(chart);

    items.forEach(item => {
      const li = document.createElement('li');
      li.style.alignItems = 'center';
      li.style.cursor = 'pointer';
      li.style.display = 'flex';
      li.style.flexDirection = 'row';
      li.style.marginLeft = '10px';

      li.onclick = () => {
        const {type} = chart.config;
        if (type === 'pie' || type === 'doughnut') {
          // Pie and doughnut charts only have a single dataset and visibility is per item
          chart.toggleDataVisibility(item.index);
        } else {
          chart.setDatasetVisibility(item.datasetIndex, !chart.isDatasetVisible(item.datasetIndex));
        }
        chart.update();
      };

      // Color box
      const boxSpan = document.createElement('span');
      boxSpan.style.background = item.fillStyle;
      boxSpan.style.borderColor = item.strokeStyle;
      boxSpan.style.borderWidth = item.lineWidth + 'px';
      boxSpan.style.display = 'inline-block';
      boxSpan.style.flexShrink = 0;
      boxSpan.style.height = '20px';
      boxSpan.style.marginRight = '10px';
      boxSpan.style.width = '20px';

      // Text
      const textContainer = document.createElement('p');
      textContainer.style.color = item.fontColor;
      textContainer.style.margin = 0;
      textContainer.style.padding = 0;
      textContainer.style.textDecoration = item.hidden ? 'line-through' : '';

      const text = document.createTextNode(item.text);
      textContainer.appendChild(text);

      li.appendChild(boxSpan);
      li.appendChild(textContainer);
      ul.appendChild(li);
    });
  }
};
</script>
<style>
    .resizable-box {
      border: 2px solid #555;
      resize: both;
      overflow: auto;
      position: relative;
    }

    .grabber {
      position: absolute;
      bottom: 2px;
      left: 50%;
      transform: translateX(-50%);
      width: 40px;
      height: 10px;
      background: #aaa;
      border-radius: 5px;
      cursor: ns-resize;
    }
  </style>
@endonce
