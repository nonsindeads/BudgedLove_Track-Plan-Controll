(() => {
  const dataEl = document.getElementById('hb-forecast-data');
  const amountEl = document.getElementById('hb-afford-amount');
  const dateEl = document.getElementById('hb-afford-date');
  const button = document.getElementById('hb-afford-run');
  const result = document.getElementById('hb-afford-result');
  if (!dataEl || !amountEl || !dateEl || !button || !result) {
    return;
  }

  let payload = {};
  try {
    payload = JSON.parse(dataEl.textContent || '{}');
  } catch (err) {
    return;
  }

  const labels = Array.isArray(payload.labels) ? payload.labels : [];
  const balances = Array.isArray(payload.forecast_including_open) ? payload.forecast_including_open : [];
  if (!labels.length || !balances.length) {
    return;
  }

  const formatter = new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
    minimumFractionDigits: 2,
  });

  const parseAmount = (value) => {
    const normalized = String(value || '')
      .replace(/\s/g, '')
      .replace(/\./g, '')
      .replace(',', '.');
    if (!normalized || Number.isNaN(Number(normalized))) {
      return null;
    }
    return Math.round(Number(normalized) * 100);
  };

  const formatDate = (isoDate) => {
    const parts = String(isoDate || '').split('-');
    if (parts.length !== 3) return isoDate || '';
    return `${parts[2]}.${parts[1]}.${parts[0]}`;
  };

  const setResult = (type, message) => {
    result.className = `alert alert-${type} mt-3 mb-0 small`;
    result.textContent = message;
  };

  const run = () => {
    const amount = parseAmount(amountEl.value);
    const date = dateEl.value || labels[0];
    if (amount === null || amount <= 0) {
      setResult('warning', 'Bitte einen positiven Betrag eingeben.');
      return;
    }

    const startIdx = labels.findIndex((label) => label >= date);
    if (startIdx < 0) {
      setResult('warning', 'Das Datum liegt ausserhalb des dargestellten Forecast-Zeitraums.');
      return;
    }

    const adjusted = balances.map((balance, idx) => idx >= startIdx ? Number(balance || 0) - amount : Number(balance || 0));
    const relevant = adjusted.slice(startIdx);
    const minBalance = Math.min(...relevant);
    const endBalance = adjusted[adjusted.length - 1] || 0;
    const firstNegativeIdx = adjusted.findIndex((balance, idx) => idx >= startIdx && balance < 0);

    if (firstNegativeIdx >= 0) {
      setResult(
        'danger',
        `Nicht sauber leistbar: der Forecast wird am ${formatDate(labels[firstNegativeIdx])} negativ. Tiefster Stand: ${formatter.format(minBalance / 100)}.`
      );
      return;
    }

    setResult(
      'success',
      `Leistbar im aktuellen Forecast. Niedrigster Stand danach: ${formatter.format(minBalance / 100)}. Periodenende: ${formatter.format(endBalance / 100)}.`
    );
  };

  button.addEventListener('click', run);
  amountEl.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      run();
    }
  });
})();
