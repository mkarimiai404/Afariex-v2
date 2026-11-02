import { Ionicons } from '@expo/vector-icons';
import * as Clipboard from 'expo-clipboard';
import { Picker } from '@react-native-picker/picker';
import * as Print from 'expo-print';
import { Stack, useRouter } from 'expo-router';
import * as Sharing from 'expo-sharing';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Linking,
  Modal,
  Platform,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
  ScrollView,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useAuth } from '@/lib/auth-context';
import { apiUrl } from '@/lib/api';
import { showError, showSuccess } from '@/lib/toast';
import { appColors } from '@/lib/theme';
import { AppBottomNav } from '@/components/app-bottom-nav';
import { RemittanceReceiptView } from '@/components/remittance-receipt-view';
import { buildRemittanceReceiptText, customerTrackingNumber, RemittanceReceiptModel } from '@/lib/remittance-receipt';
import { createRemittanceReceiptPdf } from '@/lib/remittance-receipt-native';
import { buildVariableRateWhatsAppUrl } from '@/lib/remittance-currency-flow';
import { moneyInputWords, normalizeMoneyInput } from '@/lib/money-input';
import { useMoneyInput } from '@/hooks/use-money-input';

type Agency = { id: string | number; name: string; address?: string; };

type DestinationCountryGroup = {
  group: 'requested' | 'europe' | 'arab';
  countries: Array<{ code: string; name: string; destination_currencies: CurrencyOption[] }>;
};

type CurrencyOption = { code: string; name: string; };

type RemittanceMetadata = {
  groups: DestinationCountryGroup[];
  source_currencies: CurrencyOption[];
  destination_currencies: CurrencyOption[];
  fixed_rate_pairs: Array<{ source_currency: string; destination_currency: string; country_code: string }>;
};

type ExchangeRate = {
  id: string | number;
  toman_per_afn: string | number;
  effective_date?: string;
  is_active?: number | boolean | string;
  active?: number | boolean | string;
};

type FeePreview = {
  original_amount: number;
  fee_amount: number;
  total_amount: number;
  fee_type?: string;
  fee_value?: string | number;
};

const toPersianNum = (num: string | number) => {
  if (!num && num !== 0) return '۰';
  const farsiDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  return num.toString().replace(/\d/g, (x) => farsiDigits[parseInt(x)]);
};

const formatReceiptDate = (value: string) => {
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
    year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(date);
};

const parseRate = (value: string | number): number =>
  Number(String(value ?? '').replace(/[,\s\u066C]/g, ''));

const calculateAfghaniAmount = (amountToman: string | number, tomanPerAfghani: string | number) => {
  const normalizedToman = normalizeMoneyInput(amountToman);
  const rate = parseRate(tomanPerAfghani);
  if (!normalizedToman || !Number.isFinite(rate) || rate <= 0) return '';
  const calculated = Number(normalizedToman) / rate;
  return Number.isFinite(calculated) ? calculated.toFixed(2) : '';
};

const isRateActive = (rate: any) => {
  const flag = rate?.is_active ?? rate?.active;
  return flag === undefined || flag === null || flag === 1 || flag === true || String(flag) === '1' || String(flag).toLowerCase() === 'active';
};

const pickLatestActiveRate = (rates: ExchangeRate[]) =>
  (rates || []).filter(isRateActive).sort((a, b) => Number(b.id) - Number(a.id))[0] ?? rates[0] ?? null;

