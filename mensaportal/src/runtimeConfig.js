const runtimeConfig = window.MM_RUNTIME_CONFIG ?? {};

function normalizeApiBase(value) {
  const trimmed = typeof value === 'string' ? value.trim() : '';

  if (trimmed === '') {
    return '/api';
  }

  return trimmed.endsWith('/') ? trimmed.slice(0, -1) : trimmed;
}

export const API_BASE = normalizeApiBase(runtimeConfig.apiBase);

export function apiUrl(path = '') {
  const normalizedPath = typeof path === 'string' ? path.trim() : '';

  if (normalizedPath === '') {
    return API_BASE;
  }

  if (/^https?:\/\//i.test(normalizedPath)) {
    return normalizedPath;
  }

  if (normalizedPath.startsWith('?')) {
    return `${API_BASE}${normalizedPath}`;
  }

  return normalizedPath.startsWith('/')
    ? `${API_BASE}${normalizedPath}`
    : `${API_BASE}/${normalizedPath}`;
}
