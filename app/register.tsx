import { appColors } from '@/lib/theme';
import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Image, KeyboardAvoidingView, Platform, SafeAreaView, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import * as Clipboard from 'expo-clipboard';
import { Stack, useRouter } from 'expo-router';

import { fetchJson, isApiResponseError } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { PolicySection, RegistrationPolicyModal } from '@/components/registration-policy-modal';

type RegisterStep = 'form' | 'otp' | 'success';

const passwordOk = (value: string) => value.length >= 8 && /[a-z]/.test(value) && /[A-Z]/.test(value) && /\d/.test(value);
const codeMessage: Record<string, string> = {
  INVALID_MOBILE: 'شماره موبایل معتبر وارد کنید.',
  MOBILE_ALREADY_REGISTERED: 'این شماره موبایل قبلاً ثبت شده است.',
  INVALID_REGISTRATION_DATA: 'اطلاعات ثبت‌نام معتبر نیست.',
  INVALID_REFERRAL_CODE: 'کد معرف معتبر نیست.',
  OTP_COOLDOWN: 'لطفاً برای ارسال مجدد کمی صبر کنید.',
  OTP_RATE_LIMITED: 'تعداد درخواست‌ها بیش از حد مجاز است.',
  OTP_DAILY_LIMITED: 'تعداد درخواست‌های امروز بیش از حد مجاز است.',
  INVALID_OTP: 'کد تأیید واردشده صحیح نیست.',
  OTP_EXPIRED: 'کد تأیید منقضی شده است.',
  OTP_ATTEMPTS_EXCEEDED: 'تعداد تلاش‌ها بیش از حد مجاز است.',
  REGISTRATION_OTP_REQUIRED: 'ابتدا کد تأیید شماره موبایل را وارد کنید.',
  REGISTRATION_OTP_INVALID: 'تأیید ثبت‌نام معتبر نیست.',
  OTP_SEND_FAILED: 'ارسال کد تأیید در حال حاضر امکان‌پذیر نیست.',
  PIN_SYSTEM_UNAVAILABLE: 'ساخت حساب در حال حاضر امکان‌پذیر نیست.',
  REGISTRATION_FAILED: 'ثبت‌نام در حال حاضر انجام نشد. لطفاً کمی بعد دوباره تلاش کنید.',
  CONSENT_REQUIRED: 'برای ادامه ثبتنام، ابتدا قوانین و سیاست حفظ حریم خصوصی را مطالعه و تأیید کنید.',
  CONSENT_STORAGE_UNAVAILABLE: 'ثبت‌نام در حال حاضر امکان‌پذیر نیست.',
};

const normalizeDigits = (value: string) => value.replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)));

function normalizeIranMobile(value: string): string | null {
  let normalized = normalizeDigits(value).trim().replace(/[\s().-]+/g, '');
  if (normalized.startsWith('+98')) normalized = `0${normalized.slice(3)}`;
  else if (normalized.startsWith('98')) normalized = `0${normalized.slice(2)}`;
  return /^09\d{9}$/.test(normalized) ? normalized : null;
}

function maskMobile(mobile: string): string {
  return mobile.length === 11 ? `${mobile.slice(0, 3)}******${mobile.slice(-2)}` : '***********';
}

function readResponseCode(error: unknown): string {
  return typeof error === 'object' && error !== null && 'responseCode' in error ? String((error as { responseCode?: string }).responseCode || '') : '';
}

function requestErrorMessage(error: unknown): string {
  if (isApiResponseError(error) && error.safeMessage) return error.safeMessage;
  const kind = typeof error === 'object' && error !== null && 'responseKind' in error
    ? String((error as { responseKind?: string }).responseKind || '')
    : '';
  if (kind === 'html') return 'پاسخ نامعتبر وب‌سرور یا سامانه امنیتی دریافت شد. لطفاً کمی بعد دوباره تلاش کنید.';
  if (kind === 'timeout') return 'پاسخ سرویس پیامک بیش از حد طول کشید. لطفاً کمی بعد دوباره تلاش کنید.';
  if (kind === 'invalid_json') return 'پاسخ سرور قابل پردازش نبود. لطفاً کمی بعد دوباره تلاش کنید.';
  return 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.';
}

