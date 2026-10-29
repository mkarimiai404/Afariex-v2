import { appColors } from '@/lib/theme';
import React, { useEffect, useState } from 'react';
import {
  Alert,
  View,
  Text,
  TextInput,
  TouchableOpacity,
  StyleSheet,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  ActivityIndicator
} from 'react-native';
import { useRouter, Stack } from 'expo-router'; // Stack اضافه شد
import { Ionicons } from '@expo/vector-icons';

import { apiUrl, fetchJson, isApiResponseError } from '@/lib/api';
import {
  authenticateWithBiometrics,
  clearBiometricSession,
  getBiometricEnabledState,
  getBiometricAvailability,
  getSavedBiometricSession,
  saveBiometricSession,
  validateBiometricSession,
} from '@/lib/biometric-auth';
import { useAuth } from '@/lib/auth-context';
import { InvalidLoginResponseError, parseLoginSession } from '@/lib/login-session';
import { showError, showSuccess } from '@/lib/toast';

export default function LoginScreen() {
  const router = useRouter();
  const { isAuthenticated, isInitialized, signIn } = useAuth();
  const [mobile, setMobile] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [biometricLoading, setBiometricLoading] = useState(false);
  const [biometricAvailable, setBiometricAvailable] = useState(false);
  const [biometricLabel, setBiometricLabel] = useState('ورود با اثر انگشت');
  const [showPassword, setShowPassword] = useState(false);

  useEffect(() => {
    if (__DEV__) console.log('[LOGIN_RUNTIME] actual login screen mounted');
  }, []);

  useEffect(() => {
    if (isInitialized && isAuthenticated) {
      router.replace('/dashboard');
    }
  }, [isAuthenticated, isInitialized, router]);

  useEffect(() => {
    let active = true;
    const loadBiometricState = async () => {
      try {
        const [availability, markerState] = await Promise.all([
          getBiometricAvailability(),
          getBiometricEnabledState(),
        ]);
        if (!active) return;
        const showFingerprintButton = markerState.enabled && availability.available;
        const hiddenReasons = [
          !markerState.enabled ? 'biometric enabled marker is missing or not enabled' : null,
          !availability.hardwareAvailable ? 'fingerprint hardware is not available' : null,
          !availability.fingerprintSupported ? 'FINGERPRINT is not reported in supported authentication types' : null,
          !availability.enrolled ? 'fingerprint is not enrolled on this device' : null,
        ].filter(Boolean);

        if (__DEV__) console.log('[BiometricAuth] login button visibility', {
          biometricEnabledMarkerValue: markerState.markerValue,
          secureStoreMarkerExists: markerState.markerExists,
          biometricEnabled: markerState.enabled,
          fingerprintHardwareSupported: availability.hardwareAvailable,
          fingerprintSupported: availability.fingerprintSupported,
          fingerprintEnrollmentStatus: availability.enrolled,
          showFingerprintButton,
          hiddenReason: hiddenReasons.length > 0 ? hiddenReasons.join('; ') : 'none',
        });
        setBiometricAvailable(showFingerprintButton);
        setBiometricLabel(availability.label);
      } catch (error) {
        if (__DEV__) console.log('[BiometricAuth] login button visibility', {
          showFingerprintButton: false,
          hiddenReason: 'fingerprint visibility detection failed',
          error: error instanceof Error ? error.message : String(error),
        });
        if (active) setBiometricAvailable(false);
      }
    };

    void loadBiometricState();
    return () => { active = false; };
  }, []);

  const promptEnableBiometricLogin = async (session: ReturnType<typeof parseLoginSession>) => {
    try {
      const availability = await getBiometricAvailability();
      if (!availability.available) return;

      Alert.alert(
        'ورود با اثر انگشت',
        'برای ورود امن با اثر انگشت، ابتدا اثر انگشت خود را تأیید کنید.',
        [
          { text: 'بعداً', style: 'cancel' },
          {
            text: 'فعال‌سازی',
            onPress: () => {
              void authenticateWithBiometrics()
                .then((result) => {
                  if (!result.success) return null;
                  return saveBiometricSession({
                    userId: session.userId,
                    userToken: session.userToken,
                    userName: session.userName,
                    userMobile: session.userMobile,
                     isSubuser: session.isSubuser,
                     phoneVerified: session.phoneVerified,
                     kycStatus: session.kycStatus,
                  });
                })
                .then((saved) => {
                  if (saved === null) return;
                  setBiometricAvailable(true);
                  setBiometricLabel(availability.label);
                  showSuccess('فعال شد', 'ورود با اثر انگشت برای این دستگاه فعال شد.');
                })
                .catch(() => showError('خطا', 'فعال‌سازی ورود با اثر انگشت انجام نشد.'));
            },
          },
        ],
        { cancelable: true }
      );
    } catch {
      // Biometric setup is optional and must not block normal login.
    }
  };

   const handleLogin = async () => {
    if (loading) return;
    if (!mobile || !password) {
      showError('خطا', 'لطفاً شماره موبایل و رمز عبور را وارد کنید.');
      return;
    }

    setLoading(true);

    try {
      // استفاده از FormData برای سازگاری کامل با سرورهای PHP
      const formData = new FormData();
      formData.append('mobile', mobile);
      formData.append('password', password);
      formData.append('username', mobile);

      if (__DEV__) {
        if (__DEV__) console.info('[AFARIEX-DEBUG] login request', {
          url: apiUrl('login.php'),
          method: 'POST',
          headers: { Accept: 'application/json', 'Content-Type': 'multipart/form-data (fetch-generated; boundary omitted)' },
          bodyFields: ['mobile', 'password', 'username'],
          sensitiveValuesLogged: false,
        });
      }

      const data = await fetchJson<any>('login.php', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
        },
        // نیازی به تعریف Content-Type نیست، fetch خودش برای FormData آن را تنظیم می‌کند
        body: formData,
      });

      const session = parseLoginSession(data);
      await signIn(session);
      void promptEnableBiometricLogin(session);
       if (session.isSubuser && !session.phoneVerified) router.replace('/phone-verification');
       else if (session.isSubuser && session.kycStatus !== 'approved') router.replace('/verification-upgrade');
       else router.replace('/dashboard');
    } catch (error) {
      if (__DEV__) {
        const status = isApiResponseError(error) ? error.status : null;
        const errorName = error instanceof Error ? error.name : typeof error;
        const errorMessage = error instanceof Error ? error.message : String(error);
        const cause = error instanceof Error ? (error as Error & { cause?: unknown }).cause : undefined;
        const causeName = cause instanceof Error ? cause.name : typeof cause;
        const causeMessage = cause instanceof Error ? cause.message : cause === undefined ? null : String(cause);
        if (__DEV__) console.error('[AFARIEX-DEBUG] login failure', {
          url: apiUrl('login.php'),
          method: 'POST',
          headers: { Accept: 'application/json', 'Content-Type': 'multipart/form-data (fetch-generated; boundary omitted)' },
          status,
          networkFailure: errorName === 'ApiNetworkError'
            || errorName === 'ApiTimeoutError'
            || errorMessage.toLowerCase().includes('network request failed'),
          errorName,
          errorMessage,
          causeName,
          causeMessage,
          responseCode: isApiResponseError(error) ? error.responseCode : null,
        });
      }
      if (isApiResponseError(error) && error.safeMessage) {
        showError('خطا در ورود', error.safeMessage);
      } else if (error instanceof InvalidLoginResponseError) {
        showError('خطا در ورود', 'پاسخ ورود معتبر نیست. لطفاً دوباره تلاش کنید.');
      } else {
        showError('خطای ارتباط', 'خطا در ارتباط با سرور. لطفاً اتصال اینترنت خود را بررسی کنید.');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleBiometricLogin = async () => {
    if (biometricLoading || loading) return;
    setBiometricLoading(true);

    try {
      const availability = await getBiometricAvailability();
      if (!availability.hardwareAvailable) {
        await clearBiometricSession();
        setBiometricAvailable(false);
        showError('ورود با اثر انگشت', 'این دستگاه از ورود با اثر انگشت پشتیبانی نمی‌کند.');
        return;
      }
      if (!availability.fingerprintSupported) {
        await clearBiometricSession();
        setBiometricAvailable(false);
        showError('ورود با اثر انگشت', 'ورود با اثر انگشت فقط روی دستگاه‌های دارای حسگر اثر انگشت فعال است.');
        return;
      }
      if (!availability.enrolled) {
        await clearBiometricSession();
        setBiometricAvailable(false);
        showError('ورود با اثر انگشت', 'ابتدا اثر انگشت را در تنظیمات دستگاه فعال کنید.');
        return;
      }

      const result = await authenticateWithBiometrics();
      if (!result.success) {
        if (result.error !== 'user_cancel' && result.error !== 'system_cancel' && result.error !== 'app_cancel') {
          showError('ورود با اثر انگشت', 'تأیید اثر انگشت موفق نبود.');
        }
        return;
      }

      const session = await getSavedBiometricSession();
      if (!session) {
        setBiometricAvailable(false);
        showError('ورود با اثر انگشت', 'نشست ذخیره‌شده پیدا نشد. لطفاً با رمز عبور وارد شوید.');
        return;
      }

      const validSession = await validateBiometricSession(session);
      if (!validSession) {
        setBiometricAvailable(false);
        showError('ورود با اثر انگشت', 'نشست ذخیره‌شده منقضی شده است. لطفاً با رمز عبور وارد شوید.');
        return;
      }

      await signIn({
        userId: session.userId,
        userToken: session.userToken,
        userName: session.userName,
        userMobile: session.userMobile,
        isSubuser: session.isSubuser,
        phoneVerified: session.phoneVerified,
        kycStatus: session.kycStatus,
      });
      if (session.isSubuser && !session.phoneVerified) router.replace('/phone-verification');
      else if (session.isSubuser && session.kycStatus !== 'approved') router.replace('/verification-upgrade');
      else router.replace('/dashboard');
    } catch {
      await clearBiometricSession();
      setBiometricAvailable(false);
      showError('ورود با اثر انگشت', 'ورود با اثر انگشت در حال حاضر در دسترس نیست.');
    } finally {
      setBiometricLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
    >
      {/* این خط هدر پیش‌فرض را حذف می‌کند */}
      <Stack.Screen options={{ headerShown: false }} />

      <ScrollView contentContainerStyle={styles.pageWrapper} keyboardShouldPersistTaps="handled">

        <View style={styles.loginCard}>
          <Text style={styles.title}>ورود به حساب</Text>
          <Text style={styles.subtitle}>خوش برگشتید! لطفاً اطلاعات خود را وارد کنید.</Text>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>شماره موبایل یا نام کاربری</Text>
            <TextInput
              style={styles.input}
              placeholder="09123456789 یا نام کاربری"
              placeholderTextColor="#b0b0b0"
              autoCapitalize="none"
              keyboardType="default"
              value={mobile}
              onChangeText={setMobile}
              maxLength={64}
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>رمز عبور</Text>
            <View style={styles.passwordContainer}>
              <TextInput
                style={[styles.input, { paddingLeft: 45 }]}
                placeholder="رمز عبور خود را وارد کنید"
                placeholderTextColor="#b0b0b0"
                secureTextEntry={!showPassword}
                value={password}
                onChangeText={setPassword}
              />
              <TouchableOpacity
                style={styles.eyeIconContainer}
                onPress={() => setShowPassword(!showPassword)}
              >
                <Text style={styles.eyeIconText}>👁</Text>
              </TouchableOpacity>
            </View>
          </View>

          <TouchableOpacity
            style={styles.loginBtn}
            onPress={handleLogin}
            disabled={loading || biometricLoading || !isInitialized}
          >
            {loading ? (
              <ActivityIndicator color="#ffffff" />
            ) : (
              <Text style={styles.loginBtnText}>ورود</Text>
            )}
          </TouchableOpacity>

          {biometricAvailable && (
            <TouchableOpacity
              style={styles.biometricBtn}
              onPress={handleBiometricLogin}
              disabled={loading || biometricLoading || !isInitialized}
              activeOpacity={0.85}
              accessibilityRole="button"
              accessibilityLabel={biometricLabel}
            >
              {biometricLoading ? (
                <ActivityIndicator color="#0b8f72" />
              ) : (
                <>
                  <Ionicons name="finger-print-outline" size={21} color="#0b8f72" />
                  <Text style={styles.biometricBtnText}>{biometricLabel}</Text>
                </>
              )}
            </TouchableOpacity>
          )}

          <TouchableOpacity onPress={() => router.replace('/')}>
            <Text style={styles.backLink}>بازگشت به صفحه اصلی</Text>
          </TouchableOpacity>

        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: appColors.background,
  },
  pageWrapper: {
    flexGrow: 1,
    justifyContent: 'center',
    alignItems: 'center',
    width: '100%',
    padding: 20,
    paddingBottom: 70,
  },
  loginCard: {
    backgroundColor: 'transparent',
    width: '100%',
    maxWidth: 420,
    minHeight: 0,
    paddingHorizontal: 0,
    paddingVertical: 0,
    borderRadius: 0,
    shadowColor: '#000',
    shadowOpacity: 0,
    shadowRadius: 0,
    elevation: 0,
    justifyContent: 'center',
  },
  title: {
    color: '#FFFFFF',
    fontSize: 24,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    marginBottom: 10,
    textAlign: 'center',
  },
  subtitle: {
    color: '#8ED0D1',
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    marginBottom: 30,
    textAlign: 'center',
  },
  inputGroup: {
    marginBottom: 20,
    width: '100%',
  },
  label: {
    color: '#8ED0D1',
    fontSize: 13,
    fontFamily: 'Vazirmatn',
    fontWeight: '600',
    marginBottom: 8,
    textAlign: 'right',
  },
  input: {
    width: '100%',
    paddingVertical: 12,
    paddingHorizontal: 15,
    borderWidth: 1,
    borderColor: '#e0e0e0',
    borderRadius: 8,
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    color: '#FFFFFF',
    textAlign: 'left',
    backgroundColor: appColors.surface,
  },
  passwordContainer: {
    position: 'relative',
    justifyContent: 'center',
  },
  eyeIconContainer: {
    position: 'absolute',
    left: 15,
    zIndex: 1,
    height: '100%',
    justifyContent: 'center',
    paddingRight: 10,
  },
  eyeIconText: {
    fontSize: 18,
    fontFamily: 'Vazirmatn',
    color: '#888',
  },
  loginBtn: {
    backgroundColor: '#01F8FD',
    width: '100%',
    padding: 14,
    borderRadius: 8,
    alignItems: 'center',
    marginTop: 10,
    marginBottom: 25,
  },
  loginBtnText: {
    color: '#00090A',
    fontSize: 16,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
  },
  biometricBtn: {
    width: '100%',
    minHeight: 48,
    borderWidth: 1,
    borderColor: '#99f6e4',
    backgroundColor: appColors.surfaceRaised,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
    flexDirection: 'row-reverse',
    gap: 8,
    marginBottom: 14,
  },
  biometricBtnText: {
    color: appColors.primary,
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    textAlign: 'center',
  },
  backLink: {
    color: '#FFFFFF',
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    textAlign: 'center',
    marginTop: 10,
  }
});
