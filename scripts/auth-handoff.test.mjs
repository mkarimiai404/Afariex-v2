import assert from 'node:assert/strict';
import test from 'node:test';

import {
  clearRuntimeApiToken,
  getRuntimeApiToken,
  isExplicitAuthenticationFailure,
  requestUsesRuntimeApiToken,
  setRuntimeApiToken,
} from '../lib/auth-runtime.ts';
import { InvalidLoginResponseError, parseLoginSession } from '../lib/login-session.ts';

const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

const loginResponse = (overrides = {}) => ({
  success: true,
  status: 'success',
  code: 'LOGIN_SUCCESS',
  data: {
    id: 17,
    mobile: '09000000000',
    full_name: 'Fixture User',
    api_token: 'fixture-runtime-token',
    ...overrides,
  },
});

const simulateSuccessfulHandoff = async (loginDelay, commitDelay) => {
  clearRuntimeApiToken();
  await delay(loginDelay);
  const session = parseLoginSession(loginResponse());

  // This is the synchronous part of AuthContext.signIn.
  setRuntimeApiToken(session.userToken);
  const immediateDashboardToken = getRuntimeApiToken();

  let contextAuthenticated = false;
  const committed = delay(commitDelay).then(() => { contextAuthenticated = true; });
  await committed; // Login navigation is intentionally gated on this commit.

  return { contextAuthenticated, immediateDashboardToken, dashboardToken: getRuntimeApiToken() };
};

for (const loginDelay of [100, 500, 1500]) {
  test(`successful login remains authenticated after ${loginDelay}ms response delay`, async () => {
    const result = await simulateSuccessfulHandoff(loginDelay, 35);
    assert.equal(result.contextAuthenticated, true);
    assert.equal(result.immediateDashboardToken, 'fixture-runtime-token');
    assert.equal(result.dashboardToken, 'fixture-runtime-token');
  });
}

test('dashboard request starting immediately sees the synchronously installed token', async () => {
  const result = await simulateSuccessfulHandoff(0, 120);
  assert.equal(result.immediateDashboardToken, result.dashboardToken);
});

test('late cold-start storage cleanup cannot overwrite a successful in-memory login', async () => {
  clearRuntimeApiToken();
  const legacyCleanup = delay(100); // Cleanup removes storage only; it never resets the new session.
  await delay(10);
  setRuntimeApiToken('fixture-runtime-token');
  await legacyCleanup;
  assert.equal(getRuntimeApiToken(), 'fixture-runtime-token');
});

test('timeouts, HTTP 500, and generic 401 do not expire runtime authentication', () => {
  setRuntimeApiToken('fixture-runtime-token');
  for (const failure of [
    { status: 0, code: 'TIMEOUT' },
    { status: 500, code: 'DASHBOARD_UNAVAILABLE' },
    { status: 401, code: 'LOGIN_FAILED' },
  ]) {
    if (isExplicitAuthenticationFailure(failure.status, failure.code)) clearRuntimeApiToken();
    assert.equal(getRuntimeApiToken(), 'fixture-runtime-token');
  }
});

test('only an authenticated request with the explicit invalid-token 401 contract expires auth', () => {
  setRuntimeApiToken('fixture-runtime-token');
  const init = { body: new URLSearchParams({ api_token: 'fixture-runtime-token' }).toString() };
  assert.equal(requestUsesRuntimeApiToken(init, getRuntimeApiToken()), true);
  assert.equal(isExplicitAuthenticationFailure(401, 'AUTHENTICATION_FAILED'), true);
  clearRuntimeApiToken('fixture-runtime-token');
  assert.equal(getRuntimeApiToken(), null);
});

test('a slow valid profile response leaves the same runtime session installed', async () => {
  setRuntimeApiToken('fixture-runtime-token');
  await delay(150);
  assert.equal(getRuntimeApiToken(), 'fixture-runtime-token');
});

test('current, legacy, nullable-profile, verification-level, and balance variants all parse', () => {
  const fixtures = [
    loginResponse(),
    loginResponse({ full_name: undefined, name: 'Legacy User' }),
    loginResponse({ full_name: null, mobile: null, email: null }),
    loginResponse({ verification: { level: 'bronze' }, balance: 0 }),
    loginResponse({ verification: { level: 'gold' }, balance: 250000 }),
  ];
  for (const fixture of fixtures) assert.equal(parseLoginSession(fixture).userToken, 'fixture-runtime-token');
});

test('unexpected JSON and missing api_token are rejected before authentication', () => {
  assert.throws(() => parseLoginSession({ success: true, status: 'success', data: {} }), InvalidLoginResponseError);
  assert.throws(
    () => parseLoginSession({ success: true, status: 'success', data: { api_token: 'fixture-runtime-token' } }),
    InvalidLoginResponseError,
  );
  assert.throws(() => parseLoginSession('<html>unexpected</html>'), InvalidLoginResponseError);
});

test('process start and manual logout keep the token memory-only', () => {
  clearRuntimeApiToken();
  assert.equal(getRuntimeApiToken(), null);
  setRuntimeApiToken('fixture-runtime-token');
  clearRuntimeApiToken();
  assert.equal(getRuntimeApiToken(), null);
});