export default function RegisterScreen() {
  const router = useRouter();
  const { signIn } = useAuth();
  const otpInput = useRef<TextInput>(null);
  const [step, setStep] = useState<RegisterStep>('form');
  const [fullName, setFullName] = useState('');
  const [mobile, setMobile] = useState('');
  const [referralCode, setReferralCode] = useState('');
  const [password, setPassword] = useState('');
  const [otp, setOtp] = useState('');
  const [generatedPin, setGeneratedPin] = useState<string | null>(null);
  const [pinCopied, setPinCopied] = useState(false);
  const [busy, setBusy] = useState(false);
  const [cooldown, setCooldown] = useState(0);
  const [error, setError] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [consentAccepted, setConsentAccepted] = useState(false);
  const [policyVisible, setPolicyVisible] = useState(false);
  const [policySection, setPolicySection] = useState<PolicySection>('terms');

  useEffect(() => {
    if (step !== 'otp' || cooldown <= 0) return undefined;
    const timer = setInterval(() => setCooldown((current) => Math.max(0, current - 1)), 1000);
    return () => clearInterval(timer);
  }, [step, cooldown]);

  useEffect(() => {
    if (step !== 'otp') return undefined;
    const focusTimer = setTimeout(() => otpInput.current?.focus(), 120);
    return () => clearTimeout(focusTimer);
  }, [step]);

  const validateForm = (): string | null => {
    if (!consentAccepted) return 'برای ادامه ثبتنام، ابتدا قوانین و سیاست حفظ حریم خصوصی را مطالعه و تأیید کنید.';
    if (!fullName.trim()) return 'نام و نام خانوادگی را وارد کنید.';
    if (!normalizeIranMobile(mobile)) return 'شماره موبایل معتبر وارد کنید.';
    if (!password) return 'رمز عبور را وارد کنید.';
    if (!passwordOk(password)) return 'رمز عبور باید حداقل ۸ کاراکتر و شامل حروف بزرگ، حروف کوچک و عدد باشد.';
    return null;
  };

  const requestOtp = async () => {
    if (busy || cooldown > 0) return;
    const validationError = validateForm();
    const normalizedMobile = normalizeIranMobile(mobile);
    if (validationError || !normalizedMobile) {
      setError(validationError || 'شماره موبایل معتبر وارد کنید.');
      return;
    }
    setError('');
    setBusy(true);
    try {
      const response = await fetchJson<any>('auth/request-otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mobile: normalizedMobile, purpose: 'registration' }),
      });
      setMobile(normalizedMobile);
      setOtp('');
      setCooldown(Number(response?.data?.resend_after || 60));
      setStep('otp');
    } catch (requestError) {
      const responseCode = readResponseCode(requestError);
      if (responseCode === 'OTP_COOLDOWN') {
        const responseData = (requestError as { responseData?: { data?: { resend_after?: number } } }).responseData;
        setCooldown(Math.max(0, Number(responseData?.data?.resend_after || 0)));
        setMobile(normalizedMobile);
        setOtp('');
        setStep('otp');
        setError('کد قبلاً ارسال شده است؛ کد دریافتی را وارد کنید.');
        return;
      }
      setError(codeMessage[responseCode] || requestErrorMessage(requestError));
    } finally {
      setBusy(false);
    }
  };

  const completeRegistration = async () => {
    if (busy) return;
    if (!consentAccepted) {
      setError('برای ادامه ثبتنام، ابتدا قوانین و سیاست حفظ حریم خصوصی را مطالعه و تأیید کنید.');
      return;
    }
    const normalizedOtp = normalizeDigits(otp);
    if (!/^\d{6}$/.test(normalizedOtp)) {
      setError('کد تأیید ۶ رقمی را وارد کنید.');
      return;
    }
    const normalizedMobile = normalizeIranMobile(mobile);
    if (!normalizedMobile) {
      setError('شماره موبایل معتبر وارد کنید.');
      return;
    }
    setError('');
    setBusy(true);
    try {
      const verified = await fetchJson<any>('auth/verify-otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ mobile: normalizedMobile, purpose: 'registration', otp: normalizedOtp }),
      });
      const registrationToken = String(verified?.data?.verification_token || '');
      if (!registrationToken) throw new Error('registration verification unavailable');

      const nameParts = fullName.trim().split(/\s+/);
      const registration = await fetchJson<any>('register.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          full_name: fullName.trim(),
          first_name: nameParts[0] || '',
          last_name: nameParts.slice(1).join(' '),
          mobile: normalizedMobile,
          password,
          ...(referralCode.trim() ? { referral_code: referralCode.trim() } : {}),
          registration_token: registrationToken,
          consent: true,
        }),
      });
      const token = String(registration?.data?.api_token || '').trim();
      const returnedPin = String(registration?.data?.pin || '');
      if (!token || !/^\d{4}$/.test(returnedPin)) throw new Error('registration session unavailable');

      await signIn({
        userToken: token,
        userMobile: normalizedMobile,
        userName: String(registration?.data?.full_name || fullName.trim()),
        userId: registration?.data?.id ? String(registration.data.id) : null,
      });
      setPassword('');
      setOtp('');
      setGeneratedPin(returnedPin);
      setStep('success');
    } catch (registrationError) {
      const responseCode = readResponseCode(registrationError);
      setError(codeMessage[responseCode] || 'ثبت‌نام انجام نشد. اطلاعات واردشده را بررسی کنید.');
    } finally {
      setBusy(false);
    }
  };

  const copyPin = async () => {
    if (!generatedPin) return;
    await Clipboard.setStringAsync(generatedPin);
    setPinCopied(true);
  };

  const enterDashboard = () => {
    setGeneratedPin(null);
    setPinCopied(false);
    router.replace('/dashboard');
  };

  return (
    <SafeAreaView style={styles.safe}>
      <Stack.Screen options={{ headerShown: false }} />
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView contentContainerStyle={styles.page} keyboardShouldPersistTaps="handled">
          <View style={styles.shell}>
            <View style={styles.brandRow}>
              <Image source={require('../assets/images/logo.png')} style={styles.logo} resizeMode="contain" />
              <View style={styles.brandText}><Text style={styles.brandName}>آفاریکس</Text><Text style={styles.brandCaption}>امن، ساده، حرفه‌ای</Text></View>
            </View>
            {step === 'success' && generatedPin ? (
              <SuccessStep pin={generatedPin} copied={pinCopied} onCopy={copyPin} onContinue={enterDashboard} />
            ) : (
              <View style={styles.card}>
                <Text style={styles.title}>{step === 'otp' ? 'کد تأیید را وارد کنید' : 'ساخت حساب آفاریکس'}</Text>
                <Text style={styles.subtitle}>{step === 'otp' ? `کد ارسال‌شده به ${maskMobile(mobile)} را وارد کنید.` : 'اطلاعات خود را وارد کنید تا حساب شما ساخته شود.'}</Text>
                {step === 'form' ? (
                  <>
                    <Field label="نام و نام خانوادگی" value={fullName} onChangeText={setFullName} placeholder="نام کامل خود را وارد کنید" />
                    <Field label="شماره موبایل" value={mobile} onChangeText={(value) => setMobile(normalizeDigits(value))} placeholder="مثلاً 09123456789" keyboardType="phone-pad" maxLength={13} />
                    <View style={styles.fieldHeader}><Text style={styles.optional}>اختیاری</Text><Text style={styles.label}>کد معرف</Text></View>
                    <TextInput style={styles.input} value={referralCode} onChangeText={setReferralCode} placeholder="در صورت داشتن کد معرف" placeholderTextColor="#9aa8b5" autoCapitalize="characters" textAlign="right" />
                    <View style={styles.field}><Text style={styles.label}>رمز عبور</Text><View style={styles.passwordBox}><TextInput style={styles.passwordInput} value={password} onChangeText={setPassword} placeholder="رمز عبور خود را وارد کنید" placeholderTextColor="#9aa8b5" secureTextEntry={!showPassword} autoCapitalize="none" textAlign="right" /><TouchableOpacity accessibilityRole="button" accessibilityLabel={showPassword ? 'مخفی کردن رمز عبور' : 'نمایش رمز عبور'} onPress={() => setShowPassword((visible) => !visible)} style={styles.eye}><Ionicons name={showPassword ? 'eye-off-outline' : 'eye-outline'} size={21} color="appColors.textSecondary" /></TouchableOpacity></View></View>
                    <View style={styles.consentRow}>
                      <TouchableOpacity
                        style={[styles.checkbox, consentAccepted && styles.checkboxChecked]}
                        onPress={() => { setConsentAccepted((accepted) => !accepted); setError(''); }}
                        accessibilityRole="checkbox"
                        accessibilityState={{ checked: consentAccepted }}
                        accessibilityLabel="پذیرش قوانین و سیاست حفظ حریم خصوصی">
                        {consentAccepted && <Ionicons name="checkmark" size={17} color="#fff" />}
                      </TouchableOpacity>
                      <Text style={styles.consentText}>با <Text style={styles.policyLink} onPress={() => { setPolicySection('terms'); setPolicyVisible(true); }}>قوانین و شرایط استفاده</Text> و <Text style={styles.policyLink} onPress={() => { setPolicySection('privacy'); setPolicyVisible(true); }}>سیاست حفظ حریم خصوصی</Text> آفارایکس موافقم.</Text>
                    </View>
                  </>
                ) : (
                  <>
                    <View style={styles.otpNotice}><Ionicons name="shield-checkmark-outline" size={22} color="appColors.primary" /><Text style={styles.otpNoticeText}>کد تأیید پیامکی را وارد کنید.</Text></View>
                    <Field inputRef={otpInput} label="کد تأیید" value={otp} onChangeText={(value) => setOtp(normalizeDigits(value).replace(/\D/g, '').slice(0, 6))} placeholder="کد ۶ رقمی" keyboardType="number-pad" maxLength={6} autoFocus />
                    <View style={styles.resendRow}><Text style={styles.cooldown}>{cooldown > 0 ? `ارسال مجدد تا ${cooldown} ثانیه` : 'کد را دریافت نکردید؟'}</Text><TouchableOpacity disabled={cooldown > 0 || busy} onPress={requestOtp}><Text style={[styles.resend, cooldown > 0 && styles.disabledText]}>ارسال مجدد</Text></TouchableOpacity></View>
                  </>
                )}
                {!!error && <Text style={styles.error} accessibilityLiveRegion="polite">{error}</Text>}
                <TouchableOpacity style={[styles.primaryButton, busy && styles.buttonDisabled]} onPress={step === 'form' ? requestOtp : completeRegistration} disabled={busy} accessibilityRole="button">
                  {busy ? <><ActivityIndicator color="#fff" /><Text style={styles.buttonText}>{step === 'form' ? 'در حال ارسال...' : 'در حال ساخت حساب...'}</Text></> : <Text style={styles.buttonText}>{step === 'form' ? 'ارسال کد تأیید' : 'تأیید و ساخت حساب'}</Text>}
                </TouchableOpacity>
                <TouchableOpacity onPress={() => router.replace('/login')} style={styles.loginLink}><Text style={styles.loginLinkText}>حساب دارید؟ <Text style={styles.loginLinkStrong}>ورود</Text></Text></TouchableOpacity>
              </View>
            )}
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
      <RegistrationPolicyModal
        visible={policyVisible}
        initialSection={policySection}
        onBack={() => setPolicyVisible(false)}
        onAccept={() => { setConsentAccepted(true); setError(''); setPolicyVisible(false); }}
      />
    </SafeAreaView>
  );
}

