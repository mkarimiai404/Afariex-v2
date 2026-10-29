import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import * as Clipboard from 'expo-clipboard';
import * as Print from 'expo-print';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import * as Sharing from 'expo-sharing';
import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { WithdrawalReceiptView } from '@/components/withdrawal-receipt-view';
import { fetchJson, isApiResponseError } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { buildWithdrawalReceiptText, normalizeWithdrawalReceipt, WithdrawalReceiptModel } from '@/lib/withdrawal-receipt';
import { createWithdrawalReceiptPdf } from '@/lib/withdrawal-receipt-native';
import { showError, showSuccess } from '@/lib/toast';

export default function WithdrawalReceiptScreen() {
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id?: string }>();
  const { userToken, isAuthenticated, isInitialized } = useAuth();
  const [receipt, setReceipt] = useState<WithdrawalReceiptModel | null>(null);
  const [loading, setLoading] = useState(true);
  const [errorText, setErrorText] = useState('');
  const [busyAction, setBusyAction] = useState<'pdf' | 'share' | null>(null);

  const withdrawalId = typeof id === 'string' ? id.trim() : '';

  useEffect(() => {
    if (__DEV__) console.log('[RECEIPT_RUNTIME] receipt screen mounted');
  }, []);

  useEffect(() => {
    if (__DEV__) console.log('[RECEIPT_RUNTIME] withdrawal id received =', withdrawalId || null);
  }, [withdrawalId]);

  const loadReceipt = useCallback(async () => {
    if (!isInitialized) return;
    if (!isAuthenticated || !userToken) {
      router.replace('/login' as never);
      return;
    }
    if (!/^\d+$/.test(withdrawalId)) {
      setLoading(false);
      setErrorText('شناسه برداشت معتبر نیست.');
      return;
    }

    setLoading(true);
    setErrorText('');
    try {
      if (__DEV__) console.log('[RECEIPT_RUNTIME] receipt API request started', {
        withdrawal_id: withdrawalId,
        authenticated: isAuthenticated,
      });
      const body = new URLSearchParams({
        api_token: String(userToken),
        withdrawal_id: withdrawalId,
      });
      const response = await fetchJson<{ success?: boolean; data?: unknown }>('withdrawal-receipt.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: body.toString(),
      });
      if (__DEV__) console.log('[RECEIPT_RUNTIME] receipt API response =', response);

      if (response?.success !== true || !response.data) {
        setReceipt(null);
        setErrorText('اطلاعات رسید معتبر نیست.');
        return;
      }

      setReceipt(normalizeWithdrawalReceipt(response.data));
    } catch (error) {
      if (__DEV__) console.log('[RECEIPT_RUNTIME] receipt API response =', isApiResponseError(error)
        ? {
            success: false,
            status: error.status,
            code: error.responseCode,
            message: error.safeMessage,
            data: error.responseData,
          }
        : {
            success: false,
            message: error instanceof Error ? error.message : String(error),
          });
      setReceipt(null);
      setErrorText(
        isApiResponseError(error) && error.safeMessage
          ? error.safeMessage
          : 'دریافت رسید برداشت انجام نشد.'
      );
    } finally {
      setLoading(false);
    }
  }, [isAuthenticated, isInitialized, router, userToken, withdrawalId]);

  useEffect(() => { void loadReceipt(); }, [loadReceipt]);

  const downloadPdf = async () => {
    if (!receipt || busyAction) return;
    setBusyAction('pdf');
    try {
      const { uri } = await createWithdrawalReceiptPdf(receipt);
      await Print.printAsync({ uri });
    } catch {
      showError('خطا', 'ساخت یا دریافت PDF انجام نشد.');
    } finally {
      setBusyAction(null);
    }
  };

  const shareReceipt = async () => {
    if (!receipt || busyAction) return;
    setBusyAction('share');
    try {
      if (!(await Sharing.isAvailableAsync())) {
        showError('اشتراک‌گذاری', 'اشتراک‌گذاری در این دستگاه پشتیبانی نمی‌شود.');
        return;
      }
      const { uri } = await createWithdrawalReceiptPdf(receipt);
      await Sharing.shareAsync(uri, { mimeType: 'application/pdf', dialogTitle: 'اشتراک‌گذاری رسید برداشت' });
    } catch {
      showError('خطا', 'اشتراک‌گذاری رسید انجام نشد.');
    } finally {
      setBusyAction(null);
    }
  };

  const copyReceipt = async () => {
    if (!receipt) return;
    await Clipboard.setStringAsync(buildWithdrawalReceiptText(receipt));
    showSuccess('موفق', 'متن رسید کپی شد.');
  };

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <TouchableOpacity style={styles.backIcon} onPress={() => router.back()} accessibilityLabel="بازگشت">
              <Ionicons name="arrow-forward" size={22} color={appColors.primary} />
        </TouchableOpacity>
        <Text style={styles.title}>رسید برداشت</Text>
        <View style={styles.headerSpace} />
      </View>

      {loading ? (
        <View style={styles.state}>
          <ActivityIndicator color={appColors.primary} />
          <Text style={styles.stateText}>در حال دریافت رسید...</Text>
        </View>
      ) : !receipt ? (
        <View style={styles.state}>
          <Ionicons name="document-text-outline" size={42} color="#94a3b8" />
          <Text style={styles.stateText}>{errorText || 'رسید این برداشت در دسترس نیست.'}</Text>
          <TouchableOpacity style={styles.retry} onPress={loadReceipt}>
            <Text style={styles.retryText}>تلاش مجدد</Text>
          </TouchableOpacity>
        </View>
      ) : (
        <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
          <WithdrawalReceiptView receipt={receipt} />
          <View style={styles.actions}>
            <TouchableOpacity style={[styles.action, styles.secondary]} onPress={copyReceipt} disabled={busyAction !== null}>
              <Ionicons name="copy-outline" size={20} color={appColors.primary} />
              <Text style={styles.secondaryText}>کپی رسید</Text>
            </TouchableOpacity>
            <TouchableOpacity style={[styles.action, styles.primary]} onPress={downloadPdf} disabled={busyAction !== null}>
              {busyAction === 'pdf' ? <ActivityIndicator color="#fff" /> : (
                <>
                  <Ionicons name="download-outline" size={20} color={appColors.background} />
                  <Text style={styles.primaryText}>ذخیره رسید</Text>
                </>
              )}
            </TouchableOpacity>
            <TouchableOpacity style={[styles.action, styles.secondary]} onPress={shareReceipt} disabled={busyAction !== null}>
              {busyAction === 'share' ? <ActivityIndicator color={appColors.primary} /> : (
                <>
                  <Ionicons name="share-social-outline" size={20} color={appColors.primary} />
                  <Text style={styles.secondaryText}>اشتراک‌گذاری رسید</Text>
                </>
              )}
            </TouchableOpacity>
          </View>
        </ScrollView>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: appColors.background },
  header: { height: 62, paddingHorizontal: 16, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between' },
  backIcon: { width: 42, height: 42, borderRadius: 13, backgroundColor: appColors.surface, borderWidth: 1, borderColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' },
  title: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text },
  headerSpace: { width: 42 },
  content: { width: '100%', maxWidth: 720, alignSelf: 'center', padding: 16, paddingBottom: 36 },
  actions: { gap: 10, marginTop: 16 },
  action: { minHeight: 50, borderRadius: 13, flexDirection: 'row-reverse', gap: 8, alignItems: 'center', justifyContent: 'center' },
  primary: { backgroundColor: appColors.primary },
  primaryText: { fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.background },
  secondary: { backgroundColor: appColors.surfaceRaised, borderWidth: 1, borderColor: '#99f6e4' },
  secondaryText: { fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.primary },
  state: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 },
  stateText: { fontFamily: 'Vazirmatn', color: appColors.textMuted, marginTop: 12, textAlign: 'center', writingDirection: 'rtl' },
  retry: { backgroundColor: appColors.primary, borderRadius: 12, paddingHorizontal: 20, paddingVertical: 11, marginTop: 16 },
  retryText: { fontFamily: 'VazirmatnBold', color: '#fff' },
});
