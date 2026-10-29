import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import * as Print from 'expo-print';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import * as Sharing from 'expo-sharing';
import * as Clipboard from 'expo-clipboard';
import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { RemittanceReceiptView } from '@/components/remittance-receipt-view';
import { fetchJson } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { buildRemittanceReceiptText, RemittanceReceiptModel } from '@/lib/remittance-receipt';
import { createRemittanceReceiptPdf } from '@/lib/remittance-receipt-native';
import { showError, showSuccess } from '@/lib/toast';

type ReceiptOrder = {
  id: string;
  type: string;
  status: string;
  created_at: string | null;
  metadata?: {
    tracking_number?: string | number;
    sender?: string;
    receiver?: string;
    amount_afghani?: string | number;
    amount_toman?: string | number;
    fee_amount?: string | number;
    total_amount?: string | number;
    fee_type?: string;
    fee_value?: string | number;
    destination?: string;
    destination_country_name?: string | null;
  };
};

const formatReceiptDate = (value: string | null) => {
  if (!value) return '';
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('fa-IR-u-ca-persian', {
    year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(date);
};

export default function RemittanceReceiptScreen() {
  const router = useRouter();
  const { order } = useLocalSearchParams<{ order?: string }>();
  const { userToken, isAuthenticated, isInitialized } = useAuth();
  const [receipt, setReceipt] = useState<RemittanceReceiptModel | null>(null);
  const [loading, setLoading] = useState(true);
  const [busyAction, setBusyAction] = useState<'pdf' | 'share' | null>(null);
  const orderKey = useMemo(() => typeof order === 'string' ? order : '', [order]);

  const loadReceipt = useCallback(async () => {
    if (!isInitialized) return;
    if (!isAuthenticated || !userToken) {
      router.replace('/login' as never);
      return;
    }
    if (!/^remittance-\d+$/.test(orderKey)) {
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      const body = new URLSearchParams({ api_token: String(userToken) });
      const result = await fetchJson<{ success?: boolean; data?: { orders?: ReceiptOrder[] } }>('orders.php', {
        method: 'POST', body: body.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      });
      const owned = result?.data?.orders?.find((item) => item.id === orderKey && item.type === 'remittance');
      const metadata = owned?.metadata;
      if (!owned || !metadata?.tracking_number || !metadata.sender || !metadata.receiver || !metadata.destination) {
        setReceipt(null);
        return;
      }
      setReceipt({
        trackingNumber: String(metadata.tracking_number),
        date: formatReceiptDate(owned.created_at),
        sender: metadata.sender,
        receiver: metadata.receiver,
        amountAfghani: metadata.amount_afghani ?? 0,
        amountToman: metadata.amount_toman ?? 0,
        feeAmount: metadata.fee_amount ?? 0,
        totalCharged: metadata.total_amount ?? metadata.amount_toman ?? 0,
        feeType: metadata.fee_type ?? 'fixed',
        feeValue: metadata.fee_value ?? '0',
        destination: metadata.destination,
        destinationCountry: metadata.destination_country_name || undefined,
        status: owned.status,
      });
    } catch {
      setReceipt(null);
    } finally {
      setLoading(false);
    }
  }, [isAuthenticated, isInitialized, orderKey, router, userToken]);

  useEffect(() => { void loadReceipt(); }, [loadReceipt]);

  const downloadPdf = async () => {
    if (!receipt || busyAction) return;
    setBusyAction('pdf');
    try {
      const { uri } = await createRemittanceReceiptPdf(receipt);
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
      const { uri } = await createRemittanceReceiptPdf(receipt);
      await Sharing.shareAsync(uri, { mimeType: 'application/pdf', dialogTitle: 'اشتراک‌گذاری رسید حواله' });
    } catch {
      showError('خطا', 'اشتراک‌گذاری رسید انجام نشد.');
    } finally {
      setBusyAction(null);
    }
  };

  const copyReceipt = async () => {
    if (!receipt) return;
    await Clipboard.setStringAsync(buildRemittanceReceiptText(receipt));
    showSuccess('موفق', 'رسید کپی شد');
  };

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.header}>
        <TouchableOpacity style={styles.backIcon} onPress={() => router.back()} accessibilityLabel="بازگشت"><Ionicons name="arrow-forward" size={22} color="#334155" /></TouchableOpacity>
        <Text style={styles.title}>رسید حواله</Text><View style={styles.headerSpace} />
      </View>
      {loading ? (
        <View style={styles.state}><ActivityIndicator color={appColors.primary} /><Text style={styles.stateText}>در حال دریافت رسید...</Text></View>
      ) : !receipt ? (
        <View style={styles.state}><Ionicons name="document-text-outline" size={42} color="#94a3b8" /><Text style={styles.stateText}>رسید این حواله در دسترس نیست.</Text><TouchableOpacity style={styles.retry} onPress={loadReceipt}><Text style={styles.retryText}>تلاش مجدد</Text></TouchableOpacity></View>
      ) : (
        <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
          <RemittanceReceiptView receipt={receipt} />
          <View style={styles.actions}>
            <TouchableOpacity style={[styles.action, styles.secondary]} onPress={copyReceipt} disabled={busyAction !== null}>
              <Ionicons name="copy-outline" size={20} color={appColors.primary} /><Text style={styles.secondaryText}>کپی رسید</Text>
            </TouchableOpacity>
            <TouchableOpacity style={[styles.action, styles.primary]} onPress={downloadPdf} disabled={busyAction !== null}>
              {busyAction === 'pdf' ? <ActivityIndicator color="#fff" /> : <><Ionicons name="download-outline" size={20} color="#fff" /><Text style={styles.primaryText}>دریافت PDF</Text></>}
            </TouchableOpacity>
            <TouchableOpacity style={[styles.action, styles.secondary]} onPress={shareReceipt} disabled={busyAction !== null}>
              {busyAction === 'share' ? <ActivityIndicator color={appColors.primary} /> : <><Ionicons name="share-social-outline" size={20} color={appColors.primary} /><Text style={styles.secondaryText}>اشتراک‌گذاری رسید</Text></>}
            </TouchableOpacity>
            <TouchableOpacity style={[styles.action, styles.back]} onPress={() => router.back()} disabled={busyAction !== null}><Text style={styles.backText}>بازگشت</Text></TouchableOpacity>
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
  title: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text }, headerSpace: { width: 42 },
  content: { width: '100%', maxWidth: 720, alignSelf: 'center', padding: 16, paddingBottom: 36 },
  actions: { gap: 10, marginTop: 16 }, action: { minHeight: 50, borderRadius: 13, flexDirection: 'row-reverse', gap: 8, alignItems: 'center', justifyContent: 'center' },
  primary: { backgroundColor: appColors.primary }, primaryText: { fontFamily: 'VazirmatnBold', fontSize: 13, color: '#fff' },
  secondary: { backgroundColor: appColors.surfaceRaised, borderWidth: 1, borderColor: appColors.accent }, secondaryText: { fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.primary },
  back: { backgroundColor: appColors.surface, borderWidth: 1, borderColor: appColors.surfaceRaised }, backText: { fontFamily: 'Vazirmatn', fontSize: 13, color: appColors.textSecondary },
  state: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 }, stateText: { fontFamily: 'Vazirmatn', color: appColors.textMuted, marginTop: 12 },
  retry: { backgroundColor: appColors.primary, borderRadius: 12, paddingHorizontal: 20, paddingVertical: 11, marginTop: 16 }, retryText: { fontFamily: 'VazirmatnBold', color: '#fff' },
});
