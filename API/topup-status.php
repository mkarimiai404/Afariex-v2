import React, { useState, useEffect, useRef } from 'react';
import {
  StyleSheet,
  View,
  Text,
  TextInput,
  TouchableOpacity,
  ScrollView,
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
} from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useNavigation, useRoute } from '@react-navigation/native';
import { fetchJson, isApiResponseError } from '@/lib/api';
import { getRuntimeApiToken } from '@/lib/auth-runtime';

type TopupStatus = 'success' | 'failed' | 'pending';

interface TopupResult {
  success: boolean;
  status: TopupStatus;
  code?: string;
  message?: string;
  data?: {
    transaction_id?: number;
    client_request_id?: string;
    order_id?: string;
    tracking_code?: string;
    ref_code?: string;
    mobile?: string;
    operator?: string;
    charge_type?: string;
    amount?: number;
    balance?: number;
    remaining_balance?: number;
    [key: string]: any;
  };
}

const toReceipt = (
  response: any,
  mobile: string,
  operator: string,
  amount: number,
  clientRequestId: string | null,
  chargeType: string,
  transactionId?: number,
  createdAt?: string
): TopupResult => {
  const rawStatus = String(response?.status || (response?.success ? 'success' : 'pending')).toLowerCase();
  const status: TopupStatus = rawStatus === 'success' ? 'success' : rawStatus === 'failed' ? 'failed' : 'pending';
  return {
    success: response?.success ?? status === 'success',
    status,
    code: response?.code,
    message: response?.message,
    data: {
      ...response?.data,
      mobile,
      operator,
      amount,
      client_request_id: clientRequestId || response?.data?.client_request_id,
      transaction_id: transactionId || response?.data?.transaction_id,
      order_id: response?.data?.order_id,
      tracking_code: response?.data?.tracking_code || response?.data?.ref_code || response?.data?.order_id,
      ref_code: response?.data?.ref_code,
      charge_type: chargeType,
    },
  };
};

