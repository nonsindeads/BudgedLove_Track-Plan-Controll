(() => {
  const dataEl = document.getElementById('hb-expense-breakdown');
  if (!dataEl || typeof Chart === 'undefined') {
    return;
  }

  let payload = null;
  try {
    payload = JSON.parse(dataEl.textContent || '{}');
  } catch (err) {
    return;
  }

  const formatter = new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2,
  });

  const palette = [
    '#0d6efd',
    '#20c997',
    '#ffc107',
    '#dc3545',
    '#6f42c1',
    '#0dcaf0',
    '#adb5bd',
  ];

  const buildChart = (canvasId, data) => {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !data || !Array.isArray(data.labels) || !data.labels.length) {
      return;
    }
    const values = data.values.map((value) => (typeof value === 'number' ? value / 100 : 0));
    new Chart(canvas, {
      type: 'doughnut',
      data: {
        labels: data.labels,
        datasets: [
          {
            data: values,
            backgroundColor: data.labels.map((_, idx) => palette[idx % palette.length]),
            borderWidth: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              boxWidth: 10,
              usePointStyle: true,
            },
          },
          tooltip: {
            callbacks: {
              label(context) {
                return `${context.label}: ${formatter.format(context.parsed)}`;
              },
            },
          },
        },
      },
    });
  };

  buildChart('hb-expense-category', payload.category);
  buildChart('hb-expense-tag', payload.tag);
  buildChart('hb-expense-payee', payload.payee);
})();
