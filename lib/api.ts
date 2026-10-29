import { Platform } from 'react-native';
import {
  getRuntimeApiToken,
  isExplicitAuthenticationFailure,
  requestUsesRuntimeApiToken,
} from '@/lib/auth-runtime';

type JsonValue = Record<string, any> | any[];

const LOCAL_BASE_URL = 'https://afariex.ir/API/';
const PROD_BASE_URL = 'https://afariex.ir/API/';
const TIMEOUT_MS = 12000;
const ENDPOINT_TIMEOUTS: Record<string, number> = { 'topup.php': 35000, 'topup-create.php': 35000, 'topup-status.php': 35000 };
const MAX_ATTEMPTS = 1;
let authExpiryHandler: (() => void | Promise<void>) | null = null;

export const registerAuthExpiryHandler = (handler: () => void | Promise<void>) => {
  authExpiryHandler = handler;
  return () => { if (authExpiryHandler === handler) authExpiryHandler = null; };
};

export class ApiResponseError extends Error {
  status: number;
  responseCode: string;
  safeMessage: string;
  responseData: JsonValue | null;
  isNetworkError = false;

  constructor(
    status: number,
    message: string,
    responseCode = 'unknown',
    safeMessage = '',
    responseData: JsonValue | null = null
  ) {
    super(message);
    this.name = 'ApiResponseError';
    this.status = status;
    this.responseCode = responseCode;
    this.safeMessage = safeMessage;
    this.responseData = responseData;
  }
}

const normalizeBase = (url: string) => `${url.replace(/\/+$/, '')}/`;

const getBaseCandidates = () => {
  const envBase = process.env.EXPO_PUBLIC_API_BASE_URL?.trim();

  if (__DEV__ && Platform.OS === 'web') {
    return [normalizeBase(envBase || LOCAL_BASE_URL)];
  }

  if (envBase) {
    return [normalizeBase(envBase)];
  }
  return [normalizeBase(PROD_BASE_URL)];
};

