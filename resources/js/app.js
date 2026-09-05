import Chart from 'chart.js/auto';

// Exposed globally so the inline <script> blocks in period-chart.blade.php
// and chart.blade.php (Vite doesn't process inline blade <script> tags, so
// they can't `import` directly) keep working with `new Chart(...)`
// unchanged, same as when this came from the CDN's UMD build.
window.Chart = Chart;