function SuccessStep({ pin, copied, onCopy, onContinue }: { pin: string; copied: boolean; onCopy: () => void; onContinue: () => void }) {
  return <View style={styles.card}>
    <View style={styles.successIcon}><Ionicons name="checkmark" size={30} color="#fff" /></View>
    <Text style={styles.title}>ثبت‌نام شما با موفقیت انجام شد</Text>
    <Text style={styles.successText}>پین ورود به بخش‌های حساس حساب شما ساخته شد. آن را در جای امن نگهداری کنید.</Text>
    <View style={styles.pinCard}><Text style={styles.pinLabel}>پین امنیتی شما</Text><Text style={styles.pinValue} accessibilityLabel="پین امنیتی">{pin}</Text><Text style={styles.pinWarning}>این پین فقط یک‌بار نمایش داده می‌شود.</Text></View>
    <TouchableOpacity style={styles.copyButton} onPress={onCopy} accessibilityRole="button"><Ionicons name="copy-outline" size={19} color="#0c8f70" /><Text style={styles.copyText}>{copied ? 'پین کپی شد' : 'کپی پین'}</Text></TouchableOpacity>
    <TouchableOpacity style={styles.primaryButton} onPress={onContinue} accessibilityRole="button"><Text style={styles.buttonText}>ورود به داشبورد</Text></TouchableOpacity>
  </View>;
}

