import { Ionicons } from '@expo/vector-icons';
import * as Clipboard from 'expo-clipboard';
import * as ImagePicker from 'expo-image-picker';
import { Stack, useRouter, useRootNavigationState } from 'expo-router';
import { useFocusEffect } from '@react-navigation/native';
import React, { useCallback, useEffect, useState } from 'react';
import { Linking, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View, Platform } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { apiUrl, fetchJson } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { showError, showSuccess } from '@/lib/toast';
import { AppBottomNav } from '@/components/app-bottom-nav';
import { formatMoneyInput, moneyInputWords, normalizeMoneyInput } from '@/lib/money-input';
import { useMoneyInput } from '@/hooks/use-money-input';
import { appColors } from '@/lib/theme';

// تابع تبدیل اعداد به فارسی
const toPersianNum = (num: string | number) => {
  if (!num && num !== 0) return '۰';
  const farsiDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
  return num.toString().replace(/\d/g, (x) => farsiDigits[parseInt(x)]);
};

export default function AddBalanceScreen() {
  const router = useRouter();
  const rootNavigationState = useRootNavigationState();
  const { userId, userToken, isAuthenticated, isInitialized } = useAuth();
  const navigationReady = Boolean(rootNavigationState?.key);
  const [currentBalance, setCurrentBalance] = useState<number>(0);
  const [activeTab, setActiveTab] = useState<'gateway' | 'card' | 'rubika'>('gateway');

  // استیت‌های درگاه
  const gatewayMoney = useMoneyInput();
  const gatewayAmount = gatewayMoney.value;
  const setGatewayAmount = gatewayMoney.setFormattedValue;

  // استیت‌های کارت به کارت
  const cardMoney = useMoneyInput();
  const cardAmount = cardMoney.value;
  const setCardAmount = cardMoney.setFormattedValue;
  const [trackingCode, setTrackingCode] = useState('');
  const [receiptImage, setReceiptImage] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(false);

  const fetchBalance = useCallback(async () => {
    if (!navigationReady || !isInitialized || !isAuthenticated) {
      return;
    }

    try {
      const payload = new URLSearchParams();
      if (userId) {
        payload.append('user_id', userId);
        payload.append('id', userId);
        payload.append('uid', userId);
      }
      if (userToken) {
        payload.append('api_token', userToken);
        payload.append('token', userToken);
        payload.append('user_token', userToken);
      }

      const data = await fetchJson<any>('dashboard.php', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: payload.toString(),
      });
      const balanceValue = data?.balance ?? data?.user?.balance ?? data?.data?.balance ?? data?.result?.balance;
      if (balanceValue !== undefined && balanceValue !== null && balanceValue !== '') {
        setCurrentBalance(parseInt(String(balanceValue), 10));
      }
    } catch (error) {
      showError('خطا', 'دریافت موجودی ناموفق بود. تنظیمات آدرس API را بررسی کنید.');
    }
  }, [isAuthenticated, isInitialized, navigationReady, userId, userToken]);

  // دریافت موجودی از سرور
  useEffect(() => {
    if (!navigationReady || !isInitialized) return;

    if (!isAuthenticated) {
      router.replace('/login' as any);
      return;
    }

    fetchBalance();
  }, [fetchBalance, isAuthenticated, isInitialized, navigationReady, router]);

  useFocusEffect(
    useCallback(() => {
      if (isAuthenticated) {
        fetchBalance();
      }
    }, [fetchBalance, isAuthenticated])
  );

  // تابع کپی شماره کارت
  const copyCardNumber = async () => {
    await Clipboard.setStringAsync('6037697708447013');
    showSuccess('کپی شد', 'شماره کارت کپی شد.');
  };

  const copyIban = async () => {
    await Clipboard.setStringAsync('IR280190000000340276047003');
    showSuccess('کپی شد', 'شماره شبا کپی شد.');
  };

  const copyAccountNumber = async () => {
    await Clipboard.setStringAsync('0340276047003');
    showSuccess('کپی شد', 'شماره حساب کپی شد.');
  };

  // تابع انتخاب عکس رسید
  const pickImage = async () => {
    let result = await ImagePicker.launchImageLibraryAsync({
      mediaTypes: ['images'],
      allowsEditing: true,
      quality: 0.8,
    });

    if (!result.canceled) {
      setReceiptImage(result.assets[0]);
    }
  };

  // ارسال فرم درگاه
  const handleGatewaySubmit = async () => {
    if (!normalizeMoneyInput(gatewayAmount)) {
      showError('خطا', 'لطفا مبلغ را وارد کنید.');
      return;
    }
    showSuccess('در حال انتقال', 'انتقال به درگاه پرداخت...');
  };

  // ارسال فرم کارت به کارت به بک‌اند - اصلاح شده برای نسخه وب و موبایل
  const handleCardSubmit = async () => {
    const cleanCardAmount = normalizeMoneyInput(cardAmount);
    if (__DEV__) {
      console.log('[ADD_BALANCE_RUNTIME] submit started');
      console.log('[ADD_BALANCE_RUNTIME] amount =', cleanCardAmount);
      console.log('[ADD_BALANCE_RUNTIME] receipt selected =', Boolean(receiptImage));
    }
    if (!cleanCardAmount || !trackingCode || !receiptImage) {
      showError('خطا', 'لطفا تمامی فیلدها را پر کرده و رسید را آپلود کنید.');
      return;
    }

    if (!userToken && !userId) {
      showError('خطا', 'نشست کاربری یافت نشد. لطفاً مجدداً وارد شوید.');
      return;
    }

    setIsLoading(true);

    try {
      const formData = new FormData();
      if (userToken) {
        formData.append('api_token', String(userToken));
      }
      if (userId) {
        formData.append('user_id', String(userId));
      }
      formData.append('amount', cleanCardAmount);
      formData.append('tracking_code', trackingCode.trim());

      // کلید طلایی برای حل مشکل آپلود در اکسپو وب (مرورگر) و موبایل
      const receiptFileName = receiptImage.fileName || receiptImage.name || 'receipt.jpg';
      const receiptMimeType = receiptImage.mimeType || receiptImage.type || 'image/jpeg';
      if (Platform.OS === 'web') {
        const response = await fetch(receiptImage.uri);
        const blob = await response.blob();
        formData.append('receipt', blob, receiptFileName);
      } else {
        formData.append('receipt', {
          uri: receiptImage.uri,
          name: receiptFileName,
          type: receiptMimeType,
        } as any);
      }

      if (__DEV__) console.log('[ADD_BALANCE_RUNTIME] request started');
      const response = await fetch(apiUrl('add-balance.php'), {
        method: 'POST',
        body: formData, // بدون قرار دادن هدر Content-Type
      });

      const responseText = await response.text();
      if (__DEV__) {
        console.log('[ADD_BALANCE_RUNTIME] response status =', response.status);
        console.log('[ADD_BALANCE_RUNTIME] response body =', responseText);
      }
      let data: any = {};

      try {
        data = responseText ? JSON.parse(responseText) : {};
      } catch {
        data = { success: false, message: responseText || 'پاسخ نامعتبر از سرور دریافت شد.' };
      }

      if (response.ok && (data?.success === true || data?.status === 'success')) {
        showSuccess('موفق', 'رسید شما با موفقیت ثبت شد و در انتظار تایید است.');
        setCardAmount('');
        setTrackingCode('');
        setReceiptImage(null);
      } else {
        showError('خطا', data?.message || 'خطا در ثبت رسید. لطفا دوباره تلاش کنید.');
      }
    } catch (error) {
      showError('خطا', 'مشکل در ارتباط با سرور.');
      if (__DEV__) console.error(error);
    } finally {
      setIsLoading(false);
    }
  };

  const handleRubikaSupport = async () => {
    const rubikaUrl = 'https://rubika.ir/Afariex2026';
    const canOpen = await Linking.canOpenURL(rubikaUrl);
    if (!canOpen) {
      showError('خطا', 'باز کردن لینک روبیکا ممکن نیست.');
      return;
    }
    await Linking.openURL(rubikaUrl);
  };

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />

      <View style={styles.outerBackground}>
        <View style={styles.boxedContainer}>

          <View style={styles.customHeader}>
            <TouchableOpacity onPress={() => router.back()} style={styles.backBtn}>
              <Ionicons name="arrow-forward" size={24} color="#4b5563" />
            </TouchableOpacity>
            <Text style={styles.headerTitle}>شارژ موجودی</Text>
            <View style={{ width: 40 }} />
          </View>

          <ScrollView contentContainerStyle={styles.scrollContent} showsVerticalScrollIndicator={false}>

            <View style={styles.balanceCard}>
              <Text style={styles.balanceText}>وضعیت حساب شما:</Text>
              {currentBalance > 0 ? (
                <Text style={[styles.balanceAmount, { color: appColors.primary }]}>
                  {toPersianNum(currentBalance.toLocaleString())} تومان (طلبکار)
                </Text>
              ) : currentBalance < 0 ? (
                <Text style={[styles.balanceAmount, { color: '#ef4444' }]}>
                  {toPersianNum(Math.abs(currentBalance).toLocaleString())} تومان (بدهکار)
                </Text>
              ) : (
                <Text style={styles.balanceEmpty}>۰ تومان (حساب تسویه)</Text>
              )}
            </View>

            <View style={styles.tabsContainer}>
              <TouchableOpacity style={[styles.tabBtn, activeTab === 'gateway' && styles.tabBtnActive]} onPress={() => setActiveTab('gateway')}>
                <Text style={[styles.tabBtnText, activeTab === 'gateway' && styles.tabBtnTextActive]}>درگاه</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[styles.tabBtn, activeTab === 'card' && styles.tabBtnActive]} onPress={() => setActiveTab('card')}>
                <Text style={[styles.tabBtnText, activeTab === 'card' && styles.tabBtnTextActive]}>کارت به کارت</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[styles.tabBtn, activeTab === 'rubika' && styles.tabBtnActive]} onPress={() => setActiveTab('rubika')}>
                <Text style={[styles.tabBtnText, activeTab === 'rubika' && styles.tabBtnTextActive]}>روبیکا</Text>
              </TouchableOpacity>
            </View>

            {activeTab === 'gateway' && (
              <View style={styles.tabContent}>
                <TextInput style={styles.input} placeholder="مقدار شارژ (تومان)" keyboardType="numeric" value={gatewayAmount} selection={gatewayMoney.selection} onSelectionChange={gatewayMoney.onSelectionChange} onChangeText={gatewayMoney.onChangeText} />
                {!!moneyInputWords(gatewayAmount) && <Text style={styles.amountWords}>{moneyInputWords(gatewayAmount)}</Text>}
                <View style={styles.quickAmounts}>
                  <TouchableOpacity style={styles.quickBtn} onPress={() => setGatewayAmount(formatMoneyInput('1000000'))}><Text style={styles.quickBtnText}>{toPersianNum('1,000,000')}</Text></TouchableOpacity>
                  <TouchableOpacity style={styles.quickBtn} onPress={() => setGatewayAmount(formatMoneyInput('5000000'))}><Text style={styles.quickBtnText}>{toPersianNum('5,000,000')}</Text></TouchableOpacity>
                  <TouchableOpacity style={styles.quickBtn} onPress={() => setGatewayAmount(formatMoneyInput('10000000'))}><Text style={styles.quickBtnText}>{toPersianNum('10,000,000')}</Text></TouchableOpacity>
                </View>
                <View style={styles.spacer} />
                <TouchableOpacity style={styles.submitBtn} onPress={handleGatewaySubmit}>
                  <Text style={styles.submitBtnText}>افزایش موجودی از طریق درگاه</Text>
                </TouchableOpacity>
              </View>
            )}

            {activeTab === 'card' && (
              <View style={styles.tabContent}>
                <View style={styles.bankCard}>
                  <View style={styles.bankCardHeader}>
                    <View style={styles.bankIcon}><Ionicons name="business-outline" size={22} color={appColors.primary} /></View>
                    <View style={styles.bankHeading}>
                      <Text style={styles.bankName}>بانک صادرات</Text>
                      <Text style={styles.accountType}>قرض الحسنه جاری</Text>
                    </View>
                  </View>
                  <Text style={styles.bankInstruction}>مبلغ را به یکی از حساب‌های زیر واریز کرده و رسید آن را آپلود کنید.</Text>

                  <View style={styles.bankDetail}>
                    <Text style={styles.bankDetailLabel}>شماره کارت</Text>
                    <View style={styles.bankValueRow}>
                      <Text selectable style={[styles.bankValue, styles.cardValue]}>{toPersianNum('6037 6977 0844 7013')}</Text>
                      <TouchableOpacity style={styles.copyButton} onPress={copyCardNumber} accessibilityRole="button" accessibilityLabel="کپی شماره کارت">
                        <Ionicons name="copy-outline" size={16} color={appColors.primary} /><Text style={styles.copyButtonText}>کپی</Text>
                      </TouchableOpacity>
                    </View>
                  </View>

                  <View style={styles.bankDetail}>
                    <Text style={styles.bankDetailLabel}>شماره شبا</Text>
                    <View style={styles.bankValueRow}>
                      <Text selectable style={[styles.bankValue, styles.ibanValue]}>{toPersianNum('IR28 0190 0000 0034 0276 0470 03')}</Text>
                      <TouchableOpacity style={styles.copyButton} onPress={copyIban} accessibilityRole="button" accessibilityLabel="کپی شماره شبا">
                        <Ionicons name="copy-outline" size={16} color={appColors.primary} /><Text style={styles.copyButtonText}>کپی</Text>
                      </TouchableOpacity>
                    </View>
                  </View>

                  <View style={styles.bankDetail}>
                    <Text style={styles.bankDetailLabel}>شماره حساب</Text>
                    <View style={styles.bankValueRow}>
                      <Text selectable style={styles.bankValue}>{toPersianNum('0340276047003')}</Text>
                      <TouchableOpacity style={styles.copyButton} onPress={copyAccountNumber} accessibilityRole="button" accessibilityLabel="کپی شماره حساب">
                        <Ionicons name="copy-outline" size={16} color={appColors.primary} /><Text style={styles.copyButtonText}>کپی</Text>
                      </TouchableOpacity>
                    </View>
                  </View>

                  <View style={styles.accountHolder}>
                    <Text style={styles.accountHolderLabel}>به نام</Text>
                    <Text style={styles.accountHolderName} numberOfLines={1}>به نام نورآقا محمدی</Text>
                  </View>
                </View>

                <TextInput style={styles.input} placeholder="مبلغ واریزی (تومان)" keyboardType="numeric" value={cardAmount} selection={cardMoney.selection} onSelectionChange={cardMoney.onSelectionChange} onChangeText={cardMoney.onChangeText} />
                {!!moneyInputWords(cardAmount) && <Text style={styles.amountWords}>{moneyInputWords(cardAmount)}</Text>}
                <View style={styles.quickAmounts}>
                  <TouchableOpacity style={styles.quickBtn} onPress={() => setCardAmount(formatMoneyInput('100000'))}><Text style={styles.quickBtnText}>{toPersianNum('100,000')}</Text></TouchableOpacity>
                  <TouchableOpacity style={styles.quickBtn} onPress={() => setCardAmount(formatMoneyInput('500000'))}><Text style={styles.quickBtnText}>{toPersianNum('500,000')}</Text></TouchableOpacity>
                  <TouchableOpacity style={styles.quickBtn} onPress={() => setCardAmount(formatMoneyInput('1000000'))}><Text style={styles.quickBtnText}>{toPersianNum('1,000,000')}</Text></TouchableOpacity>
                </View>

                <TextInput style={styles.input} placeholder="شماره پیگیری تراکنش" keyboardType="numeric" value={trackingCode} onChangeText={setTrackingCode} />

                <TouchableOpacity style={styles.fileUpload} onPress={pickImage}>
                  {receiptImage ? <Text style={styles.uploadTextSuccess}>رسید انتخاب شد</Text> : <Text style={styles.uploadText}>📄 آپلود عکس رسید واریز</Text>}
                </TouchableOpacity>

                <View style={styles.spacer} />

                <TouchableOpacity style={[styles.submitBtn, isLoading && { opacity: 0.7 }]} onPress={handleCardSubmit} disabled={isLoading}>
                  <Text style={styles.submitBtnText}>{isLoading ? 'در حال ارسال...' : 'ثبت فیش واریزی'}</Text>
                </TouchableOpacity>
              </View>
            )}

            {activeTab === 'rubika' && (
              <View style={styles.tabContent}>
                <View style={styles.infoCard}>
                  <Text style={styles.infoText}>برای شارژ حساب از طریق <Text style={{fontWeight: 'bold'}}>پشتیبانی</Text>، روی دکمه زیر کلیک کنید تا به روبیکای ما متصل شوید.</Text>
                  <Text style={styles.cardName}>شناسه پشتیبانی: @Afariex2026</Text>
                </View>
                <View style={styles.spacer} />
                <TouchableOpacity style={[styles.submitBtn, styles.rubikaBtn]} onPress={handleRubikaSupport}>
                  <Text style={styles.submitBtnText}>ارتباط با پشتیبانی در روبیکا</Text>
                </TouchableOpacity>
              </View>
            )}

          </ScrollView>
        </View>
        <AppBottomNav />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: appColors.background },
  outerBackground: { flex: 1, backgroundColor: appColors.background, paddingHorizontal: 18, paddingTop: 18, paddingBottom: 70 },
  boxedContainer: { flex: 1, backgroundColor: appColors.background, overflow: 'hidden', shadowOpacity: 0, shadowRadius: 0, elevation: 0 },
  customHeader: { flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', paddingHorizontal: 20, paddingVertical: 20, borderBottomWidth: 1, borderBottomColor: appColors.surfaceRaised },
  backBtn: { width: 40, height: 40, borderRadius: 20, backgroundColor: appColors.surface, justifyContent: 'center', alignItems: 'center' },
  headerTitle: { fontSize: 16, fontWeight: 'bold', color: appColors.text, fontFamily: 'Vazirmatn' },
  scrollContent: { padding: 0, flexGrow: 1, paddingBottom: 70 },
  balanceCard: { alignItems: 'center', marginBottom: 20 },
  balanceText: { fontSize: 13, color: appColors.textMuted, marginBottom: 5, fontFamily: 'Vazirmatn' },
  balanceAmount: { fontSize: 18, fontWeight: 'bold', fontFamily: 'Vazirmatn' },
  balanceEmpty: { fontSize: 15, fontWeight: 'bold', color: appColors.textMuted, fontFamily: 'Vazirmatn' },
  tabsContainer: { flexDirection: 'row-reverse', backgroundColor: appColors.surface, borderRadius: 12, padding: 4, marginBottom: 20 },
  tabBtn: { flex: 1, paddingVertical: 10, alignItems: 'center', borderRadius: 8 },
  tabBtnActive: { backgroundColor: appColors.primary },
  tabBtnText: { fontSize: 12, color: appColors.textMuted, fontWeight: '500', fontFamily: 'Vazirmatn' },
  tabBtnTextActive: { color: appColors.background, fontWeight: 'bold', fontFamily: 'Vazirmatn' },
  tabContent: { flex: 1, minHeight: 320 },
  input: { width: '100%', padding: 15, borderWidth: 1, borderColor: appColors.surfaceRaised, borderRadius: 12, backgroundColor: appColors.surface, textAlign: 'center', fontSize: 15, marginBottom: 15, color: appColors.text, fontFamily: 'Vazirmatn' },
  amountWords: { color: appColors.textSecondary, fontSize: 12, fontFamily: 'Vazirmatn', textAlign: 'right', marginTop: -8, marginBottom: 12 },
  quickAmounts: { flexDirection: 'row-reverse', justifyContent: 'space-between', marginBottom: 20, gap: 8 },
  quickBtn: { flex: 1, paddingVertical: 10, borderWidth: 1, borderColor: appColors.accent, borderRadius: 10, backgroundColor: appColors.surface, alignItems: 'center' },
  quickBtnText: { fontSize: 12, color: appColors.textSecondary, fontFamily: 'Vazirmatn' },
  spacer: { flex: 1 },
  submitBtn: { width: '100%', backgroundColor: appColors.primary, padding: 15, borderRadius: 12, alignItems: 'center', shadowColor: appColors.primary, shadowOffset: { width: 0, height: 4 }, shadowOpacity: 0.18, shadowRadius: 10, elevation: 5, marginTop: 10 },
  submitBtnText: { color: appColors.background, fontSize: 15, fontWeight: 'bold', fontFamily: 'Vazirmatn' },
  rubikaBtn: { backgroundColor: '#3b82f6', shadowColor: '#3b82f6' },
  bankCard: { backgroundColor: appColors.surface, borderWidth: 1, borderColor: appColors.accent, borderRadius: 18, padding: 18, marginBottom: 18, shadowColor: appColors.primary, shadowOffset: { width: 0, height: 6 }, shadowOpacity: 0.1, shadowRadius: 12, elevation: 3 },
  bankCardHeader: { flexDirection: 'row-reverse', alignItems: 'center', gap: 11, paddingBottom: 15, borderBottomWidth: 1, borderBottomColor: appColors.accent },
  bankIcon: { width: 42, height: 42, borderRadius: 13, backgroundColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' },
  bankHeading: { flex: 1, alignItems: 'flex-end' },
  bankName: { fontSize: 15, color: appColors.text, fontWeight: 'bold', fontFamily: 'Vazirmatn' },
  accountType: { fontSize: 11, color: appColors.textSecondary, marginTop: 2, fontFamily: 'Vazirmatn' },
  bankInstruction: { fontSize: 12, lineHeight: 21, color: appColors.textSecondary, textAlign: 'right', marginTop: 14, marginBottom: 2, fontFamily: 'Vazirmatn' },
  bankDetail: { marginTop: 14, padding: 12, backgroundColor: appColors.surfaceRaised, borderWidth: 1, borderColor: appColors.accent, borderRadius: 12 },
  bankDetailLabel: { fontSize: 11, color: appColors.textSecondary, textAlign: 'right', marginBottom: 7, fontFamily: 'Vazirmatn' },
  bankValueRow: { flexDirection: 'row-reverse', alignItems: 'center', gap: 8 },
  bankValue: { flex: 1, minWidth: 0, color: appColors.text, fontSize: 15, fontWeight: 'bold', letterSpacing: 1.2, textAlign: 'left', fontFamily: 'Vazirmatn' },
  cardValue: { fontSize: 16, letterSpacing: 1.2 },
  ibanValue: { fontSize: 10, letterSpacing: 0.4 },
  copyButton: { minHeight: 34, paddingHorizontal: 9, borderWidth: 1, borderColor: appColors.primary, borderRadius: 9, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'center', gap: 4, backgroundColor: appColors.surface },
  copyButtonText: { color: appColors.primary, fontSize: 11, fontWeight: 'bold', fontFamily: 'Vazirmatn' },
  accountHolder: { alignItems: 'center', justifyContent: 'center', marginTop: 16, paddingTop: 14, borderTopWidth: 1, borderTopColor: appColors.accent },
  accountHolderLabel: { color: appColors.textSecondary, fontSize: 11, fontFamily: 'Vazirmatn' },
  accountHolderName: { color: appColors.text, fontSize: 14, fontWeight: 'bold', fontFamily: 'Vazirmatn', textAlign: 'center' },
  infoCard: { backgroundColor: appColors.surface, padding: 15, borderRadius: 12, alignItems: 'center', marginBottom: 15 },
  infoText: { fontSize: 13, lineHeight: 22, color: appColors.textSecondary, textAlign: 'center', marginBottom: 5, fontFamily: 'Vazirmatn' },
  cardName: { fontSize: 12, color: appColors.textSecondary, marginTop: 5, fontFamily: 'Vazirmatn' },
  fileUpload: { borderWidth: 2, borderColor: '#d1d5db', borderStyle: 'dashed', borderRadius: 12, padding: 20, alignItems: 'center', backgroundColor: appColors.surfaceRaised, marginBottom: 15 },
  uploadText: { color: appColors.textMuted, fontSize: 14, fontFamily: 'Vazirmatn' },
  uploadTextSuccess: { color: appColors.primary, fontSize: 14, fontWeight: 'bold', fontFamily: 'Vazirmatn' },
});
