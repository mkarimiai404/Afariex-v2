import { Ionicons } from '@expo/vector-icons';
import { Stack, useRouter } from 'expo-router';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Modal, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { TopupReceiptView } from '@/components/topup-receipt-view';
import { AppBottomNav } from '@/components/app-bottom-nav';
import { fetchJson, isApiResponseError } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { moneyInputWords, normalizeMoneyInput } from '@/lib/money-input';
import { useMoneyInput } from '@/hooks/use-money-input';
import { appColors } from '@/lib/theme';
import { topupOperatorLabel, TopupReceiptModel } from '@/lib/topup-receipt';
import { clearPendingTopup, loadPendingTopup, savePendingTopup } from '@/lib/topup-pending';

type Operator = { title: string; min_amount: number; max_amount: number };
type OperatorsResponse = { data?: { operators?: Record<string, Operator> } };
type TopupStatus = 'success' | 'failed' | 'pending';
type ChargeType = 'normal' | 'amazing';
type FeePreview = { fee_amount: number; total_amount: number };

const fallbackOperators: Record<string, Operator> = {
  MCI: { title: topupOperatorLabel('MCI'), min_amount: 20000, max_amount: 500000 },
  MTN: { title: topupOperatorLabel('MTN'), min_amount: 22000, max_amount: 1000000 },
  RTL: { title: topupOperatorLabel('RTL'), min_amount: 22000, max_amount: 500000 },
};

const normalizeDigits = (value: string) => value
  .replace(/[\u06F0-\u06F9]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0))
  .replace(/[\u0660-\u0669]/g, (digit) => String(digit.charCodeAt(0) - 0x0660));

const normalizeIranMobile = (value: string) => {
  let normalized = normalizeDigits(value).replace(/[\s().-]/g, '');
  if (normalized.startsWith('+98')) normalized = '0' + normalized.slice(3);
  else if (normalized.startsWith('0098')) normalized = '0' + normalized.slice(4);
  else if (normalized.startsWith('98')) normalized = '0' + normalized.slice(2);
  return normalized;
};

const detectOperator = (value: string): string | null => {
  const prefix = normalizeIranMobile(value).replace(/\D/g, '').slice(0, 4);
  if (/^092[0-3]$/.test(prefix) || prefix === '0926') return 'RTL';
  if (/^091[0-9]$/.test(prefix) || /^099[0-4]$/.test(prefix)) return 'MCI';
  if (/^090[0-5]$/.test(prefix) || /^093[0-9]$/.test(prefix) || prefix === '0941') return 'MTN';
  return null;
};

