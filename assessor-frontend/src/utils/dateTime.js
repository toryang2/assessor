export const APP_TIMEZONE = 'Asia/Manila';

export const APP_TIMEZONE_LABEL = 'PHT';

export const APP_TIMEZONE_OFFSET = 'UTC+08:00';

export const formatAppDateTime = (dateValue, options = {}) => {
  if (!dateValue) return 'Never';

  let value = String(dateValue).trim();

  // MySQL datetime coming from WordPress normally has no timezone suffix.
  // Treat these values as Asia/Manila rather than browser-local time.
  if (
    /^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/.test(value)
  ) {
    value = value.replace(' ', 'T') + '+08:00';
  } else if (
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(value)
  ) {
    value += '+08:00';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return dateValue;
  }

  return new Intl.DateTimeFormat('en-US', {
    timeZone: APP_TIMEZONE,
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
    ...options,
  }).format(date);
};

export const formatAppDate = (dateValue, options = {}) => {
  if (!dateValue) return '—';

  let value = String(dateValue).trim();

  if (
    /^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/.test(value)
  ) {
    value = value.replace(' ', 'T') + '+08:00';
  } else if (
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(value)
  ) {
    value += '+08:00';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return dateValue;
  }

  return new Intl.DateTimeFormat('en-US', {
    timeZone: APP_TIMEZONE,
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    ...options,
  }).format(date);
};

export const formatAppTime = (dateValue, options = {}) => {
  if (!dateValue) return '—';

  let value = String(dateValue).trim();

  if (
    /^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/.test(value)
  ) {
    value = value.replace(' ', 'T') + '+08:00';
  } else if (
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/.test(value)
  ) {
    value += '+08:00';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return dateValue;
  }

  return new Intl.DateTimeFormat('en-US', {
    timeZone: APP_TIMEZONE,
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
    ...options,
  }).format(date);
};
