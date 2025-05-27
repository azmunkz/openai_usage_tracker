(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.chartRender = {
    attach: function (context, settings) {

      if (typeof Chart === 'undefined') {
        return;
      }

      const labels = drupalSettings.openaiUsageChart?.labels || [];
      const data = drupalSettings.openaiUsageChart?.tokens || [];

      if (!labels.length || !data.length) return;

      const ctx = document.getElementById("dailyTokenChart");
      if (!ctx) return;

      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: "Tokens per Day",
            data: data,
            backgroundColor: "rgba(99, 102, 241, 0.6)",
            borderRadius: 4
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: false }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: { precision: 0 }
            }
          }
        }
      });
    }
  };
})(jQuery, Drupal, drupalSettings);
