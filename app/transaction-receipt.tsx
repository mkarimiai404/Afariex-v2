import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Image, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { SafeAreaView } from 'react-native-safe-area-context';
import { fetchJson } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { appColors } from '@/lib/theme';

type Receipt = { id: string; amount: string; type: string; status: string; created_at: string; description: string; tracking_code: string; receipt_full_url: string };
export default function TransactionReceiptScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const router = useRouter();
  const { userToken, isAuthenticated, isInitialized } = useAuth();
  const [receipt, setReceipt] = useState<Receipt | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  useEffect(() => {
    if (!isInitialized) return;
    if (!isAuthenticated || !userToken) { router.replace('/login' as never); return; }
    const safeId = typeof id === 'string' ? id : '';
    if (!/^\d+$/.test(safeId)) { setError('شناسه رسید معتبر نیست.'); setLoading(false); return; }
    let active = true;
    const body = new URLSearchParams({ api_token: String(userToken), id: safeId });
    void fetchJson<{ success?: boolean; data?: Receipt }>('transaction-receipt.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString() })
      .then((result) => { if (active && result.success && result.data) setReceipt(result.data); else if (active) setError('رسید پیدا نشد.'); })
      .catch(() => { if (active) setError('دریافت رسید انجام نشد.'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [id, isAuthenticated, isInitialized, router, userToken]);
  return <SafeAreaView style={styles.screen}><Stack.Screen options={{ headerShown: false }} /><View style={styles.header}><TouchableOpacity onPress={() => router.back()}><Ionicons name="arrow-forward" size={22} color={appColors.text} /></TouchableOpacity><Text style={styles.title}>رسید تراکنش</Text><View style={{ width: 22 }} /></View>{loading ? <ActivityIndicator style={styles.center} color={appColors.primary} /> : error || !receipt ? <Text style={styles.message}>{error || 'رسید در دسترس نیست.'}</Text> : <ScrollView contentContainerStyle={styles.content}><View style={styles.card}><Text style={styles.brand}>رسید تراکنش</Text>{receipt.receipt_full_url ? <Image source={{ uri: receipt.receipt_full_url }} style={styles.image} resizeMode="contain" /> : <Text style={styles.notice}>تصویر رسید بارگذاری‌شده‌ای برای این تراکنش موجود نیست.</Text>}<Row label="شناسه تراکنش" value={receipt.id} />{receipt.tracking_code ? <Row label="کد پیگیری" value={receipt.tracking_code} /> : null}<Row label="مبلغ" value={`${receipt.amount || '—'} تومان`} /><Row label="وضعیت" value={receipt.status || '—'} /><Row label="تاریخ" value={receipt.created_at || '—'} />{receipt.description ? <Row label="شرح" value={receipt.description} /> : null}</View></ScrollView>}</SafeAreaView>;
}
function Row({ label, value }: { label: string; value: string }) { return <View style={styles.row}><Text style={styles.value}>{value}</Text><Text style={styles.label}>{label}</Text></View>; }
const styles = StyleSheet.create({ screen: { flex: 1, backgroundColor: appColors.background }, header: { height: 54, paddingHorizontal: 18, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between' }, title: { fontFamily: 'VazirmatnBold', color: appColors.text, fontSize: 17 }, content: { padding: 16 }, card: { backgroundColor: appColors.surface, borderRadius: 18, padding: 18, borderWidth: 1, borderColor: appColors.surfaceRaised }, brand: { fontFamily: 'VazirmatnBold', fontSize: 19, color: appColors.primary, textAlign: 'center', marginBottom: 16 }, image: { width: '100%', height: 280, marginBottom: 14 }, notice: { fontFamily: 'Vazirmatn', color: appColors.textSecondary, textAlign: 'center', backgroundColor: appColors.surfaceRaised, borderRadius: 10, padding: 12, marginBottom: 12 }, row: { minHeight: 48, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', borderTopWidth: 1, borderTopColor: appColors.surfaceRaised, gap: 12 }, label: { fontFamily: 'Vazirmatn', color: appColors.textMuted, fontSize: 12 }, value: { flex: 1, fontFamily: 'VazirmatnBold', color: appColors.text, textAlign: 'left', fontSize: 12 }, center: { flex: 1 }, message: { textAlign: 'center', marginTop: 40, color: appColors.textSecondary, fontFamily: 'Vazirmatn' } });