const fetchWalletBalance = async (userId: string, userToken: string): Promise<number | null> => {
  const payload = new URLSearchParams({ user_id: userId, id: userId, api_token: userToken, token: userToken, user_token: userToken });
  const response = await fetchJson<any>('dashboard.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: payload.toString() });
  const rawBalance = response?.balance ?? response?.user?.balance ?? response?.data?.balance ?? response?.result?.balance;
  const balance = Number(String(rawBalance ?? '').replace(/[٬,\s]/g, ''));
  return Number.isFinite(balance) ? balance : null;
};

const createClientRequestId = () => {
  const bytes = new Uint8Array(16);
  if (globalThis.crypto?.getRandomValues) globalThis.crypto.getRandomValues(bytes);
  else bytes.forEach((_, index) => { bytes[index] = Math.floor(Math.random() * 256); });
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hexadecimal = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
  return `${hexadecimal.slice(0, 8)}-${hexadecimal.slice(8, 12)}-${hexadecimal.slice(12, 16)}-${hexadecimal.slice(16, 20)}-${hexadecimal.slice(20)}`;
};

export default function TopupScreen() {
  const router = useRouter();
  const { userId, userToken, isAuthenticated, isInitialized } = useAuth();
  const [operators, setOperators] = useState<Record<string, Operator>>(fallbackOperators);
  const [operator, setOperator] = useState('MCI');
  const [chargeType, setChargeType] = useState<ChargeType>('normal');
  const [mobile, setMobile] = useState('');
  const [walletBalance, setWalletBalance] = useState<number | null>(null);
  const [balanceLoading, setBalanceLoading] = useState(false);
  const [feePreview, setFeePreview] = useState<FeePreview | null>(null);
  const [feeLoading, setFeeLoading] = useState(false);
  const [feeError, setFeeError] = useState(false);
  const amountInput = useMoneyInput();
  const amount = amountInput.value;
  const setAmount = amountInput.setFormattedValue;
  const [step, setStep] = useState<'form' | 'confirm' | 'result'>('form');
  const [loading, setLoading] = useState(true);
  const [pendingRestoreLoading, setPendingRestoreLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<TopupReceiptModel | null>(null);
  const clientRequestId = useRef<string | null>(null);
  const resultOwnerId = useRef<string | null>(null);
  const operatorManuallySelected = useRef(false);
  const statusRequestInFlight = useRef(false);
  const pollingWindow = useRef<{ key: string; startedAt: number } | null>(null);

  useEffect(() => {
    if (isInitialized && !isAuthenticated) router.replace('/login' as any);
  }, [isAuthenticated, isInitialized, router]);

  useEffect(() => {
    if (!userId || !userToken) return;
    let active = true;
    setPendingRestoreLoading(true);
    resultOwnerId.current = null;
    setResult(null);
    setStep('form');
    clientRequestId.current = null;
    loadPendingTopup(userId).then((pending) => {
      if (!active || !pending) return;
      resultOwnerId.current = userId;
      clientRequestId.current = pending.clientRequestId || null;
      setResult(pending);
      setStep('result');
    }).catch(() => undefined).finally(() => { if (active) setPendingRestoreLoading(false); });
    return () => { active = false; };
  }, [userId, userToken]);

  useEffect(() => {
    if (!userId || !result || resultOwnerId.current !== userId) return;
    const storageOperation = result.status === 'pending'
      ? savePendingTopup(userId, result)
      : clearPendingTopup(userId);
    storageOperation.catch(() => undefined);
  }, [result, userId]);

  useEffect(() => {
    if (!userToken) return;
    let active = true;
    setLoading(true);
    fetchJson<OperatorsResponse>('topup-operators.php', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${userToken}` }, body: JSON.stringify({ api_token: userToken }) })
      .then((response) => { const remote = response?.data?.operators; if (active && remote && Object.keys(remote).length) setOperators(remote); })
      .catch(() => undefined)
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [userToken]);

  useEffect(() => {
    if (!userToken || !userId) return;
    let active = true;
    setBalanceLoading(true);
    fetchWalletBalance(userId, userToken)
      .then((balance) => { if (active && balance !== null) setWalletBalance(balance); })
      .catch((error) => { if (__DEV__) console.log('[AFARIEX-DEBUG] topup wallet balance unavailable', { error: error instanceof Error ? error.name : 'unknown' }); })
      .finally(() => { if (active) setBalanceLoading(false); });
    return () => { active = false; };
  }, [userId, userToken]);

  const selectedOperator = operators[operator] || fallbackOperators[operator];
  const quickAmounts = useMemo(() => [20000, 50000, 100000, 200000], []);
  const cleanMobile = normalizeIranMobile(mobile);
  const cleanAmount = normalizeMoneyInput(amount);
  const numericAmount = Number(cleanAmount) || 0;
  const totalCost = feePreview?.total_amount ?? numericAmount;
  const balanceSufficient = walletBalance !== null && feePreview !== null && walletBalance >= totalCost;

  const updateMobile = (value: string) => {
    setMobile(value);
    const detected = detectOperator(value);
    if (detected && !operatorManuallySelected.current) setOperator(detected);
  };

  const refreshPreflightBalance = () => {
    if (!userId || !userToken) return;
    setWalletBalance(null);
    setBalanceLoading(true);
    setFeePreview(null);
    setFeeError(false);
    setFeeLoading(true);
    fetchWalletBalance(userId, userToken)
      .then((balance) => { if (balance !== null) setWalletBalance(balance); })
      .catch((error) => { if (__DEV__) console.log('[AFARIEX-DEBUG] preflight balance refresh failed', { error: error instanceof Error ? error.name : 'unknown' }); })
      .finally(() => setBalanceLoading(false));

    const payload = new URLSearchParams({ api_token: userToken, service_code: 'mobile_recharge', amount: String(numericAmount) });
    fetchJson<any>('service-fee-preview.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: payload.toString(),
    })
      .then((response) => {
        const fee = response?.data?.fee;
        const feeAmount = Number(fee?.fee_amount);
        const feeTotal = Number(fee?.total_amount);
        if (!Number.isFinite(feeAmount) || feeAmount < 0 || !Number.isFinite(feeTotal) || feeTotal < numericAmount) {
          throw new Error('Invalid mobile recharge fee preview');
        }
        setFeePreview({ fee_amount: feeAmount, total_amount: feeTotal });
      })
      .catch((error) => {
        if (__DEV__) console.log('[AFARIEX-DEBUG] topup fee preview unavailable', { error: error instanceof Error ? error.name : 'unknown' });
        setFeeError(true);
      })
      .finally(() => setFeeLoading(false));
  };

  const openConfirmation = () => {
    const error = validate();
    if (error) {
      Alert.alert('اطلاعات ناقص', error);
      return;
    }
    clientRequestId.current = createClientRequestId();
    setStep('confirm');
    refreshPreflightBalance();
  };

  const validate = () => {
    if (!/^09\d{9}$/.test(cleanMobile)) return 'شماره موبایل باید به شکل ۰۹xxxxxxxxx باشد.';
    const numericAmount = Number(cleanAmount);
    if (!Number.isInteger(numericAmount) || numericAmount < selectedOperator.min_amount || numericAmount > selectedOperator.max_amount) return `مبلغ باید بین ${selectedOperator.min_amount.toLocaleString('fa-IR')} تا ${selectedOperator.max_amount.toLocaleString('fa-IR')} تومان باشد.`;
    return '';
  };

  const submit = async () => {
    if (!userToken || !userId || submitting) return;
    setSubmitting(true);
    resultOwnerId.current = userId;
    clientRequestId.current ??= createClientRequestId();
    const requestId = clientRequestId.current;
    const pendingReceipt: TopupReceiptModel = {
      transactionId: '', clientRequestId: requestId, mobile: cleanMobile, operator,
      operatorLabel: topupOperatorLabel(operator), amount: numericAmount,
      commissionAmount: feePreview?.fee_amount ?? 0,
      totalAmount: feePreview?.total_amount ?? numericAmount,
      chargeType, status: 'pending', createdAt: new Date().toISOString(),
      message: 'پاسخ درخواست هنوز دریافت نشده است؛ وضعیت آن با همین شناسه پیگیری می‌شود.',
    };
    try {
      // Store the id before sending; after a disconnect, status lookup is safer than resubmitting.
      await savePendingTopup(userId, pendingReceipt).catch(() => undefined);
      if (__DEV__) console.log('[AFARIEX-DEBUG] topup submit started', { client_request_id: requestId, operator, charge_type: chargeType, amount: numericAmount });
      const response = await fetchJson<any>('topup-create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${userToken}` },
        body: JSON.stringify({ api_token: userToken, phone: cleanMobile, operator, amount: numericAmount, charge_type: chargeType, client_request_id: requestId }),
      });
      if (__DEV__) console.log('[AFARIEX-DEBUG] topup submit response', { client_request_id: requestId, status: response?.status, code: response?.code });
      const receipt = toReceipt(response, cleanMobile, operator, numericAmount, requestId, chargeType, '', pendingReceipt.createdAt);
      setResult(receipt);
      const updatedBalance = Number(response?.data?.remaining_balance ?? response?.data?.balance);
      if (Number.isFinite(updatedBalance)) setWalletBalance(updatedBalance);
      setStep('result');
    } catch (error) {
      const payload = isApiResponseError(error) && error.responseData && !Array.isArray(error.responseData) ? error.responseData as Record<string, any> : null;
      if (isApiResponseError(error) && error.status === 409) {
        const existingTransactionId = String((payload?.data as Record<string, unknown> | undefined)?.transaction_id ?? '');
        if (existingTransactionId) {
          try {
            const existing = await fetchJson<any>('topup-status.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${userToken}` },
              body: JSON.stringify({ api_token: userToken, transaction_id: existingTransactionId }),
            });
            setResult(toReceipt(existing, cleanMobile, operator, numericAmount, clientRequestId.current, chargeType, '', pendingReceipt.createdAt));
            setStep('result');
          } catch {
            Alert.alert('درخواست تکراری', 'شناسهٔ این درخواست قبلاً استفاده شده است؛ وضعیت تراکنش موجود را دوباره بررسی کنید.');
          }
        } else {
          Alert.alert('درخواست تکراری', payload?.message || 'شناسهٔ درخواست قبلاً استفاده شده است.');
        }
        return;
      }
      if (payload?.status) {
        setResult(toReceipt(payload, cleanMobile, operator, numericAmount, clientRequestId.current, chargeType, '', pendingReceipt.createdAt));
        setStep('result');
      } else if (!isApiResponseError(error) || error.status >= 500) {
        if (__DEV__) console.log('[AFARIEX-DEBUG] topup submission outcome uncertain', { client_request_id: clientRequestId.current, error: error instanceof Error ? error.name : 'unknown' });
        const timedOut = error instanceof Error && error.name === 'ApiTimeoutError';
        setResult({ ...pendingReceipt, message: timedOut
          ? 'پاسخ نهایی در زمان تعیین‌شده دریافت نشد. ممکن است درخواست همچنان در حال پردازش باشد. برای جلوگیری از خرید تکراری، دوباره پرداخت نکنید و وضعیت تراکنش را بررسی کنید.'
          : 'پاسخ سرور دریافت نشد و نتیجه درخواست نامشخص است. برای جلوگیری از خرید تکراری، وضعیت تراکنش را با همین شناسه بررسی کنید.' });
        setStep('result');
      } else {
        clearPendingTopup(userId).catch(() => undefined);
        Alert.alert('خطا', payload?.message || 'خرید شارژ انجام نشد.');
      }
    } finally { setSubmitting(false); }
  };

  const checkStatus = async () => {
    if (!userToken || !result || (!result.transactionId && !result.clientRequestId) || submitting || statusRequestInFlight.current) return;
    statusRequestInFlight.current = true;
    setSubmitting(true);
    try {
      if (__DEV__) console.log('[AFARIEX-DEBUG] topup status check started', { transaction_id: result.transactionId || null, client_request_id: result.clientRequestId || null });
      const response = await fetchJson<any>('topup-status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${userToken}` },
        body: JSON.stringify({ api_token: userToken, transaction_id: result.transactionId || undefined, client_request_id: result.clientRequestId || clientRequestId.current || undefined }),
      });
      if (__DEV__) console.log('[AFARIEX-DEBUG] topup status response', { status: response?.status, code: response?.code });
      const updated = toReceipt(response, result.mobile, result.operator, result.amount, result.clientRequestId, result.chargeType, result.transactionId, result.createdAt);
      setResult(updated);
      const updatedBalance = Number(response?.data?.remaining_balance ?? response?.data?.balance);
      if (Number.isFinite(updatedBalance)) setWalletBalance(updatedBalance);
    } catch (error) {
      const payload = isApiResponseError(error) && error.responseData && !Array.isArray(error.responseData) ? error.responseData as Record<string, any> : null;
      if (payload?.status) setResult(toReceipt(payload, result.mobile, result.operator, result.amount, result.clientRequestId, result.chargeType, result.transactionId, result.createdAt));
      else if (__DEV__) console.log('[AFARIEX-DEBUG] topup status check unavailable', { error: error instanceof Error ? error.name : 'unknown' });
      if (!payload?.status) Alert.alert('وضعیت نامشخص', 'فعلاً امکان دریافت نتیجه نیست. درخواست همچنان در انتظار بررسی است؛ دوباره تلاش کنید.');
    } finally { statusRequestInFlight.current = false; setSubmitting(false); }
  };
  useEffect(() => {
    if (step !== 'result' || result?.status !== 'pending' || !userToken) return;

    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let pollIndex = 0;
    const pollingKey = result.clientRequestId || result.transactionId;
    if (pollingWindow.current?.key !== pollingKey) {
      pollingWindow.current = { key: pollingKey, startedAt: Date.now() };
    }
    const startedAt = pollingWindow.current.startedAt;
    const delays = [3000, 5000, 10000];
    const maxWindowMs = 120000;
    const maxStatusCallMs = 45000;

    const scheduleNext = () => {
      const elapsed = Date.now() - startedAt;
      if (stopped) return;
        if (elapsed + maxStatusCallMs >= maxWindowMs) {
        setResult((current) => current?.status === 'pending'
          ? { ...current, message: '\u062e\u0631\u06cc\u062f \u0647\u0646\u0648\u0632 \u062f\u0631 \u0627\u0646\u062a\u0638\u0627\u0631 \u067e\u0627\u0633\u062e \u0633\u0631\u0648\u06cc\u0633 \u0627\u0633\u062a. \u0628\u0639\u062f\u0627\u064b \u0648\u0636\u0639\u06cc\u062a \u0631\u0627 \u0628\u0627 \u0647\u0645\u06cc\u0646 \u0634\u0646\u0627\u0633\u0647 \u0628\u0631\u0631\u0633\u06cc \u06a9\u0646\u06cc\u062f.' }
          : current);
        return;
      }

      const delay = Math.min(delays[Math.min(pollIndex, delays.length - 1)], maxWindowMs - maxStatusCallMs - elapsed);
      pollIndex += 1;
      timer = setTimeout(async () => {
        if (stopped) return;
        if (statusRequestInFlight.current) {
          scheduleNext();
          return;
        }

        statusRequestInFlight.current = true;
        let updatedStatus: TopupStatus | null = null;
        try {
          const response = await fetchJson<any>('topup-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + userToken },
            body: JSON.stringify({
              api_token: userToken,
              transaction_id: result.transactionId || undefined,
              client_request_id: result.clientRequestId || undefined,
            }),
          });
          if (['success', 'failed', 'pending'].includes(response?.status)) {
            const updated = toReceipt(response, result.mobile, result.operator, result.amount, result.clientRequestId, result.chargeType, result.transactionId, result.createdAt);
            setResult(updated);
            updatedStatus = updated.status;
            const updatedBalance = Number(response?.data?.remaining_balance ?? response?.data?.balance);
            if (Number.isFinite(updatedBalance)) setWalletBalance(updatedBalance);
          }
        } catch (error) {
          const payload = isApiResponseError(error) && error.responseData && !Array.isArray(error.responseData)
            ? error.responseData as Record<string, any>
            : null;
          if (['success', 'failed', 'pending'].includes(payload?.status)) {
            const updated = toReceipt(payload, result.mobile, result.operator, result.amount, result.clientRequestId, result.chargeType, result.transactionId, result.createdAt);
            setResult(updated);
            updatedStatus = updated.status;
          }
        } finally {
          statusRequestInFlight.current = false;
        }

        if (!stopped && (updatedStatus === 'pending' || updatedStatus === null)) scheduleNext();
      }, delay);
    };

    scheduleNext();
    return () => {
      stopped = true;
      if (timer) clearTimeout(timer);
    };
  }, [step, result?.status, result?.transactionId, result?.clientRequestId, result?.mobile, result?.operator, result?.amount, result?.chargeType, result?.createdAt, userToken]);


  if (!isInitialized || loading || pendingRestoreLoading) return <SafeAreaView style={styles.safe}><ActivityIndicator color={appColors.primary} /></SafeAreaView>;
  return <SafeAreaView style={styles.safe}>
    <Stack.Screen options={{ headerShown: false }} />
    <View style={styles.container}>
      <View style={styles.header}><TouchableOpacity onPress={() => router.back()} style={styles.back}><Ionicons name="arrow-forward" size={23} color={appColors.text} /></TouchableOpacity><Text style={styles.title}>خرید شارژ موبایل</Text><View style={styles.headerSpacer} /></View>
      <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
        {step === 'form' && <>
          <Text style={styles.intro}>شارژ مستقیم سیم‌کارت خود یا دیگران را با موجودی کیف پول خریداری کنید.</Text>
          <Text style={styles.label}>شماره موبایل</Text>
          <TextInput value={mobile} onChangeText={updateMobile} placeholder="۰۹xxxxxxxxx" placeholderTextColor={appColors.textMuted} keyboardType="phone-pad" style={styles.input} maxLength={16} textAlign="right" />
          <Text style={styles.label}>اپراتور</Text>
          {!!detectOperator(cleanMobile) && <Text style={styles.detectedOperator}>اپراتور پیشنهادی بر اساس پیش‌شماره: {topupOperatorLabel(detectOperator(cleanMobile) ?? '')} (در صورت نیاز دستی تغییر دهید)</Text>}
          <View style={styles.operatorRow}>{Object.entries(operators).map(([key, item]) => <TouchableOpacity key={key} onPress={() => { operatorManuallySelected.current = true; setOperator(key); }} style={[styles.operator, operator === key && styles.operatorActive]}><Text style={[styles.operatorText, operator === key && styles.operatorTextActive]}>{item.title}</Text></TouchableOpacity>)}</View>
          <Text style={styles.label}>نوع شارژ</Text>
          <View style={styles.operatorRow}>
            <TouchableOpacity onPress={() => setChargeType('normal')} style={[styles.operator, chargeType === 'normal' && styles.operatorActive]}><Text style={[styles.operatorText, chargeType === 'normal' && styles.operatorTextActive]}>عادی</Text></TouchableOpacity>
            <TouchableOpacity onPress={() => setChargeType('amazing')} style={[styles.operator, chargeType === 'amazing' && styles.operatorActive]}><Text style={[styles.operatorText, chargeType === 'amazing' && styles.operatorTextActive]}>شگفت‌انگیز / مستقیم</Text></TouchableOpacity>
          </View>
          <Text style={styles.label}>مبلغ به تومان</Text>
          <View style={styles.quickRow}>{quickAmounts.map((value) => <TouchableOpacity key={value} onPress={() => setAmount(String(value))} style={[styles.quick, Number(cleanAmount) === value && styles.quickActive]}><Text style={[styles.quickText, Number(cleanAmount) === value && styles.quickTextActive]}>{value.toLocaleString('fa-IR')}</Text></TouchableOpacity>)}</View>
          <TextInput value={amount} selection={amountInput.selection} onSelectionChange={amountInput.onSelectionChange} onChangeText={amountInput.onChangeText} placeholder="مبلغ دلخواه (تومان)" placeholderTextColor={appColors.textMuted} keyboardType="number-pad" style={styles.input} textAlign="right" />
          {!!cleanAmount && <Text style={styles.words}>{moneyInputWords(cleanAmount)}</Text>}
          <TouchableOpacity style={styles.primaryButton} onPress={openConfirmation}><Text style={styles.primaryText}>ادامه و تأیید</Text></TouchableOpacity>
        </>}
      {step === 'result' && result && <><TopupReceiptView receipt={result} />{result.status === 'pending' && <><TouchableOpacity style={styles.primaryButton} onPress={checkStatus} disabled={submitting}>{submitting ? <ActivityIndicator color={appColors.background} /> : <Text style={styles.primaryText}>بررسی وضعیت تراکنش</Text>}</TouchableOpacity><TouchableOpacity style={styles.secondaryButton} onPress={checkStatus} disabled={submitting}><Text style={styles.secondaryText}>بازیابی درخواست با همان شناسه</Text></TouchableOpacity></>}{result.status !== 'pending' && <TouchableOpacity style={styles.secondaryButton} onPress={() => { clientRequestId.current = null; operatorManuallySelected.current = false; setResult(null); setMobile(''); setAmount(''); setChargeType('normal'); setStep('form'); }}><Text style={styles.secondaryText}>خرید شارژ جدید</Text></TouchableOpacity>}</>}
      </ScrollView>
      <Modal visible={step === 'confirm'} transparent animationType="fade" onRequestClose={() => setStep('form')}>
        <View style={styles.modalBackdrop}>
          <ScrollView style={styles.modalCard} contentContainerStyle={styles.modalContent} keyboardShouldPersistTaps="handled">
            <Text style={styles.sectionTitle}>تأیید خرید شارژ</Text>
            <View style={styles.summary}>
              <SummaryRow label="شماره موبایل" value={cleanMobile} />
              <SummaryRow label="اپراتور" value={selectedOperator.title} />
              <SummaryRow label="نوع شارژ" value={chargeType === 'normal' ? 'عادی' : 'شگفت‌انگیز / مستقیم'} />
              <SummaryRow label="مبلغ شارژ" value={`${numericAmount.toLocaleString('fa-IR')} تومان`} />
              <SummaryRow label="سهم کارمزد" value={feeLoading ? 'در حال محاسبه…' : feePreview === null ? 'نامشخص' : `${feePreview.fee_amount.toLocaleString('fa-IR')} تومان`} />
              <SummaryRow label="مبلغ نهایی کسرشده" value={feePreview === null ? 'نامشخص' : `${totalCost.toLocaleString('fa-IR')} تومان`} />
              <SummaryRow label="موجودی کیف پول" value={balanceLoading ? 'در حال دریافت…' : walletBalance === null ? 'نامشخص' : `${walletBalance.toLocaleString('fa-IR')} تومان`} />
              <SummaryRow label="وضعیت موجودی" value={balanceLoading || feeLoading ? 'در حال بررسی…' : walletBalance === null || feePreview === null ? 'دریافت نشده' : balanceSufficient ? 'کافی' : 'ناکافی'} last />
            </View>
            {feeError && <TouchableOpacity onPress={refreshPreflightBalance}><Text style={styles.balanceHint}>محاسبه کارمزد انجام نشد؛ برای تلاش دوباره لمس کنید.</Text></TouchableOpacity>}
            {walletBalance === null && !balanceLoading && <TouchableOpacity onPress={refreshPreflightBalance}><Text style={styles.balanceHint}>موجودی دریافت نشد؛ برای تلاش دوباره لمس کنید.</Text></TouchableOpacity>}
            {walletBalance !== null && feePreview !== null && !balanceSufficient && <Text style={styles.insufficient}>موجودی کیف پول برای این خرید کافی نیست.</Text>}
            <Text style={styles.notice}>مبلغ شارژ و کارمزد به تومان از کیف پول کسر می‌شود. اگر پاسخ شبکه قطع شود، درخواست با همین شناسه قابل پیگیری است.</Text>
            <TouchableOpacity style={[styles.primaryButton, (balanceLoading || feeLoading || feePreview === null || !balanceSufficient || submitting) && styles.disabledButton]} onPress={submit} disabled={balanceLoading || feeLoading || feePreview === null || !balanceSufficient || submitting}>
              {submitting ? <ActivityIndicator color={appColors.background} /> : <Text style={styles.primaryText}>تأیید و خرید</Text>}
            </TouchableOpacity>
            <TouchableOpacity style={styles.secondaryButton} onPress={() => setStep('form')}><Text style={styles.secondaryText}>بازگشت و ویرایش</Text></TouchableOpacity>
          </ScrollView>
        </View>
      </Modal>
      <AppBottomNav />
    </View>
  </SafeAreaView>;
}

