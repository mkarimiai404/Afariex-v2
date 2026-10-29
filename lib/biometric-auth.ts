import * as LocalAuthentication from 'expo-local-authentication';
import * as SecureStore from 'expo-secure-store';
import { fetchJson, isAuthenticationResponseError } from '@/lib/api';

type StoredBiometricSession = {
  userId: string;
  userToken: string;
  userName: string | null;
  userMobile: string | null;
  isSubuser: boolean;
  phoneVerified: boolean;
  kycStatus: string;
  savedAt: string;
};

export type BiometricAvailability = {
  available: boolean;
  hardwareAvailable: boolean;
  enrolled: boolean;
  fingerprintSupported: boolean;
  otherBiometricSupported: boolean;
  label: string;
  types: LocalAuthentication.AuthenticationType[];
};

export type BiometricEnabledState = {
  enabled: boolean;
  markerExists: boolean;
  markerValue: string | null;
};

const BIOMETRIC_SESSION_KEY = 'afariex_biometric_session_v1';
const BIOMETRIC_MARKER_KEY = 'afariex_biometric_enabled_v1';

const baseSecureStoreOptions: SecureStore.SecureStoreOptions = {
  keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
};

const authenticatedSecureStoreOptions: SecureStore.SecureStoreOptions = {
  ...baseSecureStoreOptions,
  requireAuthentication: true,
  authenticationPrompt: 'ورود امن به حساب آفاریکس',
};

const fingerprintLabel = 'ورود با اثر انگشت';
const fingerprintPrompt = 'ورود با اثر انگشت';

export const biometricLabel = (): string => fingerprintLabel;

const biometricTypeName = (type: LocalAuthentication.AuthenticationType): string => {
  switch (type) {
    case LocalAuthentication.AuthenticationType.FINGERPRINT:
      return 'FINGERPRINT';
    case LocalAuthentication.AuthenticationType.FACIAL_RECOGNITION:
      return 'FACIAL_RECOGNITION';
    case LocalAuthentication.AuthenticationType.IRIS:
      return 'IRIS';
    default:
      return `UNKNOWN_${type}`;
  }
};

export const supportsFingerprintOnly = (types: LocalAuthentication.AuthenticationType[] = []): boolean => {
  return types.includes(LocalAuthentication.AuthenticationType.FINGERPRINT);
};

export const getBiometricAvailability = async (): Promise<BiometricAvailability> => {
  const [hardwareAvailable, enrolled, types] = await Promise.all([
    LocalAuthentication.hasHardwareAsync(),
    LocalAuthentication.isEnrolledAsync(),
    LocalAuthentication.supportedAuthenticationTypesAsync(),
  ]);

  const fingerprintSupported = types.includes(LocalAuthentication.AuthenticationType.FINGERPRINT);
  const otherBiometricSupported = types.some((type) => type !== LocalAuthentication.AuthenticationType.FINGERPRINT);
  const detectedTypes = types.map(biometricTypeName);
  const available = hardwareAvailable && enrolled && fingerprintSupported;

  const rejectionReasons = [
    !hardwareAvailable ? 'hasHardwareAsync returned false' : null,
    !enrolled ? 'isEnrolledAsync returned false' : null,
    !fingerprintSupported ? 'supportedAuthenticationTypesAsync did not include FINGERPRINT' : null,
  ].filter(Boolean);

  if (__DEV__) console.log('[BiometricAuth] capability detection', {
    hasHardwareAsync: hardwareAvailable,
    isEnrolledAsync: enrolled,
    supportedAuthenticationTypesAsync: types,
    detectedBiometricTypes: detectedTypes,
    fingerprintSupported,
    otherBiometricSupported,
    fingerprintAvailable: available,
    rejectionReason: rejectionReasons.length > 0 ? rejectionReasons.join('; ') : 'none',
  });

  return {
    available,
    hardwareAvailable,
    enrolled,
    fingerprintSupported,
    otherBiometricSupported,
    types,
    label: biometricLabel(),
  };
};

export const getBiometricEnabledState = async (): Promise<BiometricEnabledState> => {
  const marker = await SecureStore.getItemAsync(BIOMETRIC_MARKER_KEY, baseSecureStoreOptions);
  const state = {
    enabled: marker === 'enabled',
    markerExists: marker !== null,
    markerValue: marker,
  };

  if (__DEV__) console.log('[BiometricAuth] enabled marker detection', {
    biometricEnabled: state.enabled,
    secureStoreMarkerExists: state.markerExists,
    biometricEnabledMarkerValue: state.markerValue,
  });

  return state;
};

