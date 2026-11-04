import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  TextInput,
  TouchableOpacity,
  StyleSheet,
  ScrollView,
  ActivityIndicator,
  Alert,
  Modal,
} from 'react-native';
import { apiUrl } from '../lib/api';

interface ExchangeRate {
  id: number;
  toman_per_afn: number;
  effective_date?: string;
}

const normalizeExchangeRate = (value: any): ExchangeRate | null => {
  const id = Number(value?.id);
  const tomanPerAfn = Number(value?.toman_per_afn);
  if (!Number.isSafeInteger(id) || id <= 0 || !Number.isFinite(tomanPerAfn) || tomanPerAfn <= 0) return null;
  return { id, toman_per_afn: tomanPerAfn, effective_date: value?.effective_date };
};

interface DestinationCountry {
  code: string;
  name: string;
}

export default function AddRemittanceScreen({ route, navigation }: any) {
  const userId = route?.params?.userId || 1;
  const apiToken = route?.params?.apiToken || '';

  const [destinationCountries, setDestinationCountries] = useState<DestinationCountry[]>([]);
  const [selectedCountry, setSelectedCountry] = useState<string>('AF');
  const [exchangeRates, setExchangeRates] = useState<ExchangeRate[]>([]);
  const [selectedRate, setSelectedRate] = useState<ExchangeRate | null>(null);

  const [agency, setAgency] = useState<string>('مرکزی');
  const [senderName, setSenderName] = useState<string>('');
  const [receiverName, setReceiverName] = useState<string>('');
  const [receiverPhone, setReceiverPhone] = useState<string>('');
  const [amountToman, setAmountToman] = useState<string>('');
  const [amountAfn, setAmountAfn] = useState<string>('');

  const [loading, setLoading] = useState<boolean>(false);
  const [fetchingRates, setFetchingRates] = useState<boolean>(false);
  const [errorModalVisible, setErrorModalVisible] = useState<boolean>(false);
  const [errorMessage, setErrorMessage] = useState<string>('');

  // دریافت کشورهای مقصد و نرخ‌های ارز در ابتدای کار
  useEffect(() => {
    fetchDestinationCountries();
    fetchRates();
  }, []);

  const fetchDestinationCountries = async () => {
    try {
      const response = await fetch(apiUrl('get_remittance_destinations.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ user_id: userId, api_token: apiToken }),
      });
      const json = await response.json();
      if (json.success && json.data) {
        // تبدیل آبجکت یا آرایه کشورهای مقصد
        const countriesList = Array.isArray(json.data)
          ? json.data
          : Object.keys(json.data).map((code) => ({ code, name: json.data[code] }));
        setDestinationCountries(countriesList);
      }
    } catch (err) {
      console.error('Error fetching destination countries:', err);
    }
  };

  const fetchRates = async (): Promise<ExchangeRate | null> => {
    setFetchingRates(true);
    try {
      const response = await fetch(apiUrl('get_exchange_rates.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' },
        body: JSON.stringify({ user_id: userId, api_token: apiToken }),
      });
      const json = await response.json();
      if (response.ok && json.success && json.data) {
        const rates = Array.isArray(json.data) ? json.data : [json.data];
        const validRates = rates.map(normalizeExchangeRate).filter((r: ExchangeRate | null): r is ExchangeRate => r !== null);
        setExchangeRates(validRates);
        if (validRates.length > 0) {
          setSelectedRate(validRates[0]);
          return validRates[0];
        }
      }
    } catch (err) {
      console.error('Error fetching exchange rates:', err);
    } finally {
      setFetchingRates(false);
    }
    return null;
  };

  // محاسبه خودکار مبلغ افغانی هنگام تغییر تومان یا نرخ
  const handleTomanChange = (text: string) => {
    setAmountToman(text);
    const tomanNum = parseFloat(text);
    if (!isNaN(tomanNum) && selectedRate && selectedRate.toman_per_afn > 0) {
      const afn = tomanNum / selectedRate.toman_per_afn;
      setAmountAfn(afn.toFixed(2));
    } else {
      setAmountAfn('');
    }
  };

  // ارسال درخواست ثبت حواله همراه با منطق مدیریت خطای تغییر نرخ (409)
  const handleSubmit = async () => {
    if (!senderName || !receiverName || !receiverPhone || !amountToman) {
      Alert.alert('خطا', 'لطفاً تمامی فیلدهای الزامی را پر کنید.');
      return;
    }

    const tomanAmount = Number(amountToman);
    if (!Number.isFinite(tomanAmount) || tomanAmount <= 0) {
      Alert.alert('خطا', 'مبلغ حواله معتبر نیست.');
      return;
    }

    setLoading(true);
    try {
      let rateToUse = selectedRate;
      if (!rateToUse || !normalizeExchangeRate(rateToUse)) {
        rateToUse = await fetchRates();
      }

      let result: any;
      for (let attempt = 0; attempt < 3; attempt += 1) {
        if (!rateToUse) throw new Error('نرخ فعال ارز دریافت نشد. لطفاً دوباره تلاش کنید.');
        const amountAfnForRate = (tomanAmount / rateToUse.toman_per_afn).toFixed(2);
        setAmountAfn(amountAfnForRate);

        const payload = new URLSearchParams({
          user_id: String(userId),
          api_token: apiToken,
          agency,
          destination_country: selectedCountry,
          source_currency: 'IRT',
          destination_currency: 'AFN',
          rate_id: String(rateToUse.id),
          rate_value: String(rateToUse.toman_per_afn),
          sender_name: senderName,
          receiver_name: receiverName,
          receiver_phone: receiverPhone,
          amount_toman: String(tomanAmount),
          amount_afn: amountAfnForRate,
        });
        const response = await fetch(apiUrl('add_remittance.php'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload.toString(),
        });
        result = await response.json();

        if (response.status !== 409 && result.code !== 'EXCHANGE_RATE_CHANGED') break;

        // Fetch the authoritative latest rate, then retry using a payload
        // whose ID, rate value, and converted amount all come from that rate.
        const refreshedRate = normalizeExchangeRate(result.data?.active_rate) || await fetchRates();
        if (!refreshedRate) throw new Error('نرخ فعال ارز دریافت نشد. لطفاً دوباره تلاش کنید.');
        rateToUse = refreshedRate;
        setSelectedRate(refreshedRate);
        setExchangeRates((current) => [refreshedRate, ...current.filter((rate) => rate.id !== refreshedRate.id)]);
      }

      if (result?.success) {
        Alert.alert('موفقیت', result.message || 'حواله با موفقیت ثبت شد.', [
          { text: 'تایید', onPress: () => navigation.goBack() },
        ]);
      } else {
        setErrorMessage(result?.message || 'خطا در ثبت حواله.');
        setErrorModalVisible(true);
      }
    } catch (err: any) {
      console.error('Submit error:', err);
      setErrorMessage(err?.message || 'خطای ارتباط با سرور.');
      setErrorModalVisible(true);
    } finally {
      setLoading(false);
    }
  };

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.contentContainer}>
      <Text style={styles.title}>ثبت حواله جدید</Text>

      {/* انتخاب کشور مقصد */}
      <Text style={styles.label}>کشور مقصد</Text>
      <View style={styles.row}>
        {destinationCountries.map((country) => (
          <TouchableOpacity
            key={country.code}
            style={[styles.chip, selectedCountry === country.code && styles.chipSelected]}
            onPress={() => setSelectedCountry(country.code)}
          >
            <Text style={[styles.chipText, selectedCountry === country.code && styles.chipTextSelected]}>
              {country.name}
            </Text>
          </TouchableOpacity>
        ))}
      </View>

      {/* نمایش نرخ فعال */}
      <View style={styles.rateBox}>
        <Text style={styles.rateText}>
          نرخ فعلی: {selectedRate ? `${selectedRate.toman_per_afn} تومان` : 'در حال بارگذاری...'}
        </Text>
        <TouchableOpacity onPress={fetchRates} disabled={fetchingRates}>
          {fetchingRates ? <ActivityIndicator size="small" color="#fff" /> : <Text style={styles.refreshText}>بروزرسانی نرخ</Text>}
        </TouchableOpacity>
      </View>

      {/* فرم اطلاعات */}
      <Text style={styles.label}>نام صرافی / شعبه</Text>
      <TextInput
        style={styles.input}
        value={agency}
        onChangeText={setAgency}
        placeholder="نام صرافی"
        placeholderTextColor="#888"
      />

      <Text style={styles.label}>نام فرستنده</Text>
      <TextInput
        style={styles.input}
        value={senderName}
        onChangeText={setSenderName}
        placeholder="نام کامل فرستنده"
        placeholderTextColor="#888"
      />

      <Text style={styles.label}>نام گیرنده</Text>
      <TextInput
        style={styles.input}
        value={receiverName}
        onChangeText={setReceiverName}
        placeholder="نام کامل گیرنده"
        placeholderTextColor="#888"
      />

      <Text style={styles.label}>شماره تماس گیرنده</Text>
      <TextInput
        style={styles.input}
        value={receiverPhone}
        onChangeText={setReceiverPhone}
        placeholder="07xxxxxxxx"
        keyboardType="phone-pad"
        placeholderTextColor="#888"
      />

      <Text style={styles.label}>مبلغ ارسالی (تومان)</Text>
      <TextInput
        style={styles.input}
        value={amountToman}
        onChangeText={handleTomanChange}
        placeholder="مبلغ به تومان"
        keyboardType="numeric"
        placeholderTextColor="#888"
      />

      <Text style={styles.label}>مبلغ معادل (افغانی)</Text>
      <TextInput
        style={[styles.input, styles.disabledInput]}
        value={amountAfn}
        editable={false}
        placeholder="محاسبه خودکار"
        placeholderTextColor="#888"
      />

      {/* دکمه ثبت */}
      <TouchableOpacity style={styles.submitButton} onPress={handleSubmit} disabled={loading}>
        {loading ? <ActivityIndicator color="#fff" /> : <Text style={styles.submitButtonText}>ثبت حواله</Text>}
      </TouchableOpacity>

      {/* مودال خطا */}
      <Modal visible={errorModalVisible} transparent animationType="fade">
        <View style={styles.modalOverlay}>
          <View style={styles.modalContent}>
            <Text style={styles.modalTitle}>خطا</Text>
            <Text style={styles.modalMessage}>{errorMessage}</Text>
            <TouchableOpacity style={styles.modalButton} onPress={() => setErrorModalVisible(false)}>
              <Text style={styles.modalButtonText}>تایید</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#121212' },
  contentContainer: { padding: 20 },
  title: { fontSize: 22, fontWeight: 'bold', color: '#fff', marginBottom: 20, textAlign: 'right' },
  label: { color: '#bbb', fontSize: 14, marginBottom: 5, textAlign: 'right', marginTop: 10 },
  input: {
    backgroundColor: '#1e1e1e',
    color: '#fff',
    borderRadius: 8,
    paddingHorizontal: 15,
    paddingVertical: 12,
    fontSize: 16,
    textAlign: 'right',
    borderWidth: 1,
    borderColor: '#333',
  },
  disabledInput: { backgroundColor: '#181818', color: '#888' },
  row: { flexDirection: 'row-reverse', flexWrap: 'wrap', marginBottom: 10 },
  chip: {
    backgroundColor: '#222',
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 20,
    marginLeft: 8,
    marginBottom: 8,
    borderWidth: 1,
    borderColor: '#444',
  },
  chipSelected: { backgroundColor: '#00bcd4', borderColor: '#00bcd4' },
  chipText: { color: '#ccc', fontSize: 14 },
  chipTextSelected: { color: '#fff', fontWeight: 'bold' },
  rateBox: {
    backgroundColor: '#1f2937',
    padding: 12,
    borderRadius: 8,
    flexDirection: 'row-reverse',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginVertical: 10,
  },
  rateText: { color: '#fff', fontSize: 14 },
  refreshText: { color: '#38bdf8', fontSize: 14, fontWeight: 'bold' },
  submitButton: {
    backgroundColor: '#00bcd4',
    paddingVertical: 15,
    borderRadius: 8,
    alignItems: 'center',
    marginTop: 30,
    marginBottom: 40,
  },
  submitButtonText: { color: '#fff', fontSize: 18, fontWeight: 'bold' },
  modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.7)', justifyContent: 'center', alignItems: 'center' },
  modalContent: { backgroundColor: '#222', padding: 20, borderRadius: 10, width: '80%', alignItems: 'center' },
  modalTitle: { color: '#ff5252', fontSize: 18, fontWeight: 'bold', marginBottom: 10 },
  modalMessage: { color: '#fff', fontSize: 14, textAlign: 'center', marginBottom: 20 },
  modalButton: { backgroundColor: '#00bcd4', paddingHorizontal: 20, paddingVertical: 10, borderRadius: 5 },
  modalButtonText: { color: '#fff', fontWeight: 'bold' },
});