function Field({ label, value, onChangeText, placeholder, keyboardType, maxLength, inputRef, autoFocus }: { label: string; value: string; onChangeText: (value: string) => void; placeholder?: string; keyboardType?: 'phone-pad' | 'number-pad'; maxLength?: number; inputRef?: React.RefObject<TextInput | null>; autoFocus?: boolean }) {
  return <View style={styles.field}><Text style={styles.label}>{label}</Text><TextInput ref={inputRef} style={styles.input} value={value} onChangeText={onChangeText} placeholder={placeholder} placeholderTextColor="#9aa8b5" keyboardType={keyboardType} maxLength={maxLength} autoFocus={autoFocus} autoCapitalize="none" textAlign="right" /></View>;
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: appColors.background }, flex: { flex: 1 }, page: { flexGrow: 1, padding: 20, justifyContent: 'center' }, shell: { width: '100%', maxWidth: 500, alignSelf: 'center' }, brandRow: { flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'center', marginBottom: 18, gap: 10 }, logo: { width: 44, height: 44, borderRadius: 13 }, brandText: { alignItems: 'flex-end' }, brandName: { fontFamily: 'VazirmatnBold', color: appColors.text, fontSize: 21 }, brandCaption: { fontFamily: 'Vazirmatn', color: appColors.textSecondary, fontSize: 10, marginTop: 1 }, card: { width: '100%', paddingVertical: 2 }, title: { fontFamily: 'VazirmatnBold', color: appColors.text, fontSize: 22, lineHeight: 34, textAlign: 'right' }, subtitle: { fontFamily: 'Vazirmatn', color: appColors.textMuted, fontSize: 13, lineHeight: 23, textAlign: 'right', marginTop: 4, marginBottom: 13 }, field: { marginTop: 14 }, fieldHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 14, marginBottom: 7 }, label: { fontFamily: 'VazirmatnBold', color: '#334e5b', fontSize: 12, textAlign: 'right' }, optional: { fontFamily: 'Vazirmatn', color: '#8a9ba2', fontSize: 11 }, input: { width: '100%', minHeight: 49, borderWidth: 1, borderColor: appColors.surfaceRaised, borderRadius: 13, paddingHorizontal: 14, fontFamily: 'Vazirmatn', color: appColors.text, fontSize: 13, backgroundColor: appColors.surfaceRaised }, passwordBox: { position: 'relative', justifyContent: 'center' }, passwordInput: { minHeight: 49, borderWidth: 1, borderColor: appColors.surfaceRaised, borderRadius: 13, paddingHorizontal: 46, fontFamily: 'Vazirmatn', color: appColors.text, fontSize: 13, backgroundColor: appColors.surfaceRaised }, eye: { position: 'absolute', left: 12, height: '100%', width: 34, alignItems: 'center', justifyContent: 'center' }, consentRow: { flexDirection: 'row-reverse', alignItems: 'flex-start', gap: 10, marginTop: 16 }, checkbox: { width: 23, height: 23, borderRadius: 6, borderWidth: 1.5, borderColor: '#9aabb1', backgroundColor: appColors.surface, alignItems: 'center', justifyContent: 'center', marginTop: 1 }, checkboxChecked: { backgroundColor: appColors.primary, borderColor: appColors.primary }, consentText: { flex: 1, fontFamily: 'Vazirmatn', color: appColors.textSecondary, fontSize: 11, lineHeight: 23, textAlign: 'right', writingDirection: 'rtl' }, policyLink: { fontFamily: 'VazirmatnBold', color: appColors.primary, textDecorationLine: 'underline' }, error: { fontFamily: 'Vazirmatn', color: '#c24142', fontSize: 12, textAlign: 'right', lineHeight: 20, marginTop: 12 }, primaryButton: { minHeight: 52, borderRadius: 14, backgroundColor: appColors.primary, alignItems: 'center', justifyContent: 'center', flexDirection: 'row-reverse', gap: 8, marginTop: 20 }, buttonDisabled: { opacity: 0.72 }, buttonText: { fontFamily: 'VazirmatnBold', color: '#fff', fontSize: 14 }, loginLink: { alignItems: 'center', marginTop: 18 }, loginLinkText: { fontFamily: 'Vazirmatn', color: appColors.textMuted, fontSize: 12 }, loginLinkStrong: { color: appColors.primary, fontFamily: 'VazirmatnBold' }, otpNotice: { flexDirection: 'row-reverse', alignItems: 'center', gap: 8, backgroundColor: '#eefaf6', borderRadius: 12, padding: 12, marginTop: 4 }, otpNoticeText: { flex: 1, fontFamily: 'Vazirmatn', color: '#237467', fontSize: 12, textAlign: 'right' }, resendRow: { flexDirection: 'row-reverse', justifyContent: 'space-between', alignItems: 'center', marginTop: 14 }, cooldown: { fontFamily: 'Vazirmatn', color: '#82949b', fontSize: 11 }, resend: { fontFamily: 'VazirmatnBold', color: appColors.primary, fontSize: 12 }, disabledText: { color: '#aab7ba' }, successIcon: { width: 58, height: 58, borderRadius: 29, backgroundColor: appColors.primary, alignSelf: 'center', alignItems: 'center', justifyContent: 'center', marginBottom: 14 }, successText: { fontFamily: 'Vazirmatn', color: '#667d86', fontSize: 13, lineHeight: 25, textAlign: 'right', marginTop: 10 }, pinCard: { marginTop: 18, borderRadius: 16, backgroundColor: appColors.surfaceRaised, borderWidth: 1, borderColor: '#cceee3', padding: 17, alignItems: 'center' }, pinLabel: { fontFamily: 'Vazirmatn', color: '#5b7e78', fontSize: 11 }, pinValue: { fontFamily: 'VazirmatnBold', color: '#135a54', fontSize: 30, letterSpacing: 8, marginTop: 5 }, pinWarning: { fontFamily: 'Vazirmatn', color: '#8a7770', fontSize: 10, textAlign: 'center', marginTop: 8 }, copyButton: { minHeight: 44, borderRadius: 12, borderWidth: 1, borderColor: '#bce5da', flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'center', gap: 7, marginTop: 13 }, copyText: { fontFamily: 'VazirmatnBold', color: '#0c8f70', fontSize: 12 },
});

Object.assign(styles as Record<string, unknown>, {
  brandRow: { ...(styles as any).brandRow, flexDirection: 'row', gap: 0 },
  brandText: { ...(styles as any).brandText, display: 'none' },
  title: { ...(styles as any).title, textAlign: 'center' },
  subtitle: { ...(styles as any).subtitle, textAlign: 'center' },
});