export const hasSavedBiometricSession = async (): Promise<boolean> => {
  const markerState = await getBiometricEnabledState();
  const marker = markerState.markerValue;
  if (marker !== 'enabled') {
    await SecureStore.deleteItemAsync(BIOMETRIC_SESSION_KEY, baseSecureStoreOptions);
    return false;
  }
  return true;
};

export const saveBiometricSession = async (session: {
  userId: string | null;
  userToken: string | null;
  userName: string | null;
  userMobile: string | null;
  isSubuser?: boolean;
  phoneVerified?: boolean;
  kycStatus?: string;
}): Promise<void> => {
  const userId = String(session.userId ?? '').trim();
  const userToken = String(session.userToken ?? '').trim();
  if (!userId || !userToken) throw new Error('A valid authenticated session is required.');

  const payload: StoredBiometricSession = {
    userId,
    userToken,
    userName: session.userName ?? null,
    userMobile: session.userMobile ?? null,
    isSubuser: session.isSubuser === true,
    phoneVerified: session.phoneVerified === true,
    kycStatus: session.kycStatus ?? 'pending',
    savedAt: new Date().toISOString(),
  };

  await SecureStore.setItemAsync(BIOMETRIC_SESSION_KEY, JSON.stringify(payload), authenticatedSecureStoreOptions);
  await SecureStore.setItemAsync(BIOMETRIC_MARKER_KEY, 'enabled', baseSecureStoreOptions);
  if (__DEV__) console.log('[BiometricAuth] enabled marker saved', {
    biometricEnabled: true,
    secureStoreMarkerExists: true,
    biometricEnabledMarkerValue: 'enabled',
  });
};

export const clearBiometricSession = async (): Promise<void> => {
  await Promise.allSettled([
    SecureStore.deleteItemAsync(BIOMETRIC_SESSION_KEY, baseSecureStoreOptions),
    SecureStore.deleteItemAsync(BIOMETRIC_MARKER_KEY, baseSecureStoreOptions),
  ]);
};

export const authenticateWithBiometrics = async (): Promise<LocalAuthentication.LocalAuthenticationResult> =>
  LocalAuthentication.authenticateAsync({
    promptMessage: fingerprintPrompt,
    cancelLabel: 'انصراف',
    fallbackLabel: '',
    disableDeviceFallback: true,
    biometricsSecurityLevel: 'strong',
  });

export const getSavedBiometricSession = async (): Promise<StoredBiometricSession | null> => {
  const saved = await SecureStore.getItemAsync(BIOMETRIC_SESSION_KEY, authenticatedSecureStoreOptions);
  if (!saved) {
    await clearBiometricSession();
    return null;
  }

  try {
    const parsed = JSON.parse(saved) as Partial<StoredBiometricSession>;
    const userId = String(parsed.userId ?? '').trim();
    const userToken = String(parsed.userToken ?? '').trim();
    if (!userId || !userToken) return null;
    return {
      userId,
      userToken,
      userName: parsed.userName ?? null,
      userMobile: parsed.userMobile ?? null,
      isSubuser: parsed.isSubuser === true,
      phoneVerified: parsed.phoneVerified === true,
      kycStatus: parsed.kycStatus ?? 'pending',
      savedAt: String(parsed.savedAt ?? ''),
    };
  } catch {
    await clearBiometricSession();
    return null;
  }
};

export const validateBiometricSession = async (session: StoredBiometricSession): Promise<boolean> => {
  const body = new URLSearchParams({
    api_token: session.userToken,
    token: session.userToken,
    user_token: session.userToken,
  });

  try {
    const response = await fetchJson<{ success?: boolean; user?: { id?: unknown } }>('profile.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    });
    const responseUserId = String(response?.user?.id ?? '').trim();
    return response?.success === true && responseUserId === session.userId;
  } catch (error) {
    if (isAuthenticationResponseError(error)) {
      await clearBiometricSession();
      return false;
    }
    throw error;
  }
};

export const loadBiometricSessionAfterAuthentication = async (): Promise<StoredBiometricSession | null> => {
  const result = await authenticateWithBiometrics();
  if (!result.success) return null;
  return getSavedBiometricSession();
};