const withTimeout = async (url: string, init?: RequestInit, timeoutMs = TIMEOUT_MS) => {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const mergedHeaders = {
      Accept: 'application/json',
      ...(init?.headers || {}),
    };
    return await fetch(url, { ...init, headers: mergedHeaders, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
};

const getErrorDetails = (error: unknown) => {
  if (error instanceof Error) {
    return {
      message: error.message,
      cause: error.cause,
      name: error.name,
    };
  }

  return {
    message: String(error),
    cause: undefined,
    name: typeof error,
  };
};

const sanitizeLoginResponseForDebug = (text: string) => {
  try {
    const redact = (value: unknown): unknown => {
      if (Array.isArray(value)) return value.map(redact);
      if (!value || typeof value !== 'object') return value;
      return Object.fromEntries(Object.entries(value as Record<string, unknown>).map(([key, item]) => [
        key,
        /token|password|secret|authorization|mobile|username|email|full_name|first_name|last_name/i.test(key)
          || /^(id|user_id|parent_id)$/i.test(key)
          ? '[REDACTED]'
          : redact(item),
      ]));
    };
    return JSON.stringify(redact(JSON.parse(text))).slice(0, 2000);
  } catch {
    return `<non-JSON response withheld; ${text.length} characters>`;
  }
};

const toNetworkError = (endpoint: string, finalUrl: string, error: unknown) => {
  const details = getErrorDetails(error);
  const lowerMessage = details.message.toLowerCase();
  const responseKind = details.name === 'AbortError'
    ? 'timeout'
    : lowerMessage.includes('html response received')
      ? 'html'
      : lowerMessage.includes('invalid json response')
        ? 'invalid_json'
        : 'network';
  const wrappedError = new Error(`Request failed for ${endpoint} at ${finalUrl}: ${details.message}`);
  wrappedError.name = responseKind === 'timeout'
    ? 'ApiTimeoutError'
    : responseKind === 'html'
      ? 'ApiHtmlResponseError'
      : responseKind === 'invalid_json'
        ? 'ApiInvalidResponseError'
        : 'ApiNetworkError';
  (wrappedError as Error & { isNetworkError?: boolean }).isNetworkError = true;
  (wrappedError as Error & { responseKind?: string }).responseKind = responseKind;
  (wrappedError as Error & { cause?: unknown }).cause = details.cause ?? error;
  return wrappedError;
};

export const isApiResponseError = (error: unknown): error is ApiResponseError =>
  error instanceof ApiResponseError;

export const isAuthenticationResponseError = (error: unknown): error is ApiResponseError =>
  isApiResponseError(error) && isExplicitAuthenticationFailure(error.status, error.responseCode);

export const getApiBaseUrl = () => getBaseCandidates()[0];

export const apiUrl = (endpoint: string) =>
  `${getApiBaseUrl()}${endpoint.replace(/^\/+/, '')}`;

export const fetchJson = async <T = JsonValue>(
  endpoint: string,
  init?: RequestInit
): Promise<T> => {
  const normalizedEndpoint = endpoint.replace(/^\/+/, '');
  const candidates = getBaseCandidates();
  const urls = candidates.map((base) => `${base}${normalizedEndpoint}`);

  let lastError: Error | null = null;

  for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt += 1) {
    for (const url of urls) {
      try {
        const method = (init?.method || 'GET').toUpperCase();
        const requestRuntimeToken = getRuntimeApiToken();
        const authenticatedRequest = requestUsesRuntimeApiToken(init, requestRuntimeToken);
        if (__DEV__) console.log(`[API] request attempt=${attempt} method=${method} url=${url}`);
        const response = await withTimeout(url, init, ENDPOINT_TIMEOUTS[normalizedEndpoint] || TIMEOUT_MS);
        const text = (await response.text()).trim();
        if (__DEV__ && normalizedEndpoint === 'login.php') {
          console.info('[AFARIEX-DEBUG] login response', {
            url,
            status: response.status,
            contentType: response.headers.get('content-type') || 'unknown',
            rawResponseBody: sanitizeLoginResponseForDebug(text),
          });
        }
        let responseCode = 'unknown';
        let parsedResponse: JsonValue | null = null;
        try {
          parsedResponse = JSON.parse(text) as JsonValue;
          const parsed = parsedResponse as { code?: unknown; data?: { code?: unknown } };
          const parsedCode = parsed?.code ?? parsed?.data?.code;
          responseCode = typeof parsedCode === 'string' ? parsedCode : 'none';
        } catch { /* handled below */ }
        if (__DEV__) console.log(`[API] response status=${response.status} code=${responseCode} method=${method} url=${url}`);

        const responseContentType = response.headers.get('content-type')?.toLowerCase() || '';
        if (responseContentType.includes('text/html') || text.startsWith('<')) {
          throw new Error('HTML response received instead of JSON.');
        }
        if (parsedResponse === null) {
          throw new Error('Invalid JSON response');
        }

        if (!response.ok) {
          if (
            authenticatedRequest
            && getRuntimeApiToken() === requestRuntimeToken
            && isExplicitAuthenticationFailure(response.status, responseCode)
          ) {
            void authExpiryHandler?.();
          }
          const responseObject = parsedResponse && !Array.isArray(parsedResponse)
            ? parsedResponse as Record<string, unknown>
            : null;
          const safeMessage = typeof responseObject?.message === 'string'
            ? responseObject.message.trim()
            : '';
          throw new ApiResponseError(
            response.status,
            safeMessage || `HTTP ${response.status}`,
            responseCode,
            safeMessage,
            parsedResponse
          );
        }

        return parsedResponse as T;
      } catch (error) {
        const details = getErrorDetails(error);
        const method = (init?.method || 'GET').toUpperCase();
        if (__DEV__) {
          console.log('[API] error details', {
            endpoint: normalizedEndpoint,
            url,
            method,
            tokenPresent: typeof init?.body === 'string' && init.body.includes('api_token='),
            details,
          });
          console.log(`[API] failure method=${method} url=${url} status=${(error as ApiResponseError).status || 'unknown'} code=${(error as ApiResponseError).responseCode || 'unknown'}`);
        }
        lastError = isApiResponseError(error)
          ? error
          : toNetworkError(normalizedEndpoint, url, error);
      }
    }
  }

  throw lastError || new Error(`Request failed for ${normalizedEndpoint}`);
};
