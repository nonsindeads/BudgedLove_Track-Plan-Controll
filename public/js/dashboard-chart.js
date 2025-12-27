(() => {
  const dataEl = document.getElementById('hb-forecast-data');
  const canvas = document.getElementById('hb-forecast-chart');
  if (!dataEl || !canvas || typeof Chart === 'undefined') {
    return;
  }

  let payload = null;
  try {
    payload = JSON.parse(dataEl.textContent || '{}');
  } catch (err) {
    return;
  }

  const labels = Array.isArray(payload.labels) ? payload.labels : [];
  const expected = Array.isArray(payload.expected_balance) ? payload.expected_balance : [];
  const includingOpen = Array.isArray(payload.forecast_including_open) ? payload.forecast_including_open : [];
  const expenses = Array.isArray(payload.cumulative_expenses) ? payload.cumulative_expenses : [];

  const formatter = new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2,
  });

  const formatDate = (isoDate) => {
    const parts = isoDate.split('-');
    if (parts.length !== 3) {
      return isoDate;
    }
    return `${parts[2]}.${parts[1]}.`;
  };

  const drawNegativeZone = {
    id: 'negativeZone',
    beforeDatasetsDraw(chart, _args, opts) {
      const yScale = chart.scales.y;
      if (!yScale || yScale.min >= 0) {
        return;
      }
      const left = chart.chartArea.left;
      const right = chart.chartArea.right;
      const yZero = yScale.getPixelForValue(0);
      const yBottom = chart.chartArea.bottom;
      const ctx = chart.ctx;
      ctx.save();
      ctx.fillStyle = opts.color || 'rgba(220, 53, 69, 0.08)';
      ctx.fillRect(left, yZero, right - left, yBottom - yZero);
      ctx.restore();
    },
  };

  Chart.register(drawNegativeZone);

  new Chart(canvas, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Erwarteter Kontostand',
          data: expected,
          borderColor: '#198754',
          backgroundColor: 'rgba(25, 135, 84, 0.08)',
          tension: 0.25,
          pointRadius: 0,
          borderWidth: 2,
        },
        {
          label: 'Prognose inkl. offene',
          data: includingOpen,
          borderColor: '#0dcaf0',
          backgroundColor: 'rgba(13, 202, 240, 0.08)',
          tension: 0.25,
          pointRadius: 0,
          borderDash: [6, 4],
          borderWidth: 2,
        },
        {
          label: 'Kumulierte Ausgaben',
          data: expenses,
          borderColor: '#dc3545',
          backgroundColor: 'rgba(220, 53, 69, 0.18)',
          fill: true,
          tension: 0.25,
          pointRadius: 0,
          borderWidth: 1.5,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: {
        mode: 'index',
        intersect: false,
      },
      plugins: {
        legend: {
          position: 'bottom',
          labels: {
            usePointStyle: true,
            pointStyle: 'line',
          },
        },
        tooltip: {
          callbacks: {
            title(items) {
              if (!items.length) {
                return '';
              }
              return formatDate(items[0].label);
            },
            label(context) {
              return `${context.dataset.label}: ${formatter.format(context.parsed.y)}`;
            },
          },
        },
        negativeZone: {
          color: 'rgba(220, 53, 69, 0.08)',
        },
      },
      scales: {
        x: {
          ticks: {
            autoSkip: true,
            maxTicksLimit: 8,
            callback(value) {
              const label = labels[value];
              return formatDate(label);
            },
          },
          grid: {
            display: false,
          },
        },
        y: {
          ticks: {
            callback(value) {
              return formatter.format(value);
            },
          },
          grid: {
            color(context) {
              if (context.tick && context.tick.value === 0) {
                return '#adb5bd';
              }
              return 'rgba(0,0,0,0.05)';
            },
          },
        },
      },
    },
  });
})();
