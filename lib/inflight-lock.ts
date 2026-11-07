export type InFlightLock = { current: boolean };

export const tryAcquireInFlight = (lock: InFlightLock): boolean => {
  if (lock.current) return false;
  lock.current = true;
  return true;
};

export const releaseInFlight = (lock: InFlightLock): void => {
  lock.current = false;
};