export default function AddRemittanceScreen() {
  const router = useRouter();
  const { userId, userToken, userName, userMobile, isInitialized, setUserBalance } = useAuth();
  
  const [agencies, setAgencies] = useState<Agency[]>([]);
  const [destinationCountryGroups, setDestinationCountryGroups] = useState<DestinationCountryGroup[]>([]);
  const [sourceCurrencies, setSourceCurrencies] = useState<CurrencyOption[]>([{ code: 'IRT', name: 'تومان' }]);
  const [destinationCurrencies, setDestinationCurrencies] = useState<CurrencyOption[]>([]);
  const [fixedRatePairs, setFixedRatePairs] = useState<RemittanceMetadata['fixed_rate_pairs']>([]);
  
  const [selectedSourceCurrency, setSelectedSourceCurrency] = useState('IRT');
  const [selectedDestinationCountry, setSelectedDestinationCountry] = useState('');
  const [selectedDestinationCurrency, setSelectedDestinationCurrency] = useState('AFN');
  const [exchangeRates, setExchangeRates] = useState<ExchangeRate[]>([]);
  const [selectedAgencyId, setSelectedAgencyId] = useState<string | null>(null);
  const [selectedRateId, setSelectedRateId] = useState<number | null>(null);

  const amountTomanInput = useMoneyInput();
  const amountToman = amountTomanInput.value;
  const setAmountToman = amountTomanInput.setFormattedValue;
  const [amountAfghani, setAmountAfghani] = useState('');
  
  const [senderName, setSenderName] = useState('');
  const [receiverName, setReceiverName] = useState('');
  const [receiverPhone, setReceiverPhone] = useState('');

  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const resolvedUserId = userId?.trim() || '';
  const resolvedUserToken = userToken?.trim() || '';
  const loadInFlight = useRef(false);
  const submitInFlight = useRef(false);

  const [successModalVisible, setSuccessModalVisible] = useState(false);
  const [receiptModalVisible, setReceiptModalVisible] = useState(false);
  const [insufficientBalanceVisible, setInsufficientBalanceVisible] = useState(false);
  const [completedRemittanceId, setCompletedRemittanceId] = useState<string | null>(null);
  const [completedReceipt, setCompletedReceipt] = useState<RemittanceReceiptModel | null>(null);
  const [receiptBusyAction, setReceiptBusyAction] = useState<'pdf' | 'share' | null>(null);
  const [lastTrackingCode, setLastTrackingCode] = useState<string>('');
  const [lastAgencyName, setLastAgencyName] = useState<string>('');
  const [feePreview, setFeePreview] = useState<FeePreview | null>(null);

  const selectedRate = useMemo(
    () => exchangeRates.find((rate) => Number(rate.id) === selectedRateId) || pickLatestActiveRate(exchangeRates),
    [exchangeRates, selectedRateId]
  );

  const selectedCountry = useMemo(
    () => destinationCountryGroups.flatMap((g) => g.countries).find((c) => c.code === selectedDestinationCountry) || null,
    [destinationCountryGroups, selectedDestinationCountry]
  );

  const availableDestinationCurrencies = selectedCountry?.destination_currencies || destinationCurrencies;

  const isFixedRatePair = useMemo(
    () => fixedRatePairs.some((pair) =>
        pair.source_currency === selectedSourceCurrency
        && pair.destination_currency === selectedDestinationCurrency
        && pair.country_code === selectedDestinationCountry),
    [fixedRatePairs, selectedDestinationCountry, selectedDestinationCurrency, selectedSourceCurrency]
  );

  const selectedSourceCurrencyName = sourceCurrencies.find((c) => c.code === selectedSourceCurrency)?.name || selectedSourceCurrency;
  const selectedDestinationCurrencyName = availableDestinationCurrencies.find((c) => c.code === selectedDestinationCurrency)?.name || selectedDestinationCurrency;

  const handleOpenVariableRateWhatsApp = async () => {
    if (!selectedCountry) { showError('خطا', 'لطفاً کشور مقصد را انتخاب کنید.'); return; }
    try {
      await Linking.openURL(buildVariableRateWhatsAppUrl(selectedCountry.name, selectedSourceCurrencyName, selectedDestinationCurrencyName));
    } catch {
      showError('خطا', 'امکان باز کردن واتساپ وجود ندارد.');
    }
  };

  const resetForm = () => {
    setAmountToman('');
    setAmountAfghani('');
    setReceiverName('');
    setReceiverPhone('');
  };

  useEffect(() => {
    const fallbackName = userName?.trim() || userMobile?.trim() || '';
    setSenderName(fallbackName);
  }, [userName, userMobile]);

  const fetchData = useCallback(async () => {
    if (!isInitialized || loadInFlight.current) return;
    loadInFlight.current = true;
    setLoading(true);
    setError(null);
    try {
      const [agenciesResRaw, ratesResRaw, countriesResRaw] = await Promise.all([
        fetch(apiUrl('get_agencies.php')),
        fetch(apiUrl('get_exchange_rates.php')),
        fetch(apiUrl('get_remittance_destinations.php')),
      ]);

      const agenciesRes = JSON.parse((await agenciesResRaw.text()) || '{}');
      const ratesRes = JSON.parse((await ratesResRaw.text()) || '{}');
      const countriesRes = JSON.parse((await countriesResRaw.text()) || '{}');

      const agenciesList = agenciesRes?.data ?? agenciesRes?.rows ?? [];
      const ratesList = ratesRes?.data ?? ratesRes?.rows ?? [];

      if (agenciesRes?.success && Array.isArray(agenciesList)) {
        setAgencies(agenciesList);
        if (agenciesList.length > 0) setSelectedAgencyId(String(agenciesList[0].id));
      } else {
        setError('خطا در دریافت لیست نمایندگی‌ها');
      }

      if (ratesRes?.success && Array.isArray(ratesList)) {
        setExchangeRates(ratesList);
        const latestActive = pickLatestActiveRate(ratesList);
        if (latestActive) setSelectedRateId(Number(latestActive.id));
      } else {
        setError('خطا در دریافت نرخ ارز');
      }

      const metadata = countriesRes?.data;
      if (countriesRes?.success && Array.isArray(metadata?.groups)) {
        setDestinationCountryGroups(metadata.groups);
        setSourceCurrencies(metadata.source_currencies || [{ code: 'IRT', name: 'تومان' }]);
        setDestinationCurrencies(metadata.destination_currencies || []);
        setFixedRatePairs(metadata.fixed_rate_pairs || []);
      } else {
        setError('خطا در دریافت فهرست کشورهای مقصد');
      }
    } catch (err) {
      setError('خطا در ارتباط با سرور. لطفاً اینترنت خود را بررسی کنید.');
    } finally {
      setLoading(false);
      loadInFlight.current = false;
    }
  }, [isInitialized]);

  useEffect(() => {
    if (isInitialized) void fetchData();
  }, [fetchData, isInitialized]);

  useEffect(() => {
    if (!selectedDestinationCountry) return;
    const country = destinationCountryGroups.flatMap((g) => g.countries).find((i) => i.code === selectedDestinationCountry);
    if (!country) return;
    const allowed = country.destination_currencies.map((c) => c.code);
    if (!allowed.includes(selectedDestinationCurrency)) {
      setSelectedDestinationCurrency(allowed[0] || 'USD');
    }
  }, [destinationCountryGroups, selectedDestinationCountry, selectedDestinationCurrency]);

  useEffect(() => {
    const cleanAmountToman = normalizeMoneyInput(amountToman);
    if (!cleanAmountToman || !selectedRate || !isFixedRatePair) {
      setAmountAfghani('');
      return;
    }
    const calculated = calculateAfghaniAmount(cleanAmountToman, selectedRate.toman_per_afn);
    setAmountAfghani(calculated);
  }, [amountToman, isFixedRatePair, selectedRate]);

  useEffect(() => {
    const amount = Number(normalizeMoneyInput(amountToman));
    if (!resolvedUserToken || !Number.isFinite(amount) || amount <= 0) {
      setFeePreview(null);
      return;
    }
    const timer = setTimeout(async () => {
      try {
        const payload = new URLSearchParams({
          api_token: resolvedUserToken,
          service_code: 'remittance',
          amount: String(Math.round(amount)),
        });
        const response = await fetch(apiUrl('service-fee-preview.php'), {
          method: 'POST',
          headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload.toString(),
        });
        const data = await response.json();
        const fee = data?.data?.fee;
        if (data?.success && fee) {
          setFeePreview({
            original_amount: Number(fee.original_amount || 0),
            fee_amount: Number(fee.fee_amount || 0),
            total_amount: Number(fee.total_amount || 0),
            fee_type: fee.fee_type,
            fee_value: fee.fee_value,
          });
        }
      } catch {
        setFeePreview(null);
      }
    }, 350);
    return () => clearTimeout(timer);
  }, [amountToman, resolvedUserToken]);

  const handleDownloadPdf = async () => {
    if (!completedReceipt || receiptBusyAction) return;
    setReceiptBusyAction('pdf');
    try {
      const { uri } = await createRemittanceReceiptPdf(completedReceipt);
      await Print.printAsync({ uri });
    } catch {
      showError('خطا', 'ساخت یا دریافت PDF انجام نشد.');
    } finally {
      setReceiptBusyAction(null);
    }
  };

  const handleShareReceipt = async () => {
    if (!completedReceipt || receiptBusyAction) return;
    setReceiptBusyAction('share');
    try {
      if (!(await Sharing.isAvailableAsync())) {
        showError('اشتراک‌گذاری', 'اشتراک‌گذاری در این دستگاه پشتیبانی نمی‌شود.');
        return;
      }
      const { uri } = await createRemittanceReceiptPdf(completedReceipt);
      await Sharing.shareAsync(uri, { mimeType: 'application/pdf', dialogTitle: 'اشتراک‌گذاری رسید حواله' });
    } catch {
      showError('خطا', 'اشتراک‌گذاری رسید انجام نشد.');
    } finally {
      setReceiptBusyAction(null);
    }
  };

  const handleCopyReceipt = async () => {
    if (!completedReceipt) return;
    await Clipboard.setStringAsync(buildRemittanceReceiptText(completedReceipt));
    showSuccess('موفق', 'رسید کپی شد');
  };

  const handleSubmit = async () => {
    if (submitting || submitInFlight.current || completedRemittanceId) return;
    submitInFlight.current = true;

    const selectedAgency = agencies.find((a) => String(a.id) === String(selectedAgencyId));
    const cleanAmountToman = normalizeMoneyInput(amountToman);

    if (!selectedAgency) { submitInFlight.current = false; return showError('خطا', 'لطفاً یک نمایندگی انتخاب کنید.'); }
    if (!selectedDestinationCountry) { submitInFlight.current = false; return showError('خطا', 'لطفاً کشور مقصد را انتخاب کنید.'); }
    if (!isFixedRatePair) {
      submitInFlight.current = false;
      await handleOpenVariableRateWhatsApp();
      return;
    }
    if (!selectedRate) { submitInFlight.current = false; return showError('خطا', 'نرخ ارز معتبر یافت نشد.'); }
    if (!cleanAmountToman || Number(cleanAmountToman) <= 0) { submitInFlight.current = false; return showError('خطا', 'مبلغ تومان را وارد کنید.'); }
    if (!resolvedUserId) { submitInFlight.current = false; return showError('خطا', 'شناسه کاربر یافت نشد. دوباره وارد شوید.'); }
    if (!senderName.trim() || !receiverName.trim() || !receiverPhone.trim()) {
      submitInFlight.current = false;
      return showError('خطا', 'نام فرستنده، گیرنده و شماره تماس الزامی است.');
    }

    setSubmitting(true);
    let commitReceived = false;

    try {
      // تازه‌سازی نرخ پیش از ارسال جهت جلوگیری از انقضای rate_id
      let submissionRate = selectedRate;
      try {
        const freshRes = await fetch(`${apiUrl('get_exchange_rates.php')}?cache_bust=${Date.now()}`, {
          headers: { 'Cache-Control': 'no-cache, no-store, must-revalidate', 'Pragma': 'no-cache' },
          cache: 'no-store',
        });
        const freshJson: any = await freshRes.json().catch(() => null);
        const freshList: any[] = freshJson?.data ?? freshJson?.rows ?? [];
        if (freshRes.ok && freshJson?.success !== false && freshList.length > 0) {
          setExchangeRates(freshList);
          const stillChosen = freshList.find((r: any) => Number(r.id) === Number(selectedRateId) && isRateActive(r));
          submissionRate = stillChosen || pickLatestActiveRate(freshList) || submissionRate;
          setSelectedRateId(Number(submissionRate.id));
        }
      } catch {
        // ادامه با نرخ موجود در صورت خطای شبکه
      }

      const submittedAmountAfghani = calculateAfghaniAmount(cleanAmountToman, submissionRate.toman_per_afn);
      if (!submittedAmountAfghani) {
        setSubmitting(false);
        submitInFlight.current = false;
        return showError('خطا', 'محاسبه مبلغ معادل افغانی ناموفق بود.');
      }

      const sendRequest = async (rateIdToUse: string | number, afnAmountToUse: string) => {
        const payload = new URLSearchParams();
        payload.append('user_id', resolvedUserId);
        if (resolvedUserToken) payload.append('api_token', resolvedUserToken);
        payload.append('agency', selectedAgency.name);
        payload.append('destination_country', selectedDestinationCountry);
        payload.append('source_currency', selectedSourceCurrency);
        payload.append('destination_currency', selectedDestinationCurrency);
        payload.append('rate_id', String(rateIdToUse));
        payload.append('sender_name', senderName.trim());
        payload.append('receiver_name', receiverName.trim());
        payload.append('receiver_phone', receiverPhone.trim());
        payload.append('amount_toman', cleanAmountToman);
        payload.append('amount_afn', afnAmountToUse);

        return await fetch(apiUrl('add_remittance.php'), {
          method: 'POST',
          headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload.toString(),
        });
      };

      let response = await sendRequest(submissionRate.id, submittedAmountAfghani);
      let responseText = await response.text();
      let result: any = {};
      try {
        result = responseText ? JSON.parse(responseText) : {};
      } catch {
        setSubmitting(false);
        submitInFlight.current = false;
        return showError('خطا', 'پاسخ معتبری از سرور دریافت نشد.');
      }

      const isRateConflict = (res: Response, body: any) => {
        const code = String(body?.code ?? body?.data?.code ?? '').trim().toUpperCase();
        return res.status === 409 || ['EXCHANGE_RATE_CHANGED', 'INVALID_CONVERTED_AMOUNT', 'RATE_MISMATCH'].includes(code);
      };

      // تلاش مجدد خودکار در صورت تغییر نرخ
      if (isRateConflict(response, result)) {
        let retryOk = false;
        for (let attempt = 1; attempt <= 2; attempt++) {
          const latest = pickLatestActiveRate(exchangeRates);
          if (!latest) break;
          const retryAmount = calculateAfghaniAmount(cleanAmountToman, latest.toman_per_afn);
          if (!retryAmount) break;
          
          submissionRate = latest;
          setSelectedRateId(Number(latest.id));

          response = await sendRequest(latest.id, retryAmount);
          responseText = await response.text();
          try { result = responseText ? JSON.parse(responseText) : {}; } catch { break; }

          if (!isRateConflict(response, result)) {
            retryOk = true;
            break;
          }
        }
        if (!retryOk) {
          setSubmitting(false);
          submitInFlight.current = false;
          return showError('خطا', 'نرخ ارز تغییر کرده است. لطفاً فرم حواله را بازخوانی کنید.');
        }
      }

      const responseMessage = typeof result?.message === 'string' ? result.message.trim() : '';
      const normalizedStatus = String(result?.status ?? '').toLowerCase();
      const isErrorResponse = normalizedStatus === 'error' || response.status === 403 || !response.ok;

      if (result?.code === 'DAILY_TRANSACTION_LIMIT_EXCEEDED') {
        setSubmitting(false);
        submitInFlight.current = false;
        showError('سقف تراکنش روزانه', `سقف: ${Number(result?.data?.daily_limit || 0).toLocaleString('en-US')} تومان`);
        router.push('/access-level' as any);
        return;
      }

      const responseCode = String(result?.code ?? result?.data?.code ?? '').trim();
      if (responseCode === 'INSUFFICIENT_BALANCE') {
        setSubmitting(false);
        submitInFlight.current = false;
        setInsufficientBalanceVisible(true);
        return;
      }

      if (isErrorResponse) {
        setSubmitting(false);
        submitInFlight.current = false;
        return showError('خطا', responseMessage || 'خطا در ثبت حواله.');
      }

      if (result?.status === 'success' || result?.success) {
        commitReceived = true;
        const committedRemittanceId = String(
          result?.data?.remittance_id ??
          result?.data?.tracking_number ??
          result?.data?.code ??
          result?.remittance_id ??
          result?.tracking_number ??
          ''
        ).trim();

        if (!committedRemittanceId) {
          setSubmitting(false);
          submitInFlight.current = false;
          return showError('خطا', 'حواله ثبت شد، اما شناسه رسید از سرور دریافت نشد.');
        }

        setCompletedRemittanceId(committedRemittanceId);
        setLastTrackingCode(committedRemittanceId);
        setLastAgencyName(selectedAgency?.name || '');

        const receiptData = result?.data || {};
        setCompletedReceipt({
          trackingNumber: String(receiptData.tracking_number ?? committedRemittanceId),
          date: receiptData.created_at ? formatReceiptDate(String(receiptData.created_at)) : '',
          sender: String(receiptData.sender ?? senderName.trim()),
          receiver: String(receiptData.receiver ?? receiverName.trim()),
          amountAfghani: receiptData.amount_afghani ?? submittedAmountAfghani,
          amountToman: receiptData.amount_toman ?? cleanAmountToman,
          feeAmount: receiptData.fee?.fee_amount ?? 0,
          totalCharged: receiptData.fee?.total_amount ?? cleanAmountToman,
          feeType: receiptData.fee?.fee_type ?? 'fixed',
          feeValue: receiptData.fee?.fee_value ?? '0',
          destination: String(receiptData.destination ?? receiptData.agency_address ?? ''),
          destinationCountry: String(receiptData.destination_country_name ?? ''),
          status: String(receiptData.status ?? 'pending'),
        });

        const newBalance = result?.new_balance ?? result?.data?.new_balance ?? result?.balance ?? result?.data?.balance ?? null;
        const nextBalance = newBalance !== null && newBalance !== '' ? Number(newBalance) : null;
        if (Number.isFinite(nextBalance)) {
          setUserBalance(nextBalance);
        }

        setReceiptModalVisible(true);
        showSuccess('موفق', 'حواله با موفقیت ثبت شد.');
      } else {
        showError('خطا', result?.message || 'خطا در ثبت حواله.');
      }
    } catch (err) {
      if (commitReceived) {
        showError('رسید حواله', 'حواله با موفقیت ثبت شد، اما نمایش رسید انجام نشد.');
      } else {
        showError('خطای ارتباط', 'مشکل در ارتباط با سرور رخ داده است.');
      }
    } finally {
      setSubmitting(false);
      submitInFlight.current = false;
    }
  };

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color={appColors.primary} />
        <Text style={styles.loadingText}>در حال دریافت اطلاعات...</Text>
      </View>
    );
  }

  if (error) {
    return (
      <View style={styles.center}>
        <Text style={styles.errorText}>{error}</Text>
        <TouchableOpacity style={styles.retryBtn} onPress={fetchData}>
          <Text style={styles.retryText}>تلاش مجدد</Text>
        </TouchableOpacity>
      </View>
    );
  }

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <View style={styles.outerBackground}>
          <View style={styles.boxedContainer}>
            <View style={styles.customHeader}>
              <TouchableOpacity onPress={() => router.back()} style={styles.backBtn}>
                <Ionicons name="arrow-forward" size={24} color="#4b5563" />
              </TouchableOpacity>
              <Text style={styles.headerTitle}>ثبت حواله جدید</Text>
              <View style={{ width: 40 }} />
            </View>

            <ScrollView contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>
              <Text style={styles.label}>ارز مبدا</Text>
              <View style={styles.pickerContainer}>
                <Picker
                  style={styles.picker}
                  selectedValue={selectedSourceCurrency}
                  onValueChange={(value) => setSelectedSourceCurrency(String(value))}
                >
                  {sourceCurrencies.map((currency) => (
                    <Picker.Item key={currency.code} label={`${currency.name} (${currency.code})`} value={currency.code} />
                  ))}
                </Picker>
              </View>

              <Text style={styles.label}>کشور مقصد</Text>
              <View style={styles.pickerContainer}>
                <Picker
                  style={styles.picker}
                  selectedValue={selectedDestinationCountry}
                  onValueChange={(value) => setSelectedDestinationCountry(String(value))}
                >
                  <Picker.Item label="انتخاب کشور مقصد..." value="" />
                  {destinationCountryGroups.flatMap((group) => group.countries.map((country) => (
                    <Picker.Item
                      key={country.code}
                      label={`${group.group === 'europe' ? 'اروپا' : group.group === 'arab' ? 'کشورهای عربی' : 'مقصدهای درخواستی'} — ${country.name}`}
                      value={country.code}
                    />
                  )))}
                </Picker>
              </View>

              <Text style={styles.label}>ارز مقصد</Text>
              <View style={styles.pickerContainer}>
                <Picker
                  style={styles.picker}
                  selectedValue={selectedDestinationCurrency}
                  onValueChange={(value) => setSelectedDestinationCurrency(String(value))}
                >
                  {availableDestinationCurrencies.map((currency) => (
                    <Picker.Item key={currency.code} label={`${currency.name} (${currency.code})`} value={currency.code} />
                  ))}
                </Picker>
              </View>

              <Text style={styles.label}>نمایندگی مقصد</Text>
              <View style={styles.pickerContainer}>
                <Picker
                  style={styles.picker}
                  selectedValue={selectedAgencyId ?? ''}
                  onValueChange={(v) => setSelectedAgencyId(String(v))}
                >
                  {agencies.map((agency) => (
                    <Picker.Item key={agency.id} label={agency.name} value={String(agency.id)} />
                  ))}
                </Picker>
              </View>

              {isFixedRatePair ? (
                <>
                  <Text style={styles.label}>نرخ ارز امروز</Text>
                  <View style={styles.readonlyBox}>
                    <Text style={styles.readonlyText}>
                      {selectedRate ? `${toPersianNum(selectedRate.toman_per_afn)} تومان به ازای هر افغانی` : '-'}
                    </Text>
                  </View>
                </>
              ) : (
                <View style={styles.variableRateBox}>
                  <Text style={styles.variableRateText}>
                    نرخ این حواله متغیر است. برای دریافت نرخ روز و اطلاعات پرداخت با پشتیبانی واتساپ در ارتباط باشید.
                  </Text>
                  <TouchableOpacity style={styles.whatsappBtn} onPress={handleOpenVariableRateWhatsApp}>
                    <Ionicons name="logo-whatsapp" size={18} color="#fff" />
                    <Text style={styles.whatsappBtnText}>ارتباط با پشتیبانی واتساپ</Text>
                  </TouchableOpacity>
                </View>
              )}

              <Text style={styles.label}>نام فرستنده</Text>
              <TextInput
                style={[styles.input, styles.disabledInput]}
                editable={false}
                value={senderName}
                placeholder="نام شما"
              />

              {isFixedRatePair && (
                <View style={styles.row}>
                  <View style={styles.half}>
                    <Text style={styles.label}>مبلغ (تومان)</Text>
                    <TextInput
                      style={styles.input}
                      keyboardType="numeric"
                      editable={true}
                      value={amountToman}
                      selection={amountTomanInput.selection}
                      onSelectionChange={amountTomanInput.onSelectionChange}
                      onChangeText={amountTomanInput.onChangeText}
                      placeholder="مثال: 5000000"
                    />
                    {!!moneyInputWords(amountToman) && (
                      <Text style={styles.amountWords}>{moneyInputWords(amountToman)}</Text>
                    )}
                  </View>
                  <View style={styles.half}>
                    <Text style={styles.label}>معادل (افغانی)</Text>
                    <TextInput
                      style={[styles.input, styles.disabledInput]}
                      editable={false}
                      value={amountAfghani ? toPersianNum(amountAfghani) : ''}
                      placeholder="۰"
                    />
                  </View>
                </View>
              )}

              <Text style={styles.label}>نام گیرنده</Text>
              <TextInput
                style={styles.input}
                value={receiverName}
                onChangeText={setReceiverName}
                placeholder="نام کامل گیرنده حواله"
              />

              <Text style={styles.label}>شماره تماس گیرنده</Text>
              <TextInput
                style={styles.input}
                keyboardType="phone-pad"
                value={receiverPhone}
                onChangeText={setReceiverPhone}
                placeholder="شماره موبایل گیرنده"
              />

              {isFixedRatePair && (
                <>
                  <View style={styles.spacer} />
                  <View style={styles.feeBox}>
                    <View style={styles.feeRow}>
                      <Text style={styles.feeLabel}>مبلغ حواله:</Text>
                      <Text style={styles.feeValue}>{amountToman ? toPersianNum(amountToman) : '۰'} تومان</Text>
                    </View>
                    <View style={styles.feeRow}>
                      <Text style={styles.feeLabel}>کارمزد انتقال:</Text>
                      <Text style={styles.feeValue}>
                        {feePreview ? `${toPersianNum(feePreview.fee_amount)} تومان` : 'محاسبه...'}
                      </Text>
                    </View>
                    <View style={[styles.feeRow, styles.feeTotalRow]}>
                      <Text style={styles.feeTotalLabel}>مجموع کسر از کیف پول:</Text>
                      <Text style={styles.feeTotalValue}>
                        {feePreview ? `${toPersianNum(feePreview.total_amount)} تومان` : '...'}
                      </Text>
                    </View>
                  </View>
                </>
              )}

              <TouchableOpacity
                style={[styles.submitBtn, (submitting || completedRemittanceId) && styles.disabledBtn]}
                onPress={handleSubmit}
                disabled={submitting || !!completedRemittanceId}
              >
                {submitting ? (
                  <ActivityIndicator color="#fff" />
                ) : (
                  <Text style={styles.submitBtnText}>
                    {isFixedRatePair ? 'ثبت و ارسال حواله' : 'دریافت نرخ از طریق واتساپ'}
                  </Text>
                )}
              </TouchableOpacity>
            </ScrollView>
          </View>
        </View>
      </KeyboardAvoidingView>

      <AppBottomNav />

      {/* مودال رسید پرداخت */}
      <Modal
        visible={receiptModalVisible}
        animationType="slide"
        transparent={false}
        onRequestClose={() => {
          setReceiptModalVisible(false);
          router.replace('/(tabs)/transactions' as any);
        }}
      >
        <SafeAreaView style={{ flex: 1, backgroundColor: '#f9fafb' }}>
          <View style={styles.receiptHeader}>
            <Text style={styles.receiptHeaderTitle}>رسید ثبت حواله</Text>
            <TouchableOpacity
              onPress={() => {
                setReceiptModalVisible(false);
                router.replace('/(tabs)/transactions' as any);
              }}
              style={styles.receiptCloseBtn}
            >
              <Ionicons name="close" size={24} color="#374151" />
            </TouchableOpacity>
          </View>

          <ScrollView contentContainerStyle={{ padding: 16 }}>
            {completedReceipt && <RemittanceReceiptView receipt={completedReceipt} />}

            <View style={styles.receiptActionsContainer}>
              <TouchableOpacity
                style={[styles.receiptActionBtn, styles.receiptPrimaryBtn]}
                onPress={handleDownloadPdf}
                disabled={receiptBusyAction !== null}
              >
                {receiptBusyAction === 'pdf' ? (
                  <ActivityIndicator color="#fff" size="small" />
                ) : (
                  <>
                    <Ionicons name="document-text-outline" size={20} color="#fff" style={{ marginLeft: 6 }} />
                    <Text style={styles.receiptPrimaryBtnText}>دریافت PDF</Text>
                  </>
                )}
              </TouchableOpacity>

              <TouchableOpacity
                style={[styles.receiptActionBtn, styles.receiptSecondaryBtn]}
                onPress={handleShareReceipt}
                disabled={receiptBusyAction !== null}
              >
                {receiptBusyAction === 'share' ? (
                  <ActivityIndicator color="#111827" size="small" />
                ) : (
                  <>
                    <Ionicons name="share-social-outline" size={20} color="#111827" style={{ marginLeft: 6 }} />
                    <Text style={styles.receiptSecondaryBtnText}>اشتراک‌گذاری</Text>
                  </>
                )}
              </TouchableOpacity>
            </View>

            <TouchableOpacity style={styles.receiptCopyBtn} onPress={handleCopyReceipt}>
              <Ionicons name="copy-outline" size={18} color="#4b5563" style={{ marginLeft: 6 }} />
              <Text style={styles.receiptCopyText}>کپی متن رسید</Text>
            </TouchableOpacity>
          </ScrollView>
        </SafeAreaView>
      </Modal>

      {/* مودال موجودی ناکافی */}
      <Modal
        visible={insufficientBalanceVisible}
        animationType="fade"
        transparent={true}
        onRequestClose={() => setInsufficientBalanceVisible(false)}
      >
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <View style={styles.modalIconBox}>
              <Ionicons name="wallet-outline" size={36} color="#ef4444" />
            </View>
            <Text style={styles.modalTitle}>موجودی ناکافی</Text>
            <Text style={styles.modalText}>موجودی و اعتبار کیف پول شما برای پرداخت مبلغ حواله و کارمزد کافی نیست.</Text>
            <View style={styles.modalButtonsRow}>
              <TouchableOpacity
                style={[styles.modalBtn, styles.modalSecondaryBtn]}
                onPress={() => setInsufficientBalanceVisible(false)}
              >
                <Text style={styles.modalSecondaryBtnText}>متوجه شدم</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.modalBtn, styles.modalPrimaryBtn]}
                onPress={() => {
                  setInsufficientBalanceVisible(false);
                  router.push('/deposit' as any);
                }}
              >
                <Text style={styles.modalPrimaryBtnText}>افزایش موجودی</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#f3f4f6' },
  outerBackground: { flex: 1, backgroundColor: '#f3f4f6', paddingBottom: 60 },
  boxedContainer: { flex: 1, backgroundColor: '#ffffff', marginHorizontal: 0, borderRadius: 0, overflow: 'hidden' },
  customHeader: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16, paddingVertical: 14, borderBottomWidth: 1, borderBottomColor: '#f3f4f6' },
  backBtn: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center' },
  headerTitle: { fontSize: 16, fontWeight: 'bold', color: '#111827', textAlign: 'center' },
  scrollContent: { padding: 16, paddingBottom: 40 },
  label: { fontSize: 13, fontWeight: '600', color: '#374151', marginBottom: 6, marginTop: 12, textAlign: 'right' },
  pickerContainer: { borderWidth: 1, borderColor: '#d1d5db', borderRadius: 10, backgroundColor: '#f9fafb', overflow: 'hidden' },
  picker: { height: 50, width: '100%', color: '#111827' },
  readonlyBox: { backgroundColor: '#f3f4f6', borderWidth: 1, borderColor: '#e5e7eb', borderRadius: 10, padding: 12, alignItems: 'center' },
  readonlyText: { fontSize: 15, fontWeight: 'bold', color: '#1f2937' },
  variableRateBox: { backgroundColor: '#eff6ff', borderWidth: 1, borderColor: '#bfdbfe', borderRadius: 10, padding: 12, marginTop: 8, alignItems: 'center' },
  variableRateText: { fontSize: 13, color: '#1e40af', textAlign: 'center', lineHeight: 20, marginBottom: 10 },
  whatsappBtn: { flexDirection: 'row-reverse', alignItems: 'center', backgroundColor: '#25d366', paddingHorizontal: 16, paddingVertical: 10, borderRadius: 8 },
  whatsappBtnText: { color: '#fff', fontWeight: 'bold', fontSize: 13, marginRight: 6 },
  input: { borderWidth: 1, borderColor: '#d1d5db', borderRadius: 10, paddingHorizontal: 14, paddingVertical: 12, fontSize: 15, color: '#111827', backgroundColor: '#fff', textAlign: 'right' },
  disabledInput: { backgroundColor: '#f9fafb', color: '#6b7280' },
  amountWords: { fontSize: 11, color: '#6b7280', marginTop: 4, textAlign: 'right' },
  row: { flexDirection: 'row-reverse', gap: 12 },
  half: { flex: 1 },
  spacer: { height: 16 },
  feeBox: { backgroundColor: '#f9fafb', borderWidth: 1, borderColor: '#e5e7eb', borderRadius: 10, padding: 12, marginBottom: 16 },
  feeRow: { flexDirection: 'row-reverse', justifyContent: 'space-between', paddingVertical: 4 },
  feeLabel: { fontSize: 12, color: '#6b7280' },
  feeValue: { fontSize: 12, fontWeight: '600', color: '#374151' },
  feeTotalRow: { borderTopWidth: 1, borderTopColor: '#e5e7eb', marginTop: 6, paddingTop: 8 },
  feeTotalLabel: { fontSize: 13, fontWeight: 'bold', color: '#111827' },
  feeTotalValue: { fontSize: 13, fontWeight: 'bold', color: appColors.primary },
  submitBtn: { backgroundColor: appColors.primary, borderRadius: 10, paddingVertical: 14, alignItems: 'center', marginTop: 8 },
  disabledBtn: { opacity: 0.6 },
  submitBtnText: { color: '#fff', fontSize: 15, fontWeight: 'bold' },
  center: { flex: 1, justifyContent: 'center', alignItems: 'center', padding: 20, backgroundColor: '#fff' },
  loadingText: { marginTop: 12, fontSize: 14, color: '#6b7280' },
  errorText: { fontSize: 14, color: '#ef4444', textAlign: 'center', marginBottom: 16 },
  retryBtn: { backgroundColor: appColors.primary, paddingHorizontal: 20, paddingVertical: 10, borderRadius: 8 },
  retryText: { color: '#fff', fontWeight: 'bold' },
  receiptHeader: { flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 16, paddingVertical: 14, borderBottomWidth: 1, borderBottomColor: '#e5e7eb', backgroundColor: '#fff' },
  receiptHeaderTitle: { fontSize: 16, fontWeight: 'bold', color: '#111827' },
  receiptCloseBtn: { width: 40, height: 40, alignItems: 'center', justifyContent: 'center' },
  receiptActionsContainer: { flexDirection: 'row-reverse', gap: 12, marginTop: 16 },
  receiptActionBtn: { flex: 1, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'center', paddingVertical: 12, borderRadius: 10 },
  receiptPrimaryBtn: { backgroundColor: appColors.primary },
  receiptPrimaryBtnText: { color: '#fff', fontWeight: 'bold', fontSize: 14 },
  receiptSecondaryBtn: { backgroundColor: '#f3f4f6', borderWidth: 1, borderColor: '#d1d5db' },
  receiptSecondaryBtnText: { color: '#111827', fontWeight: 'bold', fontSize: 14 },
  receiptCopyBtn: { flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'center', paddingVertical: 12, backgroundColor: '#f9fafb', borderWidth: 1, borderColor: '#e5e7eb', borderRadius: 10, marginTop: 12 },
  receiptCopyText: { color: '#4b5563', fontWeight: '600', fontSize: 13 },
  modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'center', alignItems: 'center', padding: 20 },
  modalContent: { backgroundColor: '#fff', borderRadius: 16, padding: 24, width: '100%', maxWidth: 340, alignItems: 'center' },
  modalIconBox: { width: 64, height: 64, borderRadius: 32, backgroundColor: '#fee2e2', alignItems: 'center', justifyContent: 'center', marginBottom: 16 },
  modalTitle: { fontSize: 18, fontWeight: 'bold', color: '#111827', marginBottom: 8 },
  modalText: { fontSize: 13, color: '#4b5563', textAlign: 'center', lineHeight: 20, marginBottom: 20 },
  modalButtonsRow: { flexDirection: 'row-reverse', gap: 10, width: '100%' },
  modalBtn: { flex: 1, paddingVertical: 12, borderRadius: 10, alignItems: 'center' },
  modalPrimaryBtn: { backgroundColor: appColors.primary },
  modalPrimaryBtnText: { color: '#fff', fontWeight: 'bold', fontSize: 13 },
  modalSecondaryBtn: { backgroundColor: '#f3f4f6', borderWidth: 1, borderColor: '#d1d5db' },
  modalSecondaryBtnText: { color: '#374151', fontWeight: 'bold', fontSize: 13 },
});