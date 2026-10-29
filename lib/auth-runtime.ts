let runtimeApiToken: string | null = null;

const normalizeToken = (token: unknown): string =>
  typeof token === 'string' ? token.trim() : '';

export const setRuntimeApiToken = (token: string): string => {
  const normalized = normalizeToken(token);
  if (!normalized) throw new Error('A non-empty runtime API token is required.');
  runtimeApiToken = normalized;
  return normalized;
};

export const getRuntimeApiToken = (): string | null => runtimeApiToken;

export const clearRuntimeApiToken = (expectedToken?: string | null): boolean => {
  if (expectedToken !== undefined && runtimeApiToken !== normalizeToken(expectedToken)) return false;
  runtimeApiToken = null;
  return true;
};

export const isExplicitAuthenticationFailure = (status: number, responseCode: string): boolean =>
  status === 401
  && (responseCode === 'AUTHENTICATION_REQUIRED' || responseCode === 'AUTHENTICATION_FAILED');

const bodyToken = (body: RequestInit['body']): string | null => {
  if (!body) return null;
  if (typeof body === 'string') {
    try {
      const params = new URLSearchParams(body);
      const formToken = params.get('api_token') ?? params.get('token') ?? params.get('user_token');
      if (formToken) return normalizeToken(formToken) || null;
    } catch { /* It may be JSON rather than form-encoded. */ }
    try {
      const parsed = JSON.parse(body) as Record<string, unknown>;
      return normalizeToken(parsed.api_token ?? parsed.token ?? parsed.user_token) || null;
    } catch { return null; }
  }
  if (typeof FormData !== 'undefined' && body instanceof FormData) {
    const compatibleBody = body as FormData & {
      get?: (name: string) => FormDataEntryValue | null;
      _parts?: [string, unknown][];
    };
    if (typeof compatibleBody.get === 'function') {
      return normalizeToken(
        compatibleBody.get('api_token') ?? compatibleBody.get('token') ?? compatibleBody.get('user_token')
      ) || null;
    }
    const part = compatibleBody._parts?.find(([name]) =>
      name === 'api_token' || name === 'token' || name === 'user_token'
    );
    return normalizeToken(part?.[1]) || null;
  }
  return null;
};

export const requestUsesRuntimeApiToken = (init: RequestInit | undefined, token: string | null): boolean =>
  Boolean(token && bodyToken(init?.body) === token);
