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

type Agency = {
  id: string | number;
  name: string;
  address?: string;
};

type DestinationCountryGroup = {
  group: 'requested' | 'europe' | 'arab';
  countries: Array<{ code: string; name: string; destination_currencies: CurrencyOption[] }>;
};

type CurrencyOption = {
  code: string;
  name: string;
};

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
  const farsiDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
  return num.toString().replace(/\d/g, (x) => farsiDigits[parseInt(x)]);
};

const formatReceiptDate = (value: string) => {
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
    year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(date);
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

const calculateAfghaniAmount = (amountToman: string | number, tomanPerAfghani: string | number) => {
  const normalizedToman = normalizeMoneyInput(amountToman);
  const rate = Number(tomanPerAfghani);
  if (!normalizedToman || !Number.isFinite(rate) || rate <= 0) return '';
  const calculated = Number(normalizedToman) / rate;
  return Number.isFinite(calculated) ? calculated.toFixed(2) : '';
};

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
  const resolvedApiToken = userToken?.trim() || '';
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
    () => exchangeRates.find((rate) => Number(rate.id) === selectedRateId) || null,
    [exchangeRates, selectedRateId]
  );

  const selectedCountry = useMemo(
    () => destinationCountryGroups.flatMap((group) => group.countries).find((country) => country.code === selectedDestinationCountry) || null,
    [destinationCountryGroups, selectedDestinationCountry]
  );

  const availableDestinationCurrencies = selectedCountry?.destination_currencies || destinationCurrencies;

  const isFixedRatePair = useMemo(
    () => fixedRatePairs.some(
      (pair) =>
        pair.source_currency === selectedSourceCurrency
        && pair.destination_currency === selectedDestinationCurrency
        && pair.country_code === selectedDestinationCountry
    ),
    [fixedRatePairs, selectedDestinationCountry, selectedDestinationCurrency, selectedSourceCurrency]
  );

  const selectedSourceCurrencyName = sourceCurrencies.find((currency) => currency.code === selectedSourceCurrency)?.name || selectedSourceCurrency;
  const selectedDestinationCurrencyName = availableDestinationCurrencies.find((currency) => currency.code === selectedDestinationCurrency)?.name || selectedDestinationCurrency;

  const handleOpenVariableRateWhatsApp = async () => {
    if (!selectedCountry) {
      showError('خطا', 'لطفاً کشور مقصد را انتخاب کنید.');
      return;
    }
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
        if (ratesList.length > 0) setSelectedRateId(Number(ratesList[0].id));
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
    const country = destinationCountryGroups
      .flatMap((group) => group.countries)
      .find((item) => item.code === selectedDestinationCountry);
    if (!country) return;
    const allowed = country.destination_currencies.map((currency) => currency.code);
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
    const rate = Number(selectedRate.toman_per_afn);
    if (!Number.isFinite(rate) || rate <= 0) {
      setAmountAfghani('');
      return;
    }
    const calculated = parseFloat(cleanAmountToman || '0') / rate;
    setAmountAfghani(Number.isFinite(calculated) ? calculated.toFixed(2) : '');
  }, [amountToman, isFixedRatePair, selectedRate]);

  useEffect(() => {
    const amount = Number(normalizeMoneyInput(amountToman));
    if (!resolvedApiToken || !Number.isFinite(amount) || amount <= 0) {
      setFeePreview(null);
      return;
    }
    const timer = setTimeout(async () => {
      try {
        const payload = new URLSearchParams({
          api_token: resolvedApiToken,
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
  }, [amountToman, resolvedApiToken]);

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
    if (submitInFlight.current || submitting || completedRemittanceId) return;

    const selectedAgency = agencies.find((a) => String(a.id) === String(selectedAgencyId));
    const cleanAmountToman = normalizeMoneyInput(amountToman);
    const calculatedAmountAfghani = selectedRate
      ? calculateAfghaniAmount(cleanAmountToman, selectedRate.toman_per_afn)
      : '';

    if (!selectedAgency) return showError('خطا', 'لطفاً یک نمایندگی انتخاب کنید.');
    if (!selectedDestinationCountry) return showError('خطا', 'لطفاً کشور مقصد را انتخاب کنید.');
    if (!isFixedRatePair) {
      await handleOpenVariableRateWhatsApp();
      return;
    }
    if (!selectedRate || !Number.isFinite(Number(selectedRate.toman_per_afn)) || Number(selectedRate.toman_per_afn) <= 0) return showError('خطا', 'نرخ ارز معتبر یافت نشد.');
    if (!cleanAmountToman || !Number.isFinite(Number(cleanAmountToman)) || Number(cleanAmountToman) <= 0) return showError('خطا', 'مبلغ تومان را وارد کنید.');
    if (!resolvedUserId) return showError('خطا', 'شناسه کاربر یافت نشد. دوباره وارد شوید.');
    if (!senderName.trim() || !receiverName.trim() || !receiverPhone.trim()) {
      return showError('خطا', 'نام فرستنده، گیرنده و شماره تماس الزامی است.');
    }

    submitInFlight.current = true;
    setSubmitting(true);
    let commitReceived = false;
    try {
      // استفاده از URLSearchParams برای اطمینان 100% از دریافت در بک‌اند
      const payload = new URLSearchParams();

      // کلیدهای زیر دقیقاً با فایل بک‌اند همگام شده‌اند
      payload.append('user_id', resolvedUserId);
      if (resolvedApiToken) payload.append('api_token', resolvedApiToken);
      payload.append('agency', selectedAgency.name);
      payload.append('destination_country', selectedDestinationCountry);
      payload.append('country_code', selectedDestinationCountry);
      payload.append('source_currency', selectedSourceCurrency);
      payload.append('destination_currency', selectedDestinationCurrency);
      let submissionRate = selectedRate;
      let submittedAmountAfghani = calculatedAmountAfghani;
      payload.append('rate_id', String(submissionRate.id));
      payload.append('sender_name', senderName.trim());
      payload.append('receiver_name', receiverName.trim());
      payload.append('receiver_phone', receiverPhone.trim());
      payload.append('amount_toman', cleanAmountToman);
      payload.append('amount_afn', submittedAmountAfghani);

      const sendRemittance = () => fetch(apiUrl('add_remittance.php'), {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: payload.toString(),
      });

      let response = await sendRemittance();
      const parseResponse = (responseText: string): Record<string, any> | null => {
        try {
          const parsed: unknown = responseText ? JSON.parse(responseText) : {};
          return isRecord(parsed) ? parsed : null;
        } catch {
          return null;
        }
      };
      let result = parseResponse(await response.text());
      if (result === null) {
        showError('خطا', 'پاسخ معتبری از سرور دریافت نشد.');
        return;
      }

      const responseCodeOf = (body: Record<string, any>) =>
        String(body.code ?? body.data?.code ?? body.result?.code ?? '').trim().toUpperCase();
      const isRateConflict = (res: Response, body: Record<string, any>) =>
        res.status === 409 || responseCodeOf(body) === 'EXCHANGE_RATE_CHANGED'
        || ['INVALID_CONVERTED_AMOUNT', 'RATE_MISMATCH'].includes(responseCodeOf(body));

      if (responseCodeOf(result) === 'DAILY_TRANSACTION_LIMIT_EXCEEDED') {
        showError('سقف تراکنش روزانه', `سقف: ${Number(result.data?.daily_limit || 0).toLocaleString('en-US')} تومان، باقی‌مانده: ${Number(result.data?.remaining_today || 0).toLocaleString('en-US')} تومان`);
        router.push('/access-level' as any);
        return;
      }

      // A rate conflict gets exactly one retry, with both rate_id and its recalculated
      // converted amount updated together. A second conflict is returned as an error.
      if (isRateConflict(response, result)) {
        try {
          const oldRateId = submissionRate.id;
          const latestRatesUrl = `${apiUrl('get_exchange_rates.php')}?cache_bust=${Date.now()}`;
          const ratesResponse = await fetch(latestRatesUrl, {
            method: 'GET',
            headers: {
              Accept: 'application/json',
              'Cache-Control': 'no-cache, no-store, must-revalidate',
              Pragma: 'no-cache',
            },
            cache: 'no-store',
          });
          const ratesData: unknown = await ratesResponse.json();
          if (!isRecord(ratesData)) throw new Error('Invalid rates response');
          const ratesList = ratesData.data ?? ratesData.rows ?? [];
          const refreshedRates = (Array.isArray(ratesList) ? ratesList.filter((rate: unknown): rate is ExchangeRate =>
            isRecord(rate)
            && (typeof rate.id === 'string' || typeof rate.id === 'number')
            && Number.isInteger(Number(rate.id)) && Number(rate.id) > 0
            && (typeof rate.toman_per_afn === 'string' || typeof rate.toman_per_afn === 'number')
            && Number.isFinite(Number(rate.toman_per_afn)) && Number(rate.toman_per_afn) > 0
          ) : []).sort((left, right) => Number(right.id) - Number(left.id));
          if (!ratesResponse.ok || ratesData.success !== true || refreshedRates.length === 0) {
            throw new Error('No usable exchange rate');
          }

          submissionRate = refreshedRates[0];
          submittedAmountAfghani = calculateAfghaniAmount(cleanAmountToman, submissionRate.toman_per_afn);
          if (!submittedAmountAfghani) throw new Error('Could not calculate converted amount');

          setExchangeRates(refreshedRates);
          setSelectedRateId(Number(submissionRate.id));
          setAmountAfghani(submittedAmountAfghani);
          payload.set('rate_id', String(submissionRate.id));
          payload.set('amount_afn', submittedAmountAfghani);

          if (__DEV__) {
            console.info('[REMITTANCE] Retrying once with refreshed rate', {
              oldRateId,
              newRateId: submissionRate.id,
              newRateValue: submissionRate.toman_per_afn,
            });
          }
          response = await sendRemittance();
          result = parseResponse(await response.text());
          if (result === null) throw new Error('Invalid retry response');
        } catch {
          showError('خطا', 'به‌روزرسانی نرخ ارز یا تلاش مجدد برای ثبت حواله انجام نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.');
          return;
        }
      }

      const responseMessage = typeof result?.message === 'string' ? result.message.trim() : '';
      const normalizedStatus = String(result.status ?? result.data?.status ?? '').toLowerCase();
      const isErrorResponse = normalizedStatus === 'error' || response.status === 403 || !response.ok;
      const responseCode = responseCodeOf(result);

      if (responseCode === 'INSUFFICIENT_BALANCE') {
        setInsufficientBalanceVisible(true);
        return;
      }

      if (isErrorResponse) {
        showError('خطا', responseMessage || 'خطا در ثبت حواله.');
        return;
      }

      if (normalizedStatus === 'success' || result.success === true || result.data?.success === true) {
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
          showError('خطا', 'حواله ثبت شد، اما شناسه رسید از سرور دریافت نشد.');
          return;
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
        const newBalance =
          result?.new_balance ??
          result?.data?.new_balance ??
          result?.result?.new_balance ??
          result?.balance ??
          result?.data?.balance ??
          result?.result?.balance ??
          null;

        const nextBalance = newBalance !== null && newBalance !== '' ? Number(newBalance) : null;
        if (Number.isFinite(nextBalance)) {
          setUserBalance(nextBalance);
        }

        // The committed response is the receipt source; opening it is local UI
        // state only and must never submit the remittance again.
        resetForm();
        setFeePreview(null);
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
      submitInFlight.current = false;
      setSubmitting(false);
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

            {/* هدر مشابه صفحه افزایش موجودی */}
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
                  onValueChange={(value) => setSelectedSourceCurrency(String(value))}>
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
                  onValueChange={(value) => setSelectedDestinationCountry(String(value))}>
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
                  onValueChange={(value) => setSelectedDestinationCurrency(String(value))}>
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
                  onValueChange={(v) => setSelectedAgencyId(String(v))}>
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
                    {!!moneyInputWords(amountToman) && <Text style={styles.amountWords}>{moneyInputWords(amountToman)}</Text>}
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

              {isFixedRatePair ? (
                <>
                  <View style={styles.spacer} />

                  <View style={styles.feeBox}>
                    <View style={styles.feeRow}><Text style={styles.feeLabel}>مبلغ حواله</Text><Text style={styles.feeValue}>{toPersianNum(Number(normalizeMoneyInput(amountToman) || 0).toLocaleString('en-US'))} تومان</Text></View>
                    <View style={styles.feeRow}><Text style={styles.feeLabel}>کارمزد</Text><Text style={styles.feeValue}>{toPersianNum(Number(feePreview?.fee_amount || 0).toLocaleString('en-US'))} تومان</Text></View>
                    <View style={[styles.feeRow, styles.feeTotalRow]}><Text style={styles.feeTotalLabel}>مبلغ نهایی قابل پرداخت</Text><Text style={styles.feeTotalValue}>{toPersianNum(Number(feePreview?.total_amount || normalizeMoneyInput(amountToman) || 0).toLocaleString('en-US'))} تومان</Text></View>
                  </View>

                  <TouchableOpacity
                    style={[styles.submitBtn, submitting && styles.disabledBtn]}
                    onPress={handleSubmit}
                    disabled={submitting}>
                    {submitting ? (
                      <ActivityIndicator color="#fff" />
                    ) : (
                      <Text style={styles.submitBtnText}>ثبت و ارسال حواله</Text>
                    )}
                  </TouchableOpacity>
                </>
              ) : (
                <TouchableOpacity
                  style={styles.whatsappBtn}
                  onPress={handleOpenVariableRateWhatsApp}>
                  <Ionicons name="logo-whatsapp" size={20} color="#fff" style={{ marginLeft: 8 }} />
                  <Text style={styles.submitBtnText}>دریافت نرخ حواله در واتساپ</Text>
                </TouchableOpacity>
              )}

            </ScrollView>
          </View>
          <AppBottomNav />
        </View>
      </KeyboardAvoidingView>

      <Modal
        visible={insufficientBalanceVisible}
        transparent
        animationType="fade"
        onRequestClose={() => setInsufficientBalanceVisible(false)}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>موجودی ناکافی</Text>
            <Text style={styles.modalLine}>موجودی کیف پول شما برای ثبت این حواله کافی نیست.</Text>
            <View style={styles.modalActions}>
              <TouchableOpacity
                style={[styles.modalBtn, styles.pdfBtn]}
                onPress={() => {
                  setInsufficientBalanceVisible(false);
                  router.push('/add-balance' as any);
                }}>
                <Text style={styles.modalBtnText}>افزایش موجودی کیف پول</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.modalBtn, styles.homeBtn]}
                onPress={() => setInsufficientBalanceVisible(false)}>
                <Text style={[styles.modalBtnText, { color: '#374151' }]}>بازگشت</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* مدال موفقیت */}
      <Modal
        visible={successModalVisible}
        transparent
        animationType="fade"
        onRequestClose={() => setSuccessModalVisible(false)}>
        <View style={styles.modalBackdrop}>
          <View style={styles.modalCard}>
                <Ionicons name="checkmark-circle" size={60} color={appColors.primary} style={{ alignSelf: 'center', marginBottom: 10 }} />
            <Text style={styles.modalTitle}>حواله با موفقیت ثبت شد</Text>
            <Text style={styles.modalLine}>کد پیگیری: <Text style={styles.modalCode}>{toPersianNum(customerTrackingNumber(lastTrackingCode))}</Text></Text>
            <Text style={styles.modalLine}>نمایندگی: {lastAgencyName}</Text>

            <View style={styles.modalActions}>
              <TouchableOpacity style={[styles.modalBtn, styles.pdfBtn]} onPress={() => { setSuccessModalVisible(false); setReceiptModalVisible(true); }}>
                <Ionicons name="document-text-outline" size={20} color="#fff" style={{ marginLeft: 8 }} />
                <Text style={styles.modalBtnText}>مشاهده رسید</Text>
              </TouchableOpacity>

              <TouchableOpacity
                style={[styles.modalBtn, styles.homeBtn]}
                onPress={() => {
                  setSuccessModalVisible(false);
                  resetForm();
                  router.replace('/dashboard');
                }}>
                <Text style={styles.modalBtnText}>بازگشت به پیشخوان</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      <Modal
        visible={receiptModalVisible}
        animationType="slide"
        onRequestClose={() => setReceiptModalVisible(false)}>
        <SafeAreaView style={styles.receiptScreen}>
          <View style={styles.receiptHeader}>
            <TouchableOpacity style={styles.receiptHeaderBack} onPress={() => setReceiptModalVisible(false)}><Ionicons name="arrow-forward" size={22} color="#334155" /></TouchableOpacity>
            <Text style={styles.receiptHeaderTitle}>رسید حواله</Text><View style={{ width: 42 }} />
          </View>
          <ScrollView contentContainerStyle={styles.receiptContent} showsVerticalScrollIndicator={false}>
            {completedReceipt && <RemittanceReceiptView receipt={completedReceipt} />}
            <View style={styles.receiptActions}>
              <TouchableOpacity style={[styles.receiptAction, styles.receiptSecondary]} onPress={handleCopyReceipt} disabled={receiptBusyAction !== null}>
                <Ionicons name="copy-outline" size={20} color={appColors.primary} /><Text style={styles.receiptSecondaryText}>کپی رسید</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[styles.receiptAction, styles.receiptPrimary]} onPress={handleDownloadPdf} disabled={receiptBusyAction !== null}>
                {receiptBusyAction === 'pdf' ? <ActivityIndicator color="#fff" /> : <><Ionicons name="download-outline" size={20} color="#fff" /><Text style={styles.receiptPrimaryText}>دریافت PDF</Text></>}
              </TouchableOpacity>
              <TouchableOpacity style={[styles.receiptAction, styles.receiptSecondary]} onPress={handleShareReceipt} disabled={receiptBusyAction !== null}>
                {receiptBusyAction === 'share' ? <ActivityIndicator color={appColors.primary} /> : <><Ionicons name="share-social-outline" size={20} color={appColors.primary} /><Text style={styles.receiptSecondaryText}>اشتراک‌گذاری رسید</Text></>}
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.receiptAction, styles.receiptPrimary]}
                onPress={() => {
                  setReceiptModalVisible(false);
                  setCompletedRemittanceId(null);
                  setCompletedReceipt(null);
                  setLastTrackingCode('');
                  setLastAgencyName('');
                  resetForm();
                  setFeePreview(null);
                }}
                disabled={receiptBusyAction !== null}>
                <Text style={styles.receiptPrimaryText}>ثبت حواله جدید</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[styles.receiptAction, styles.receiptBack]} onPress={() => setReceiptModalVisible(false)} disabled={receiptBusyAction !== null}><Text style={styles.receiptBackText}>بازگشت</Text></TouchableOpacity>
            </View>
          </ScrollView>
        </SafeAreaView>
      </Modal>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: appColors.background },
  receiptScreen: { flex: 1, backgroundColor: appColors.background },
  receiptHeader: { height: 62, paddingHorizontal: 16, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between' },
  receiptHeaderBack: { width: 42, height: 42, borderRadius: 13, backgroundColor: appColors.surface, borderWidth: 1, borderColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' },
  receiptHeaderTitle: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text },
  receiptContent: { width: '100%', maxWidth: 720, alignSelf: 'center', padding: 16, paddingBottom: 36 },
  receiptActions: { gap: 10, marginTop: 16 },
  receiptAction: { minHeight: 50, borderRadius: 13, flexDirection: 'row-reverse', gap: 8, alignItems: 'center', justifyContent: 'center' },
  receiptPrimary: { backgroundColor: appColors.primary }, receiptPrimaryText: { fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.background },
  receiptSecondary: { backgroundColor: appColors.surface, borderWidth: 1, borderColor: appColors.accent }, receiptSecondaryText: { fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.primary },
  receiptBack: { backgroundColor: appColors.surface, borderWidth: 1, borderColor: appColors.surfaceRaised }, receiptBackText: { fontFamily: 'Vazirmatn', fontSize: 13, color: appColors.textSecondary },
  outerBackground: { flex: 1, backgroundColor: appColors.background, paddingHorizontal: 18, paddingTop: 18, paddingBottom: 70 },
  boxedContainer: {
    flex: 1,
    backgroundColor: appColors.background,
    overflow: 'hidden',
    shadowOpacity: 0,
    shadowRadius: 0,
    elevation: 0
  },
  customHeader: {
    flexDirection: 'row-reverse',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 20,
    paddingVertical: 20,
    borderBottomWidth: 1,
    borderBottomColor: appColors.surfaceRaised
  },
  backBtn: { width: 40, height: 40, borderRadius: 20, backgroundColor: appColors.surfaceRaised, justifyContent: 'center', alignItems: 'center' },
  headerTitle: { fontSize: 16, fontWeight: 'bold', color: appColors.text, fontFamily: 'Vazirmatn' },
  scrollContent: { padding: 0, flexGrow: 1, paddingBottom: 70 },

  label: { fontSize: 13, color: appColors.textMuted, marginBottom: 8, marginTop: 15, fontFamily: 'Vazirmatn', textAlign: 'right' },
  input: {
    width: '100%',
    padding: 15,
    borderWidth: 1,
    borderColor: '#e5e7eb',
    borderRadius: 12,
    backgroundColor: appColors.surfaceRaised,
    textAlign: 'right',
    fontSize: 14,
    color: appColors.text,
    fontFamily: 'Vazirmatn'
  },
  amountWords: { color: appColors.primary, fontSize: 12, fontFamily: 'Vazirmatn', textAlign: 'right', marginTop: 8 },
  disabledInput: { backgroundColor: appColors.surfaceRaised, color: '#9ca3af' },
  variableRateBox: { borderWidth: 1, borderColor: '#f59e0b', backgroundColor: '#fffbeb', borderRadius: 14, padding: 14, marginTop: 16 },
  variableRateText: { color: '#92400e', fontFamily: 'Vazirmatn', fontSize: 12, lineHeight: 21, textAlign: 'right' },

  pickerContainer: {
    borderWidth: 1,
    borderColor: '#e5e7eb',
    borderRadius: 12,
    backgroundColor: appColors.surfaceRaised,
    overflow: 'hidden'
  },
  picker: {
    height: 50,
    width: '100%',
    color: appColors.text,
    fontFamily: 'Vazirmatn',
  },
  readonlyBox: {
    borderWidth: 1,
    borderColor: '#e5e7eb',
    borderRadius: 12,
    padding: 15,
    backgroundColor: appColors.surfaceRaised
  },
  readonlyText: { fontSize: 14, color: appColors.textSecondary, fontFamily: 'Vazirmatn', textAlign: 'right' },

  row: { flexDirection: 'row-reverse', justifyContent: 'space-between', gap: 10 },
  half: { flex: 1 },
  spacer: { marginTop: 25 },
  feeBox: { borderWidth: 1, borderColor: appColors.surfaceRaised, backgroundColor: appColors.background, borderRadius: 14, padding: 13, marginBottom: 16 },
  feeRow: { flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 6 },
  feeLabel: { fontFamily: 'Vazirmatn', fontSize: 12, color: '#64748b', textAlign: 'right' },
  feeValue: { fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.text, textAlign: 'left' },
  feeTotalRow: { borderTopWidth: 1, borderTopColor: '#dce7ef', marginTop: 5, paddingTop: 11 },
  feeTotalLabel: { fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.primary, textAlign: 'right' },
  feeTotalValue: { fontFamily: 'VazirmatnBold', fontSize: 14, color: appColors.primary, textAlign: 'left' },

  submitBtn: {
    width: '100%',
    backgroundColor: appColors.primary,
    padding: 15,
    borderRadius: 12,
    alignItems: 'center',
    shadowColor: appColors.primary,
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.3,
    shadowRadius: 10,
    elevation: 5,
    marginTop: 10,
    marginBottom: 20
  },
  disabledBtn: { opacity: 0.7 },
  submitBtnText: { color: '#fff', fontSize: 15, fontWeight: 'bold', fontFamily: 'Vazirmatn' },
  whatsappBtn: { width: '100%', minHeight: 52, backgroundColor: '#128c7e', padding: 15, borderRadius: 12, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'center', marginTop: 16, marginBottom: 20 },

  center: { flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: appColors.background },
  loadingText: { marginTop: 10, color: appColors.textMuted, fontFamily: 'Vazirmatn' },
  errorText: { color: '#ef4444', fontFamily: 'Vazirmatn', marginBottom: 15, textAlign: 'center' },
  retryBtn: { backgroundColor: appColors.primary, paddingHorizontal: 20, paddingVertical: 10, borderRadius: 10 },
  retryText: { color: '#fff', fontFamily: 'Vazirmatn' },

  // استایل‌های مدال
  modalBackdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'center', padding: 20 },
  modalCard: { backgroundColor: appColors.surface, borderRadius: 24, padding: 25, width: '100%' },
  modalTitle: { textAlign: 'center', fontSize: 18, fontWeight: 'bold', color: appColors.text, fontFamily: 'Vazirmatn', marginBottom: 15 },
  modalLine: { textAlign: 'center', fontSize: 14, color: appColors.textSecondary, fontFamily: 'Vazirmatn', marginBottom: 8 },
  modalCode: { fontWeight: 'bold', color: appColors.primary, fontSize: 16 },
  modalActions: { marginTop: 20, gap: 10 },
  modalBtn: { flexDirection: 'row-reverse', borderRadius: 12, padding: 14, alignItems: 'center', justifyContent: 'center' },
  pdfBtn: { backgroundColor: appColors.primary },
  homeBtn: { backgroundColor: appColors.surfaceRaised, borderWidth: 1, borderColor: '#e5e7eb' },
  modalBtnText: { color: '#fff', fontSize: 14, fontWeight: 'bold', fontFamily: 'Vazirmatn' },
});
