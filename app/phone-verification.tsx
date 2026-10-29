import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import { Stack, useRouter } from 'expo-router';
import React, { useEffect, useState } from 'react';
import { ActivityIndicator, KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { fetchJson, isApiResponseError } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { showError, showSuccess } from '@/lib/toast';

const maskMobile = (mobile: string | null) => {
  const value = String(mobile || '').trim();
  if (value.length < 5) return '****';
  return `${value.slice(0, 3)}${'*'.repeat(Math.max(2, value.length - 5))}${value.slice(-2)}`;
};

const apiErrorMessage = (error: unknown) => {
  if (isApiResponseError(error) && error.safeMessage) return error.safeMessage;
  const kind = typeof error === 'object' && error !== null && 'responseKind' in error
    ? String((error as { responseKind?: string }).responseKind || '')
    : '';
  if (kind === 'html') return 'پاسخ نامعتبر وب‌سرور یا سامانه امنیتی دریافت شد. لطفاً کمی بعد دوباره تلاش کنید.';
  if (kind === 'timeout') return 'پاسخ سرویس پیامک بیش از حد طول کشید. لطفاً کمی بعد دوباره تلاش کنید.';
  if (kind === 'invalid_json') return 'پاسخ سرور قابل پردازش نبود. لطفاً کمی بعد دوباره تلاش کنید.';
  return 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.';
};

export default function PhoneVerificationScreen() {
  const router = useRouter();
  const { userId, userToken, userMobile, isAuthenticated, isInitialized } = useAuth();
  const [code, setCode] = useState('');
  const [cooldown, setCooldown] = useState(0);
  const [requesting, setRequesting] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [sent, setSent] = useState(false);
  const [developmentCode, setDevelopmentCode] = useState<string | null>(null);

  useEffect(() => {
    if (cooldown <= 0) return undefined;
    const timer = setInterval(() => setCooldown((value) => Math.max(0, value - 1)), 1000);
    return () => clearInterval(timer);
  }, [cooldown]);

  useEffect(() => {
    if (isInitialized && !isAuthenticated) router.replace('/login' as any);
  }, [isAuthenticated, isInitialized, router]);

  const callApi = async (action: 'request' | 'verify') => {
    const body = new URLSearchParams({ user_id: String(userId || ''), api_token: String(userToken || ''), action });
    if (action === 'verify') body.append('code', code.trim());
    return fetchJson<any>('phone-verification.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    });
  };

  const requestCode = async () => {
    if (requesting || cooldown > 0 || !isInitialized) return;
    if (!isAuthenticated || !userId || !userToken) {
      showError('خطا', 'نشست کاربری معتبر نیست.');
      return;
    }
    setRequesting(true);
    try {
      const data = await callApi('request');
      if (data?.success !== true) {
        showError('خطا', data?.message || 'ارسال کد تأیید انجام نشد.');
        return;
      }
      if (data?.data?.phone_verified === true) {
        router.back();
        return;
      }
      setSent(true);
      setCooldown(60);
      setDevelopmentCode(data?.data?.development_code || null);
    } catch (error) {
      if (isApiResponseError(error) && error.responseCode === 'OTP_COOLDOWN') {
        const response = error.responseData as { data?: { resend_after?: number } } | null;
        setSent(true);
        setCooldown(Math.max(0, Number(response?.data?.resend_after || 0)));
      }
      showError('خطا', apiErrorMessage(error));
    } finally {
      setRequesting(false);
    }
  };

  const verifyCode = async () => {
    if (verifying || code.trim().length !== 6) {
      showError('خطا', 'کد تأیید صحیح نیست.');
      return;
    }
    if (!isInitialized || !isAuthenticated || !userId || !userToken) {
      showError('خطا', 'نشست کاربری معتبر نیست.');
      return;
    }
    setVerifying(true);
    try {
      const data = await callApi('verify');
      if (data?.success !== true) {
        showError('خطا', data?.message || 'کد واردشده صحیح نیست.');
        return;
      }
      showSuccess('تأیید موفق', 'شماره موبایل با موفقیت تأیید شد.');
      router.back();
    } catch (error) {
      showError('خطا', apiErrorMessage(error));
    } finally {
      setVerifying(false);
    }
  };

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.container}>
        <View style={styles.header}>
          <TouchableOpacity style={styles.backButton} onPress={() => router.back()} accessibilityLabel="بازگشت"><Ionicons name="arrow-forward" size={22} color="#374151" /></TouchableOpacity>
          <Text style={styles.title}>تأیید شماره موبایل</Text><View style={styles.headerSpace} />
        </View>
        <KeyboardAvoidingView style={styles.keyboard} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
          <View style={styles.card}>
            <View style={styles.iconCircle}><Ionicons name="phone-portrait-outline" size={30} color="appColors.primary" /></View>
            <Text style={styles.description}>کد تأیید برای شماره زیر ارسال می‌شود:</Text>
            <Text style={styles.mobile}>{maskMobile(userMobile)}</Text>
            {!sent ? <TouchableOpacity style={styles.button} onPress={requestCode} disabled={requesting || !isInitialized || !isAuthenticated || !userId || !userToken}>
              {requesting ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>ارسال کد تأیید</Text>}
            </TouchableOpacity> : <>
              <Text style={styles.sentText}>کد تأیید برای شماره شما ارسال شد.</Text>
              {developmentCode && <Text style={styles.devCode}>کد آزمایشی: {developmentCode}</Text>}
              <TextInput style={styles.input} value={code} onChangeText={setCode} keyboardType="number-pad" maxLength={6} placeholder="کد تأیید" textAlign="center" />
              <TouchableOpacity style={styles.button} onPress={verifyCode} disabled={verifying}>
                {verifying ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>تأیید شماره موبایل</Text>}
              </TouchableOpacity>
              <TouchableOpacity style={styles.resend} onPress={requestCode} disabled={cooldown > 0 || requesting}>
                <Text style={[styles.resendText, cooldown > 0 && styles.disabledText]}>{cooldown > 0 ? `ارسال مجدد کد (${cooldown})` : 'ارسال مجدد کد'}</Text>
              </TouchableOpacity>
            </>}
          </View>
        </ScrollView>
        </KeyboardAvoidingView>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: appColors.background },
  container: { flex: 1, padding: 18 },
  keyboard: { flex: 1 },
  header: { flexDirection: 'row-reverse', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 },
  backButton: { width: 40, height: 40, borderRadius: 20, backgroundColor: appColors.surface, alignItems: 'center', justifyContent: 'center' },
  title: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text },
  headerSpace: { width: 40 },
  content: { paddingBottom: 30 },
  card: { backgroundColor: appColors.surface, borderRadius: 20, padding: 24, alignItems: 'center' },
  iconCircle: { width: 64, height: 64, borderRadius: 32, backgroundColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center', marginBottom: 14 },
  description: { fontFamily: 'Vazirmatn', color: appColors.textSecondary, textAlign: 'center' },
  mobile: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text, marginTop: 10 },
  sentText: { fontFamily: 'Vazirmatn', color: '#059669', fontSize: 13, textAlign: 'center', marginTop: 18 },
  devCode: { fontFamily: 'VazirmatnBold', color: appColors.primary, marginTop: 10 },
  input: { width: '100%', borderWidth: 1, borderColor: appColors.surfaceRaised, borderRadius: 12, padding: 14, marginTop: 18, fontFamily: 'Vazirmatn', fontSize: 20, letterSpacing: 4 },
  button: { width: '100%', minHeight: 52, backgroundColor: appColors.primary, borderRadius: 12, alignItems: 'center', justifyContent: 'center', marginTop: 20 },
  buttonText: { fontFamily: 'VazirmatnBold', color: '#fff', fontSize: 14 },
  resend: { marginTop: 18, padding: 8 },
  resendText: { fontFamily: 'Vazirmatn', color: appColors.primary },
  disabledText: { color: '#9ca3af' },
});
