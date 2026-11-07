import test from 'node:test';
import assert from 'node:assert/strict';
import { releaseInFlight, tryAcquireInFlight } from '../lib/inflight-lock.ts';

test('two immediate submit triggers acquire the financial request lock once', () => {
  const submitInFlight = { current: false };
  let submittedRequests = 0;
  const triggerSubmit = () => {
    if (!tryAcquireInFlight(submitInFlight)) return;
    submittedRequests += 1;
  };

  triggerSubmit();
  triggerSubmit();

  assert.equal(submittedRequests, 1);
  releaseInFlight(submitInFlight);
  triggerSubmit();
  assert.equal(submittedRequests, 2);
});
