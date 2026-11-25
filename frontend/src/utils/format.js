const inrFormatter = new Intl.NumberFormat('en-IN', {
  style: 'currency',
  currency: 'INR',
  maximumFractionDigits: 2,
});

export function formatMoney(value) {
  return inrFormatter.format(Number(value) || 0);
}

export function formatDate(value) {
  if (!value) return '—';
  const [year, month, day] = value.slice(0, 10).split('-').map(Number);
  if (!year || !month || !day) return value;
  return new Intl.DateTimeFormat('en-IN', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(new Date(year, month - 1, day));
}

export function getErrorMessage(error, fallback = 'Something went wrong. Please try again.') {
  const validationErrors = error.response?.data?.errors;
  if (validationErrors) {
    const firstError = Object.values(validationErrors).flat()[0];
    if (firstError) return firstError;
  }

  return error.response?.data?.message || error.message || fallback;
}