function toReceipt(payload: any, mobile: string, operator: string, amount: number, fallbackClientRequestId?: string | null, fallbackChargeType: ChargeType = 'normal', fallbackTransactionId = '', fallbackCreatedAt?: string): TopupReceiptModel {
  const status = (payload?.status === 'success' || payload?.status === 'failed' || payload?.status === 'pending' ? payload.status : 'pending') as TopupStatus;
  const data = payload?.data || {};
  const reference = data.ref_code ?? data.trans_id ?? data.ref_id;
  const commissionAmount = Number(data.commission_amount || 0);
  const receiptAmount = Number(data.amount || amount);
  return { transactionId: String(data.transaction_id || fallbackTransactionId), clientRequestId: String(data.client_request_id || fallbackClientRequestId || '') || undefined, mobile: String(data.mobile || data.phone || mobile), operator: String(data.operator || operator), operatorLabel: topupOperatorLabel(String(data.operator || operator)), amount: receiptAmount, commissionAmount, totalAmount: Number(data.total_amount ?? receiptAmount + commissionAmount), chargeType: (data.charge_type === 'amazing' ? 'amazing' : data.charge_type === 'normal' ? 'normal' : fallbackChargeType), status, refCode: reference ? String(reference) : undefined, trackingCode: data.tracking_code ? String(data.tracking_code) : undefined, orderId: data.order_id ? String(data.order_id) : undefined, message: String(payload?.message || ''), createdAt: data.created_at ? String(data.created_at) : fallbackCreatedAt };
}