export default function TopupScreen() {
  const navigation = useNavigation();
  const route = useRoute();
  
  const [mobile, setMobile] = useState('');
  const [amount, setAmount] = useState('');
  const [operator, setOperator] = useState('mci');
  const [chargeType, setChargeType] = useState('credit');
  const [step, setStep] = useState<'form' | 'result'>('form');
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<TopupResult | null>(null);
  const [walletBalance, setWalletBalance] = useState<number | null>(null);

  const userToken = getRuntimeApiToken();
  const clientRequestId = useRef<string | null>(null);
  const statusRequestInFlight = useRef(false);
  const pollingWindow = useRef<{ key: string; startedAt: number } | null>(null);

  const checkStatus = async () => {
    if (!userToken || !result || (!result.transactionId && !result.clientRequestId) || submitting || statusRequestInFlight.current) return;
    statusRequestInFlight.current = true;
    setSubmitting(true);
    try {
      if (__DEV__) console.log('[AFARIEX-DEBUG] topup status check started', { transaction_id: result.data?.transaction_id || null, client_request_id: result.data?.client_request_id || null });
      const response = await fetchJson<any>('topup-status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${userToken}` },
        body: JSON.stringify({
          api_token: userToken,
          transaction_id: result.data?.transaction_id || undefined,
          client_request_id: result.data?.client_request_id || clientRequestId.current || undefined,
        }),
      });
      if (__DEV__) console.log('[AFARIEX-DEBUG] topup status response', { status: response?.status, code: response?.code });
      const updated = toReceipt(response, mobile || result.data?.mobile || '', operator || result.data?.operator || '', Number(amount || result.data?.amount || 0), clientRequestId.current, chargeType, result.data?.transaction_id);
      setResult(updated);
      const updatedBalance = Number(response?.data?.remaining_balance ?? response?.data?.balance);
      if (Number.isFinite(updatedBalance)) setWalletBalance(updatedBalance);
    } catch (error) {
      const payload = isApiResponseError(error) && error.responseData && !Array.isArray(error.responseData) ? error.responseData as Record<string, any> : null;
      if (isApiResponseError(error) && error.status === 404) {
        const notVisible = {
          ...(payload || {}),
          status: 'pending',
          code: payload?.code || 'TOPUP_TRANSACTION_NOT_VISIBLE_YET',
          message: payload?.message || 'درخواست هنوز قابل مشاهده نیست؛ وضعیت نامشخص است. لطفاً بعداً بررسی کنید.',
        };
        setResult(toReceipt(notVisible, mobile, operator, Number(amount || 0), clientRequestId.current, chargeType));
      } else if (payload?.status) {
        setResult(toReceipt(payload, mobile, operator, Number(amount || 0), clientRequestId.current, chargeType));
      } else {
        setResult({
          ...(result || {}),
          status: 'pending',
          message: 'خطا در ارتباط با سرور. درخواست شما در انتظار بررسی است؛ لطفاً دوباره تلاش کنید.',
        });
        if (__DEV__) console.log('[AFARIEX-DEBUG] topup status check unavailable', { error: error instanceof Error ? error.name : 'unknown' });
      }
    } finally {
      statusRequestInFlight.current = false;
      setSubmitting(false);
    }
  };

  useEffect(() => {
    if (step !== 'result' || result?.status !== 'pending' || !userToken) return;

    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let pollIndex = 0;
    const pollingKey = result.data?.client_request_id || result.data?.transaction_id;
    if (pollingWindow.current?.key !== pollingKey) {
      pollingWindow.current = { key: String(pollingKey), startedAt: Date.now() };
    }
    const startedAt = pollingWindow.current.startedAt;
    const delays = [3000, 5000, 10000, 15000];
    const maxWindowMs = 300000; // ۵ دقیقه برای هماهنگی با Inax و بک‌اند
    const maxStatusCallMs = 60000;

    const scheduleNext = () => {
      const elapsed = Date.now() - startedAt;
      if (stopped) return;
      if (elapsed + maxStatusCallMs >= maxWindowMs) {
        setResult((current) => current?.status === 'pending'
          ? { ...current, message: 'خرید همچنان در انتظار پاسخ نهایی سرویس است. پرداخت را تکرار نکنید؛ بعداً وضعیت را با همین شناسه بررسی کنید.' }
          : current);
        return;
      }

      const delay = Math.min(delays[Math.min(pollIndex, delays.length - 1)], maxWindowMs - maxStatusCallMs - elapsed);
      pollIndex += 1;
      timer = setTimeout(async () => {
        if (stopped) return;
        if (statusRequestInFlight.current) { scheduleNext(); return; }
        statusRequestInFlight.current = true;

        let updatedStatus: TopupStatus | null = null;
        try {
          const response = await fetchJson<any>('topup-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${userToken}` },
            body: JSON.stringify({
              api_token: userToken,
              transaction_id: result.data?.transaction_id || undefined,
              client_request_id: result.data?.client_request_id || clientRequestId.current || undefined,
            }),
          });
          if (['success', 'failed', 'pending'].includes(response?.status)) {
            const updated = toReceipt(response, mobile, operator, Number(amount || 0), clientRequestId.current, chargeType);
            setResult(updated);
            updatedStatus = updated.status;
            const updatedBalance = Number(response?.data?.remaining_balance ?? response?.data?.balance);
            if (Number.isFinite(updatedBalance)) setWalletBalance(updatedBalance);
          }
        } catch (error) {
          const payload = isApiResponseError(error) && error.responseData && !Array.isArray(error.responseData) ? error.responseData as Record<string, any> : null;
          if (['success', 'failed', 'pending'].includes(payload?.status)) {
            const updated = toReceipt(payload, mobile, operator, Number(amount || 0), clientRequestId.current, chargeType);
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
    return () => { stopped = true; if (timer) clearTimeout(timer); };
  }, [step, result?.status, result?.data?.transaction_id, result?.data?.client_request_id, userToken]);

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : 'height'} style={styles.container}>
      <ScrollView contentContainerStyle={styles.scroll}>
        <Text style={styles.title}>خرید شارژ مستقیم</Text>
        {step === 'form' ? (
          <View style={styles.formCard}>
            <Text style={styles.label}>شماره موبایل</Text>
            <TextInput
              style={styles.input}
              placeholder="مثلا 09123456789"
              placeholderTextColor="#888"
              keyboardType="phone-pad"
              value={mobile}
              onChangeText={setMobile}
            />
            <Text style={styles.label}>مبلغ (تومان)</Text>
            <TextInput
              style={styles.input}
              placeholder="مثلا 10000"
              placeholderTextColor="#888"
              keyboardType="numeric"
              value={amount}
              onChangeText={setAmount}
            />
            <TouchableOpacity
              style={[styles.button, submitting && styles.buttonDisabled]}
              disabled={submitting}
              onPress={() => {
                Alert.alert('ثبت', 'لطفاً اطلاعات را بررسی کنید.');
              }}
            >
              {submitting ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>پرداخت و خرید</Text>}
            </TouchableOpacity>
          </View>
        ) : (
          <View style={styles.resultCard}>
            <Text style={styles.resultTitle}>نتیجه تراکنش</Text>
            <Text style={styles.resultText}>{result?.message || 'در حال بررسی وضعیت...'}</Text>
            <TouchableOpacity
              style={[styles.button, submitting && styles.buttonDisabled]}
              disabled={submitting}
              onPress={checkStatus}
            >
              {submitting ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>بررسی مجدد وضعیت</Text>}
            </TouchableOpacity>
            <TouchableOpacity
              style={[styles.secondaryButton]}
              onPress={() => setStep('form')}
            >
              <Text style={styles.secondaryButtonText}>بازگشت به فرم خرید</Text>
            </TouchableOpacity>
          </View>
        )}
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#121212' },
  scroll: { padding: 20, justifyContent: 'center', flexGrow: 1 },
  title: { fontSize: 22, fontWeight: 'bold', color: '#fff', textAlign: 'center', marginBottom: 20 },
  formCard: { backgroundColor: '#1e1e1e', padding: 20, borderRadius: 12 },
  resultCard: { backgroundColor: '#1e1e1e', padding: 20, borderRadius: 12, alignItems: 'center' },
  label: { color: '#ccc', marginBottom: 8, marginTop: 12 },
  input: { backgroundColor: '#2c2c2c', color: '#fff', padding: 12, borderRadius: 8, fontSize: 16 },
  button: { backgroundColor: '#007AFF', padding: 14, borderRadius: 8, alignItems: 'center', marginTop: 20, width: '100%' },
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#fff', fontSize: 16, fontWeight: 'bold' },
  secondaryButton: { padding: 12, marginTop: 10, alignItems: 'center', width: '100%' },
  secondaryButtonText: { color: '#007AFF', fontSize: 14 },
  resultTitle: { fontSize: 18, fontWeight: 'bold', color: '#fff', marginBottom: 10 },
  resultText: { color: '#bbb', textAlign: 'center', marginBottom: 20, lineHeight: 22 },
});
