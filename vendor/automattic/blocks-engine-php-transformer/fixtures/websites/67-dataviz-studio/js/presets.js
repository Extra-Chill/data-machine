/* =========================================================
   PLOTWEAVER — Gallery presets
   Each preset = a built-in dataset key + a chart config that
   loads into the studio. Used by gallery.html thumbnails and
   "Open in studio" deep-links.
   Exposes window.PW.PRESETS
   ========================================================= */
(function () {
  'use strict';
  const PW = (window.PW = window.PW || {});

  PW.PRESETS = [
    {
      id: 'arr-stacked',
      tag: 'Stacked Bar',
      title: 'Stacked ARR by Quarter',
      desc: 'Three product lines stacked to show total annual recurring revenue growth.',
      dataset: 'quarterly_revenue',
      config: { type: 'bar', x: 'Quarter', series: ['Core Platform', 'Analytics', 'Automation'], palette: 'ocean', title: 'ARR by Product Line ($M)', yLabel: '$M ARR', legendPos: 'top', stacked: true, grid: true, valueFormat: 'currency', sort: 'none', animate: true },
    },
    {
      id: 'temp-line',
      tag: 'Line',
      title: 'Global Temperature Trend',
      desc: 'Decadal land-ocean temperature anomaly versus the 20th-century baseline.',
      dataset: 'global_temp',
      config: { type: 'line', x: 'Year', series: ['Anomaly'], palette: 'sunset', title: 'Temperature Anomaly (°C)', xLabel: 'Year', yLabel: '°C', legendPos: 'none', grid: true, valueFormat: 'number', sort: 'asc', animate: true },
    },
    {
      id: 'energy-donut',
      tag: 'Donut',
      title: 'Generation Mix',
      desc: 'Share of annual electricity generation by source for a regional grid.',
      dataset: 'energy_mix',
      config: { type: 'donut', x: 'Source', series: ['TWh'], palette: 'aurora', title: 'Electricity Generation (TWh)', legendPos: 'right', valueFormat: 'compact', sort: 'desc', animate: true },
    },
    {
      id: 'pop-area',
      tag: 'Stacked Area',
      title: 'Metro Population Growth',
      desc: 'Population of six fast-growing US metros stacked from 1990 to 2020.',
      dataset: 'city_population',
      config: { type: 'area', x: 'City', series: ['1990', '2000', '2010', '2020'], palette: 'forest', title: 'Metro Population (M)', yLabel: 'Millions', legendPos: 'top', stacked: false, grid: true, valueFormat: 'number', sort: 'desc', animate: true },
    },
    {
      id: 'iris-scatter',
      tag: 'Scatter',
      title: 'Iris Petal vs Sepal',
      desc: 'Petal length against sepal length, colored by flower species.',
      dataset: 'iris_sample',
      config: { type: 'scatter', x: 'Sepal Length', series: ['Petal Length'], group: 'Species', palette: 'candy', title: 'Iris: Sepal vs Petal Length', xLabel: 'Sepal Length (cm)', yLabel: 'Petal Length (cm)', legendPos: 'top', grid: true, valueFormat: 'number', animate: true },
    },
    {
      id: 'coffee-radar',
      tag: 'Radar',
      title: 'Coffee Cupping Profile',
      desc: 'Sensory attribute scores compared across three coffee origins.',
      dataset: 'coffee_ratings',
      config: { type: 'radar', x: 'Attribute', series: ['Ethiopia', 'Colombia', 'Sumatra'], palette: 'sunset', title: 'Cupping Scores by Origin', legendPos: 'top', valueFormat: 'number', animate: true },
    },
    {
      id: 'rain-heatmap',
      tag: 'Heatmap',
      title: 'Monthly Rainfall',
      desc: 'Average precipitation per month across five US cities.',
      dataset: 'rainfall_grid',
      config: { type: 'heatmap', x: 'City', series: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], palette: 'ocean', title: 'Avg Rainfall (mm)', legendPos: 'none', valueFormat: 'number', sort: 'none', animate: true },
    },
    {
      id: 'market-grouped',
      tag: 'Grouped Bar',
      title: 'Market Units vs Revenue',
      desc: 'Units sold and revenue side by side for each produce category.',
      dataset: 'fruit_market',
      config: { type: 'bar', x: 'Category', series: ['Units', 'Revenue'], palette: 'candy', title: 'Farmers Market: Units vs Revenue', legendPos: 'top', stacked: false, grid: true, valueFormat: 'number', sort: 'desc', animate: true },
    },
    {
      id: 'iris-hist',
      tag: 'Histogram',
      title: 'Petal Length Distribution',
      desc: 'Frequency distribution of iris petal lengths across the sample.',
      dataset: 'iris_sample',
      config: { type: 'histogram', x: '', series: ['Petal Length'], palette: 'forest', title: 'Petal Length Distribution', xLabel: 'Petal Length (cm)', legendPos: 'none', grid: true, valueFormat: 'number', animate: true },
    },
  ];
})();
