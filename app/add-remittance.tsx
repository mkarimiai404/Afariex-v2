import { Ionicons } from '@expo/vector-icons';
import * as Clipboard from 'expo-clipboard';
import * as Print from 'expo-print';
import { Stack, useRouter } from 'expo-router';
import * as Sharing from 'expo-sharing';
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Alert,
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
  phone?: string;
};

type AgenciesResponse = {
  success: boolean;
  data?: Agency[];
  rows?: Agency[];
  code?: string;
  message?: string;
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

type SelectorKey = 'source' | 'country' | 'destinationCurrency' | 'agency';
type SelectorOption = { value: string; label: string };
const isRecord = (value: unknown): value is Record<string, unknown> =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

const calculateAfghaniAmount = (amountToman: string | number, tomanPerAfghani: string | number) => {
  const normalizedToman = normalizeMoneyInput(amountToman);
  const rate = Number(tomanPerAfghani);
  if (!normalizedToman || !Number.isFinite(rate) || rate <= 0) return '';
  const calculated = Number(normalizedToman) / rate;
  return Number.isFinite(calculated) ? calculated.toFixed(2) : '';
};

const normalizeCurrencyOptions = (value: unknown): CurrencyOption[] => {
  if (!Array.isArray(value)) return [];
  return value.filter((item): item is CurrencyOption =>
    isRecord(item)
    && typeof item.code === 'string' && item.code.trim() !== ''
    && typeof item.name === 'string'
  ).map((item) => ({ code: item.code.trim(), name: item.name.trim() }));
};

const normalizeDestinationGroups = (value: unknown): DestinationCountryGroup[] => {
  if (!Array.isArray(value)) return [];
  return value.filter(isRecord).map((group) => {
    const rawGroup = group.group;
    const groupName: DestinationCountryGroup['group'] = rawGroup === 'europe'
      ? 'europe'
      : rawGroup === 'arab'
        ? 'arab'
        : 'requested';
    const rawCountries = Array.isArray(group.countries) ? group.countries : [];
    const countries = rawCountries.filter((country: unknown): country is Record<string, unknown> =>
      isRecord(country)
      && typeof country.code === 'string' && country.code.trim() !== ''
      && typeof country.name === 'string'
    ).map((country) => ({
      code: (country.code as string).trim(),
      name: (country.name as string).trim(),
      destination_currencies: normalizeCurrencyOptions(country.destination_currencies),
    }));
    return { group: groupName, countries };
  }).filter((group) => group.countries.length > 0);
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
  const [activeSelector, setActiveSelector] = useState<SelectorKey | null>(null);

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

  const availableDestinationCurrencies = selectedCountry?.destination_currencies?.length
    ? selectedCountry.destination_currencies
    : destinationCurrencies;

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

  const countries = useMemo(
    () => destinationCountryGroups.flatMap((group) => group.countries),
    [destinationCountryGroups]
  );
  const selectorOptions = useMemo<SelectorOption[]>(() => {
    switch (activeSelector) {
      case 'source':
        return sourceCurrencies.map((currency) => ({ value: currency.code, label: currency.name + ' (' + currency.code + ')' }));
      case 'country':
        return destinationCountryGroups.flatMap((group) => group.countries.map((country) => ({
          value: country.code,
          label: (group.group === 'europe' ? 'اروپا' : group.group === 'arab' ? 'کشورهای عربی' : 'مقصدهای درخواستی') + ' — ' + country.name,
        })));
      case 'destinationCurrency':
        return availableDestinationCurrencies.map((currency) => ({ value: currency.code, label: currency.name + ' (' + currency.code + ')' }));
      case 'agency':
        return agencies.map((agency) => ({ value: String(agency.id), label: agency.name }));
      default:
        return [];
    }
  }, [activeSelector, agencies, availableDestinationCurrencies, destinationCountryGroups, sourceCurrencies]);

  const selectorTitle = activeSelector === 'source'
    ? 'انتخاب ارز مبدا'
    : activeSelector === 'country'
      ? 'انتخاب کشور مقصد'
      : activeSelector === 'destinationCurrency'
        ? 'انتخاب ارز مقصد'
        : 'انتخاب نمایندگی مقصد';
  const selectedSelectorValue = activeSelector === 'source'
    ? selectedSourceCurrency
    : activeSelector === 'country'
      ? selectedDestinationCountry
      : activeSelector === 'destinationCurrency'
        ? selectedDestinationCurrency
        : selectedAgencyId ?? '';

  const handleSelectorChoice = (value: string) => {
    switch (activeSelector) {
      case 'source':
        setSelectedSourceCurrency(value);
        break;
      case 'country': {
        setSelectedDestinationCountry(value);
        const country = countries.find((item) => item.code === value);
        const allowedCurrencies = country?.destination_currencies.length
          ? country.destination_currencies
          : destinationCurrencies;
        if (!allowedCurrencies.some((currency) => currency.code === selectedDestinationCurrency)) {
          setSelectedDestinationCurrency(allowedCurrencies[0]?.code || 'USD');
        }
        break;
      }
      case 'destinationCurrency':
        setSelectedDestinationCurrency(value);
        break;
      case 'agency':
        setSelectedAgencyId(value);
        break;
      default:
        break;
    }
    setActiveSelector(null);
  };

  const renderSelectorButton = (value: string, placeholder: string, selector: SelectorKey) => (
    <TouchableOpacity
      style={styles.selectorButton}
      onPress={() => setActiveSelector(selector)}
      accessibilityRole="button"
      accessibilityLabel={value || placeholder}>
      <Ionicons name="chevron-down" size={18} color={appColors.textSecondary} />
      <Text style={[styles.selectorButtonText, !value && styles.selectorPlaceholder]} numberOfLines={1}>
        {value || placeholder}
      </Text>
    </TouchableOpacity>
  );

  const handleOpenVariableRateWhatsApp = async () => {
    if (!selectedCountry || !selectedSourceCurrency || !selectedDestinationCurrency
      || !sourceCurrencies.some((currency) => currency.code === selectedSourceCurrency)
      || !availableDestinationCurrencies.some((currency) => currency.code === selectedDestinationCurrency)) {
      Alert.alert('انتخاب مقصد', 'لطفاً کشور مقصد، ارز مبدا و ارز مقصد را انتخاب کنید.');
      return;
    }
    try {
      await Linking.openURL(buildVariableRateWhatsAppUrl(
        selectedCountry.name || selectedDestinationCountry,
        selectedSourceCurrencyName || selectedSourceCurrency,
        selectedDestinationCurrencyName || selectedDestinationCurrency,
      ));
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

    // هلپر اختصاصی برای ارسال هدرهای ضد کش و ثبت دقیق وضعیت در APK
    const safeFetch = async (endpoint: string) => {
      const url = apiUrl(endpoint);
      try {
        const res = await fetch(url, {
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
          },
        });
        const rawText = await res.text();
        if (__DEV__) console.log(`[AFARIEX-DEBUG] Endpoint: ${endpoint} | Status: ${res.status}`);
        
        let parsed = null;
        try {
          parsed = JSON.parse(rawText || '{}');
        } catch (jsonErr) {
          if (__DEV__) console.warn(`[AFARIEX-DEBUG] Invalid JSON from ${endpoint}:`, rawText.slice(0, 200));
        }

        return {
          ok: res.ok,
          status: res.status,
          contentType: res.headers.get('content-type') || '',
          data: parsed,
          raw: rawText,
        };
      } catch (netErr: any) {
        if (__DEV__) console.error(`[AFARIEX-DEBUG] Network error on ${endpoint}:`, netErr?.message || netErr);
        return { ok: false, status: 0, contentType: '', data: null, raw: '' };
      }
    };

    try {
      const [agenciesResult, ratesResult, countriesResult] = await Promise.all([
        safeFetch('get_agencies.php'),
        safeFetch('get_exchange_rates.php'),
        safeFetch('get_remittance_destinations.php'),
      ]);

      const agenciesRes = agenciesResult.data as AgenciesResponse | null;
      const ratesRes = ratesResult.data;
      const countriesRes = countriesResult.data;

      const agenciesList = agenciesRes?.data ?? agenciesRes?.rows ?? [];
      const ratesList = ratesRes?.data ?? ratesRes?.rows ?? [];

      if (__DEV__) {
        console.log('[AFARIEX-DEBUG] Agencies response', {
          endpoint: apiUrl('get_agencies.php'),
          httpStatus: agenciesResult.status,
          requestSucceeded: agenciesResult.ok,
          contentType: agenciesResult.contentType,
          success: agenciesRes?.success ?? null,
          responseCode: agenciesRes?.code ?? null,
          dataType: Array.isArray(agenciesList) ? 'array' : typeof agenciesList,
          agencyCount: Array.isArray(agenciesList) ? agenciesList.length : null,
          firstAgencyKeys: Array.isArray(agenciesList) && agenciesList[0] && typeof agenciesList[0] === 'object'
            ? Object.keys(agenciesList[0])
            : [],
        });
      }

      if (agenciesResult.ok && agenciesRes?.success && Array.isArray(agenciesList)) {
        const validAgencies = agenciesList.filter((agency): agency is Agency =>
          agency !== null
          && typeof agency === 'object'
          && (typeof agency.id === 'string' || typeof agency.id === 'number')
          && typeof agency.name === 'string'
          && agency.name.trim() !== ''
        );
        setAgencies(validAgencies);
        if (validAgencies.length > 0) {
          setSelectedAgencyId(String(validAgencies[0].id));
        } else {
          if (__DEV__) console.warn('[AFARIEX-DEBUG] Agencies endpoint returned an empty list.');
          setError('در حال حاضر نمایندگی‌ای برای انتخاب ثبت نشده است.');
        }
      } else {
        if (__DEV__) console.warn('[AFARIEX-DEBUG] Agencies payload could not be used', {
          httpStatus: agenciesResult.status,
          response: agenciesResult.raw.slice(0, 300),
        });
        setError('دریافت لیست نمایندگی‌ها ناموفق بود. اتصال سرور یا پاسخ API را بررسی کنید.');
      }

      if (ratesRes?.success && Array.isArray(ratesList)) {
        const validRates = ratesList.filter((rate: unknown): rate is ExchangeRate =>
          isRecord(rate)
          && (typeof rate.id === 'string' || typeof rate.id === 'number')
          && (typeof rate.toman_per_afn === 'string' || typeof rate.toman_per_afn === 'number')
        );
        setExchangeRates(validRates);
        if (validRates.length > 0) setSelectedRateId(Number(validRates[0].id));
      } else {
        setError('خطا در دریافت نرخ ارز');
      }

      const metadata = countriesRes?.data;
      if (countriesRes?.success && Array.isArray(metadata?.groups)) {
        const normalizedGroups = normalizeDestinationGroups(metadata.groups);
        const normalizedDestinationCurrencies = normalizeCurrencyOptions(metadata.destination_currencies);
        const normalizedSourceCurrencies = normalizeCurrencyOptions(metadata.source_currencies);
        setDestinationCountryGroups(normalizedGroups);
        setSourceCurrencies(normalizedSourceCurrencies.length ? normalizedSourceCurrencies : [{ code: 'IRT', name: 'تومان' }]);
        setDestinationCurrencies(normalizedDestinationCurrencies);
        setFixedRatePairs(Array.isArray(metadata.fixed_rate_pairs)
          ? metadata.fixed_rate_pairs.filter((pair: unknown) =>
            isRecord(pair)
            && typeof pair.source_currency === 'string'
            && typeof pair.destination_currency === 'string'
            && typeof pair.country_code === 'string'
          )
          : []);
        if (normalizedGroups.length === 0) setError('فهرست کشورهای مقصد در دسترس نیست.');
      } else {
        // لاگ کمکی برای این که دقیقاً ببینید سرور به APK چه جوابی داده است
        if (__DEV__) console.warn('[AFARIEX-DEBUG] Destinations payload mismatch:', countriesResult.raw.slice(0, 300));
        setError('خطا در دریافت فهرست کشورهای مقصد');
      }
    } catch (err) {
      if (__DEV__) console.error('[AFARIEX-DEBUG] Unhandled error in fetchData:', err);
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
    if (sourceCurrencies.length && !sourceCurrencies.some((currency) => currency.code === selectedSourceCurrency)) {
      setSelectedSourceCurrency(sourceCurrencies[0].code);
    }
  }, [selectedSourceCurrency, sourceCurrencies]);

  useEffect(() => {
    if (!selectedDestinationCountry) return;
    const country = countries.find((item) => item.code === selectedDestinationCountry);
    const allowedCurrencies = country?.destination_currencies?.length
      ? country.destination_currencies
      : destinationCurrencies;
    const allowed = allowedCurrencies.map((currency) => currency.code);
    if (!allowed.includes(selectedDestinationCurrency)) {
      setSelectedDestinationCurrency(allowed[0] || 'USD');
    }
  }, [countries, destinationCurrencies, selectedDestinationCountry, selectedDestinationCurrency]);

  useEffect(() => {
    if (!selectedRate || !isFixedRatePair) {
      setAmountAfghani('');
      return;
    }
    setAmountAfghani(calculateAfghaniAmount(amountToman, selectedRate.toman_per_afn));
  }, [amountToman, isFixedRatePair, selectedRate]);

  useEffect(() => {
    const amount = Number(normalizeMoneyInput(amountToman));
    if (!isFixedRatePair || !resolvedApiToken || !Number.isFinite(amount) || amount <= 0) {
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
  }, [amountToman, isFixedRatePair, resolvedApiToken]);

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
    if (submitting || completedRemittanceId) return;

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
    if (!selectedRate) return showError('خطا', 'نرخ ارز معتبر یافت نشد.');
    if (!cleanAmountToman || Number(cleanAmountToman) <= 0) return showError('خطا', 'مبلغ تومان را وارد کنید.');
    if (!resolvedUserId) return showError('خطا', 'شناسه کاربر یافت نشد. دوباره وارد شوید.');
    if (!senderName.trim() || !receiverName.trim() || !receiverPhone.trim()) {
      return showError('خطا', 'نام فرستنده، گیرنده و شماره تماس الزامی است.');
    }

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
      payload.append('source_currency', selectedSourceCurrency);
      payload.append('destination_currency', selectedDestinationCurrency);
      payload.append('rate_id', String(selectedRate.id));
      payload.append('sender_name', senderName.trim());
      payload.append('receiver_name', receiverName.trim());
      payload.append('receiver_phone', receiverPhone.trim());
      payload.append('amount_toman', cleanAmountToman);
      payload.append('amount_afn', calculatedAmountAfghani);

      const response = await fetch(apiUrl('add_remittance.php'), {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: payload.toString(),
      });

      const responseText = await response.text();
      let result: any = {};
      try {
        result = responseText ? JSON.parse(responseText) : {};
      } catch {
        showError('خطا', 'پاسخ معتبری از سرور دریافت نشد.');
        return;
      }

      const responseMessage = typeof result?.message === 'string' ? result.message.trim() : '';
      const normalizedStatus = String(result?.status ?? '').toLowerCase();
      const isErrorResponse = normalizedStatus === 'error' || response.status === 403 || !response.ok;

      if (result?.code === 'DAILY_TRANSACTION_LIMIT_EXCEEDED') {
        showError('سقف تراکنش روزانه', `سقف: ${Number(result?.data?.daily_limit || 0).toLocaleString('en-US')} تومان، باقی‌مانده: ${Number(result?.data?.remaining_today || 0).toLocaleString('en-US')} تومان`);
        router.push('/access-level' as any);
        return;
      }

      const responseCode = String(result?.code ?? result?.data?.code ?? '').trim();

      if (responseCode === 'INSUFFICIENT_BALANCE') {
        setInsufficientBalanceVisible(true);
        return;
      }

      if (responseCode === 'EXCHANGE_RATE_CHANGED') {
        try {
          const ratesResponse = await fetch(apiUrl('get_exchange_rates.php'), {
            method: 'GET',
            headers: {
              Accept: 'application/json',
              'Cache-Control': 'no-cache, no-store, must-revalidate',
              Pragma: 'no-cache',
            },
          });
          const ratesData = await ratesResponse.json();
          const ratesList = ratesData?.data ?? ratesData?.rows ?? [];
          const refreshedRates = Array.isArray(ratesList)
            ? ratesList.filter((rate: unknown): rate is ExchangeRate =>
              isRecord(rate)
              && (typeof rate.id === 'string' || typeof rate.id === 'number')
              && (typeof rate.toman_per_afn === 'string' || typeof rate.toman_per_afn === 'number')
              && Number.isFinite(Number(rate.toman_per_afn))
              && Number(rate.toman_per_afn) > 0
            )
            : [];

          if (!ratesResponse.ok || !ratesData?.success || refreshedRates.length === 0) {
            throw new Error('No usable exchange rate returned');
          }

          const latestRate = refreshedRates[0];
          const updatedAfghaniAmount = calculateAfghaniAmount(cleanAmountToman, latestRate.toman_per_afn);
          setExchangeRates(refreshedRates);
          setSelectedRateId(Number(latestRate.id));
          setAmountAfghani(updatedAfghaniAmount);
          Alert.alert(
            'نرخ ارز به‌روزرسانی شد',
            `مبلغ معادل افغانی اکنون ${toPersianNum(updatedAfghaniAmount)} افغانی است. لطفاً مبلغ جدید را بررسی کنید؛ برای ادامه، حواله را دوباره ثبت کنید.`,
            [{ text: 'متوجه شدم' }]
          );
        } catch {
          setExchangeRates([]);
          setSelectedRateId(null);
          setAmountAfghani('');
          Alert.alert(
            'به‌روزرسانی نرخ ناموفق بود',
            'دریافت نرخ جدید ممکن نشد. لطفاً اتصال اینترنت را بررسی و دوباره تلاش کنید. مبلغی ثبت نشده است.',
            [{ text: 'متوجه شدم' }]
          );
        }
        return;
      }

      if (isErrorResponse) {
        showError('Ø®Ø·Ø§', responseMessage || 'Ø®Ø·Ø§ Ø¯Ø± Ø«Ø¨Øª Ø­ÙˆØ§Ù„Ù‡.');
        return;
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
          amountAfghani: receiptData.amount_afghani ?? amountAfghani.trim(),
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
              {renderSelectorButton(selectedSourceCurrencyName, 'انتخاب ارز مبدا', 'source')}

              <Text style={styles.label}>کشور مقصد</Text>
              {renderSelectorButton(countries.find((country) => country.code === selectedDestinationCountry)?.name || '', 'انتخاب کشور مقصد', 'country')}

              <Text style={styles.label}>ارز مقصد</Text>
              {renderSelectorButton(selectedDestinationCurrencyName, 'انتخاب ارز مقصد', 'destinationCurrency')}

              <Text style={styles.label}>نمایندگی مقصد</Text>
              {renderSelectorButton(agencies.find((agency) => String(agency.id) === String(selectedAgencyId))?.name || '', 'انتخاب نمایندگی مقصد', 'agency')}

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
        visible={activeSelector !== null}
        transparent
        animationType="fade"
        onRequestClose={() => setActiveSelector(null)}>
        <View style={styles.selectorBackdrop}>
          <View style={styles.selectorModal}>
            <View style={styles.selectorHeader}>
              <Text style={styles.selectorTitle}>{selectorTitle}</Text>
              <TouchableOpacity onPress={() => setActiveSelector(null)} accessibilityRole="button" accessibilityLabel="بستن">
                <Ionicons name="close" size={22} color={appColors.textSecondary} />
              </TouchableOpacity>
            </View>
            <ScrollView style={styles.selectorOptions} keyboardShouldPersistTaps="handled">
              {selectorOptions.length ? selectorOptions.map((option) => (
                <TouchableOpacity
                  key={option.value}
                  style={styles.selectorOption}
                  onPress={() => handleSelectorChoice(option.value)}
                  accessibilityRole="button">
                  <Text style={styles.selectorOptionText}>{option.label}</Text>
                  {option.value === selectedSelectorValue && <Ionicons name="checkmark" size={20} color={appColors.primary} />}
                </TouchableOpacity>
              )) : <Text style={styles.selectorEmpty}>گزینه‌ای برای انتخاب وجود ندارد.</Text>}
            </ScrollView>
          </View>
        </View>
      </Modal>

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

  selectorButton: { minHeight: 50, borderWidth: 1, borderColor: appColors.surfaceRaised, borderRadius: 12, backgroundColor: appColors.surfaceRaised, paddingHorizontal: 14, flexDirection: 'row-reverse', alignItems: 'center', gap: 10 },
  selectorButtonText: { flex: 1, color: appColors.text, fontFamily: 'Vazirmatn', fontSize: 14, textAlign: 'right' },
  selectorPlaceholder: { color: appColors.textMuted },
  selectorBackdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.62)', justifyContent: 'center', padding: 20 },
  selectorModal: { width: '100%', maxHeight: '78%', alignSelf: 'center', backgroundColor: appColors.surface, borderRadius: 18, padding: 16 },
  selectorHeader: { flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', paddingBottom: 12, borderBottomWidth: 1, borderBottomColor: appColors.surfaceRaised },
  selectorTitle: { color: appColors.text, fontFamily: 'VazirmatnBold', fontSize: 16 },
  selectorOptions: { flexGrow: 0, marginTop: 6 },
  selectorOption: { minHeight: 48, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 8, borderBottomWidth: 1, borderBottomColor: appColors.surfaceRaised },
  selectorOptionText: { flex: 1, color: appColors.textSecondary, fontFamily: 'Vazirmatn', fontSize: 14, textAlign: 'right' },
  selectorEmpty: { color: appColors.textMuted, fontFamily: 'Vazirmatn', textAlign: 'center', paddingVertical: 20 },
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