function SummaryRow({ label, value, last = false }: { label: string; value: string; last?: boolean }) { return <View style={[styles.summaryRow, last && styles.lastRow]}><Text style={styles.summaryLabel}>{label}</Text><Text style={styles.summaryValue}>{value}</Text></View>; }

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: appColors.background },
  container: { flex: 1, backgroundColor: appColors.background },
  header: { height: 64, paddingHorizontal: 16, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between' },
  title: { fontFamily: 'VazirmatnBold', color: appColors.text, fontSize: 18 },
  back: { width: 42, height: 42, borderRadius: 13, alignItems: 'center', justifyContent: 'center', backgroundColor: appColors.surface },
  headerSpacer: { width: 42 },
  content: { padding: 16, paddingBottom: 92, gap: 10 },
  intro: { color: appColors.textSecondary, fontFamily: 'Vazirmatn', fontSize: 13, lineHeight: 23, textAlign: 'right', marginBottom: 8 },
  label: { color: appColors.text, fontFamily: 'VazirmatnBold', fontSize: 13, textAlign: 'right', marginTop: 7 },
  input: { minHeight: 50, borderRadius: 13, borderWidth: 1, borderColor: appColors.surfaceRaised, backgroundColor: appColors.surface, color: appColors.text, fontFamily: 'Vazirmatn', fontSize: 15, paddingHorizontal: 14 },
  operatorRow: { flexDirection: 'row-reverse', gap: 8 },
  operator: { flex: 1, minHeight: 48, borderRadius: 12, borderWidth: 1, borderColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center', backgroundColor: appColors.surface },
  operatorActive: { borderColor: appColors.primary, backgroundColor: appColors.surfaceRaised },
  operatorText: { color: appColors.textSecondary, fontFamily: 'Vazirmatn', fontSize: 12 },
  operatorTextActive: { color: appColors.primary, fontFamily: 'VazirmatnBold' },
  quickRow: { flexDirection: 'row-reverse', flexWrap: 'wrap', gap: 8 },
  quick: { borderRadius: 10, borderWidth: 1, borderColor: appColors.surfaceRaised, paddingHorizontal: 12, paddingVertical: 9, backgroundColor: appColors.surface },
  quickActive: { borderColor: appColors.primary, backgroundColor: appColors.surfaceRaised },
  quickText: { color: appColors.textSecondary, fontFamily: 'Vazirmatn', fontSize: 12 },
  quickTextActive: { color: appColors.primary, fontFamily: 'VazirmatnBold' },
  words: { color: appColors.textMuted, fontFamily: 'Vazirmatn', fontSize: 11, textAlign: 'right' },
  primaryButton: { minHeight: 52, borderRadius: 13, backgroundColor: appColors.primary, alignItems: 'center', justifyContent: 'center', marginTop: 14 },
  primaryText: { color: appColors.background, fontFamily: 'VazirmatnBold', fontSize: 14 },
  secondaryButton: { minHeight: 50, borderRadius: 13, borderWidth: 1, borderColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center', marginTop: 10, backgroundColor: appColors.surface },
  secondaryText: { color: appColors.primary, fontFamily: 'VazirmatnBold', fontSize: 13 },
  sectionTitle: { color: appColors.text, fontFamily: 'VazirmatnBold', fontSize: 18, textAlign: 'right', marginBottom: 5 },
  summary: { borderRadius: 14, borderWidth: 1, borderColor: appColors.surfaceRaised, overflow: 'hidden', backgroundColor: appColors.surface },
  detectedOperator: { color: appColors.textMuted, fontFamily: 'Vazirmatn', fontSize: 11, textAlign: 'right' },
  modalBackdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.72)', justifyContent: 'center', alignItems: 'center', padding: 16 },
  modalCard: { width: '100%', maxWidth: 440, maxHeight: '92%', borderRadius: 20, backgroundColor: appColors.background, borderWidth: 1, borderColor: appColors.surfaceRaised },
  modalContent: { padding: 18, paddingBottom: 22 },
  balanceHint: { color: appColors.warning, fontFamily: 'Vazirmatn', fontSize: 12, lineHeight: 21, textAlign: 'right', marginTop: 10 },
  insufficient: { color: appColors.danger, fontFamily: 'VazirmatnBold', fontSize: 12, textAlign: 'right', marginTop: 10 },
  disabledButton: { opacity: 0.45 },
  summaryRow: { minHeight: 52, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 14, borderBottomWidth: 1, borderBottomColor: appColors.surfaceRaised },
  lastRow: { borderBottomWidth: 0 },
  summaryLabel: { color: appColors.textSecondary, fontFamily: 'Vazirmatn', fontSize: 12 },
  summaryValue: { color: appColors.text, fontFamily: 'VazirmatnBold', fontSize: 13 },
  notice: { color: appColors.textSecondary, backgroundColor: appColors.surface, borderRightWidth: 3, borderRightColor: appColors.primary, padding: 13, lineHeight: 23, textAlign: 'right', writingDirection: 'rtl', fontFamily: 'Vazirmatn', fontSize: 12, marginTop: 8 },
});
