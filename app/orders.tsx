import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import { Stack, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { fetchJson, isAuthenticationResponseError } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';

type ActivityOrder = {
  id: string;
  type: string;
  title: string;
  amount: number;
  currency: string;
  status: string;
  created_at: string | null;
  description: string;
  metadata?: Record<string, unknown>;
};

const statusLabels: Record<string, { label: string; color: string; background: string }> = {
  completed: { label: 'تکمیل شده', color: '#16845f', background: '#e7f7ef' },
  approved: { label: 'تکمیل شده', color: '#16845f', background: '#e7f7ef' },
  success: { label: 'تکمیل شده', color: '#16845f', background: '#e7f7ef' },
  pending: { label: 'در انتظار', color: '#a46a20', background: '#fff4df' },
  processing: { label: 'در حال بررسی', color: '#a46a20', background: '#fff4df' },
  failed: { label: 'ناموفق', color: '#b34343', background: '#fff0ef' },
  rejected: { label: 'ناموفق', color: '#b34343', background: '#fff0ef' },
};

const iconByType: Record<string, keyof typeof Ionicons.glyphMap> = {
  remittance: 'send-outline', deposit: 'wallet-outline', withdrawal: 'arrow-down-circle-outline', exchange: 'swap-horizontal-outline',
};

const formatAmount = (value: number) => `${Number(value || 0).toLocaleString('fa-IR')} تومان`;

const formatDate = (value: string | null) => {
  if (!value) return 'تاریخ ثبت نشده';
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('fa-IR', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).format(date);
};

export default function OrdersScreen() {
  const router = useRouter();
  const { userToken, isAuthenticated, isInitialized } = useAuth();
  const [orders, setOrders] = useState<ActivityOrder[]>([]);
  const [latestActivity, setLatestActivity] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  const loadOrders = useCallback(async () => {
    if (!isInitialized) return;
    if (!isAuthenticated || !userToken) {
      setLoading(false);
      router.replace('/login' as any);
      return;
    }
    setLoading(true);
    setError(false);
    try {
      const body = new URLSearchParams({ api_token: String(userToken) });
      const result = await fetchJson<{ success?: boolean; data?: { orders?: ActivityOrder[]; latest_activity?: string | null }; code?: string }>('orders.php', {
        method: 'POST', body: body.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      });
      if (result?.success !== true || !result.data) throw new Error('Invalid orders response');
      setOrders(Array.isArray(result.data.orders) ? result.data.orders : []);
      setLatestActivity(result.data.latest_activity || null);
    } catch (requestError) {
      if (isAuthenticationResponseError(requestError)) return;
      setError(true);
    } finally {
      setLoading(false);
    }
  }, [isAuthenticated, isInitialized, router, userToken]);

  useEffect(() => { loadOrders(); }, [loadOrders]);

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.screen}>
        <View style={styles.container}>
          <View style={styles.header}>
            <TouchableOpacity style={styles.backButton} onPress={() => router.back()} accessibilityRole="button" accessibilityLabel="بازگشت"><Ionicons name="arrow-forward" size={21} color="#243247" /></TouchableOpacity>
            <Text style={styles.title}>سفارش‌ها</Text>
            <View style={styles.headerSpace} />
          </View>

          {loading ? <StateView loading /> : error ? <StateView onRetry={loadOrders} /> : (
            <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
              <View style={styles.summaryCard}>
                <View style={styles.summaryIcon}><Ionicons name="pulse-outline" size={23} color="#0e9471" /></View>
                <View style={styles.summaryText}><Text style={styles.summaryCaption}>مرکز فعالیت‌های شما</Text><Text style={styles.summaryTitle}>تاریخچه سفارش‌ها و فعالیت‌ها</Text></View>
                <View style={styles.totalBox}><Text style={styles.totalValue}>{orders.length.toLocaleString('fa-IR')}</Text><Text style={styles.totalLabel}>فعالیت</Text></View>
              </View>
              <View style={styles.latestRow}><Ionicons name="time-outline" size={15} color="appColors.textSecondary" /><Text style={styles.latestText}>آخرین فعالیت: {formatDate(latestActivity)}</Text></View>
              {orders.length === 0 ? <EmptyState /> : <View style={styles.activityCard}>{orders.map((order, index) => <ActivityRow key={order.id} order={order} last={index === orders.length - 1} />)}</View>}
            </ScrollView>
          )}
        </View>
      </View>
    </SafeAreaView>
  );
}

function ActivityRow({ order, last }: { order: ActivityOrder; last: boolean }) {
  const router = useRouter();
  const status = statusLabels[order.status] || statusLabels.pending;
  const icon = iconByType[order.type] || 'list-outline';
  return <View style={[styles.activityRow, last && styles.lastActivityRow]}>
    <View style={styles.activityIcon}><Ionicons name={icon} size={21} color="#0e9270" /></View>
    <View style={styles.activityMain}><View style={styles.activityHeading}><Text style={styles.activityTitle}>{order.title}</Text><View style={[styles.statusBadge, { backgroundColor: status.background }]}><Text style={[styles.statusText, { color: status.color }]}>{status.label}</Text></View></View><Text style={styles.activityDescription}>{order.description}</Text><Text style={styles.activityDate}>{formatDate(order.created_at)}</Text></View>
    <View style={styles.amountBox}><Text style={styles.amount}>{formatAmount(order.amount)}</Text><Text style={styles.currency}>{order.currency || 'تومان'}</Text>{order.type === 'remittance' && <TouchableOpacity style={styles.receiptButton} onPress={() => router.push({ pathname: '/remittance-receipt', params: { order: order.id } } as any)} accessibilityRole="button"><Text style={styles.receiptButtonText}>مشاهده رسید</Text></TouchableOpacity>}</View>
  </View>;
}

function EmptyState() {
  return <View style={styles.emptyState}><View style={styles.emptyIcon}><Ionicons name="receipt-outline" size={32} color="#7d9298" /></View><Text style={styles.emptyTitle}>هنوز سفارشی ثبت نکرده‌اید</Text><Text style={styles.emptyCaption}>فعالیت‌های مالی و سفارش‌های شما در این بخش نمایش داده می‌شوند.</Text></View>;
}

function StateView({ loading = false, onRetry }: { loading?: boolean; onRetry?: () => void }) {
  return <View style={styles.stateWrap}><View style={styles.stateIcon}>{loading ? <ActivityIndicator color="#01F8FD" /> : <Ionicons name="cloud-offline-outline" size={28} color="#c47a24" />}</View><Text style={styles.stateTitle}>{loading ? 'در حال دریافت تاریخچه' : 'دریافت تاریخچه انجام نشد'}</Text>{!loading && <TouchableOpacity style={styles.retryButton} onPress={onRetry}><Text style={styles.retryText}>تلاش مجدد</Text></TouchableOpacity>}</View>;
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: appColors.background }, screen: { flex: 1, backgroundColor: appColors.background }, container: { flex: 1, width: '100%', maxWidth: 860, alignSelf: 'center', paddingHorizontal: 16, paddingTop: 14 },
  header: { height: 52, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }, backButton: { width: 44, height: 44, borderRadius: 14, backgroundColor: appColors.surface, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: appColors.surfaceRaised }, title: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text }, headerSpace: { width: 44, height: 44 }, content: { paddingBottom: 32 },
  summaryCard: { flexDirection: 'row-reverse', alignItems: 'center', backgroundColor: appColors.surface, borderRadius: 22, padding: 18, borderWidth: 1, borderColor: appColors.surfaceRaised, shadowColor: '#123c4a', shadowOpacity: 0.07, shadowRadius: 12, shadowOffset: { width: 0, height: 5 }, elevation: 2 }, summaryIcon: { width: 48, height: 48, borderRadius: 16, backgroundColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' }, summaryText: { flex: 1, alignItems: 'flex-end', marginHorizontal: 13 }, summaryCaption: { width: '100%', fontFamily: 'Vazirmatn', fontSize: 11, color: appColors.textSecondary, textAlign: 'right' }, summaryTitle: { width: '100%', fontFamily: 'VazirmatnBold', fontSize: 14, color: appColors.text, textAlign: 'right', marginTop: 4 }, totalBox: { minWidth: 52, alignItems: 'center', borderRightWidth: 1, borderRightColor: appColors.surfaceRaised, paddingRight: 12 }, totalValue: { fontFamily: 'VazirmatnBold', fontSize: 19, color: appColors.primary }, totalLabel: { fontFamily: 'Vazirmatn', fontSize: 10, color: appColors.textMuted, marginTop: 1 }, latestRow: { flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'flex-start', gap: 5, marginTop: 13, paddingHorizontal: 4 }, latestText: { fontFamily: 'Vazirmatn', fontSize: 10, color: appColors.textMuted },
  activityCard: { backgroundColor: appColors.surface, borderRadius: 20, marginTop: 13, paddingHorizontal: 17, borderWidth: 1, borderColor: appColors.surfaceRaised }, activityRow: { minHeight: 102, flexDirection: 'row-reverse', alignItems: 'center', columnGap: 12, paddingVertical: 15, borderBottomWidth: 1, borderBottomColor: appColors.surfaceRaised }, lastActivityRow: { borderBottomWidth: 0 }, activityIcon: { width: 44, height: 44, borderRadius: 14, backgroundColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' }, activityMain: { flex: 1, minWidth: 0, alignItems: 'flex-end' }, activityHeading: { width: '100%', flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'flex-start', gap: 8 }, activityTitle: { fontFamily: 'VazirmatnBold', fontSize: 14, color: appColors.text }, statusBadge: { borderRadius: 10, paddingHorizontal: 7, paddingVertical: 4 }, statusText: { fontFamily: 'Vazirmatn', fontSize: 9 }, activityDescription: { width: '100%', fontFamily: 'Vazirmatn', fontSize: 10, color: appColors.textSecondary, textAlign: 'right', marginTop: 5 }, activityDate: { width: '100%', fontFamily: 'Vazirmatn', fontSize: 10, color: appColors.textMuted, textAlign: 'right', marginTop: 3 }, amountBox: { alignItems: 'flex-end', minWidth: 92 }, amount: { fontFamily: 'VazirmatnBold', fontSize: 12, color: appColors.text, textAlign: 'right' }, currency: { fontFamily: 'Vazirmatn', fontSize: 9, color: appColors.textMuted, marginTop: 2 }, receiptButton: { marginTop: 8, paddingHorizontal: 9, paddingVertical: 6, borderRadius: 9, backgroundColor: appColors.surfaceRaised }, receiptButtonText: { fontFamily: 'VazirmatnBold', fontSize: 9, color: appColors.primary },
  emptyState: { alignItems: 'center', backgroundColor: appColors.surface, borderRadius: 20, marginTop: 13, paddingVertical: 48, paddingHorizontal: 24, borderWidth: 1, borderColor: appColors.surfaceRaised }, emptyIcon: { width: 68, height: 68, borderRadius: 34, backgroundColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' }, emptyTitle: { fontFamily: 'VazirmatnBold', fontSize: 15, color: appColors.textSecondary, marginTop: 15 }, emptyCaption: { fontFamily: 'Vazirmatn', fontSize: 11, color: appColors.textMuted, textAlign: 'center', marginTop: 6, lineHeight: 20 }, stateWrap: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingBottom: 80 }, stateIcon: { width: 58, height: 58, borderRadius: 29, backgroundColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' }, stateTitle: { fontFamily: 'VazirmatnBold', color: '#2d4652', fontSize: 14, marginTop: 15 }, retryButton: { minHeight: 42, marginTop: 18, paddingHorizontal: 20, borderRadius: 12, backgroundColor: appColors.primary, alignItems: 'center', justifyContent: 'center' }, retryText: { fontFamily: 'VazirmatnBold', color: '#fff', fontSize: 12 },
});
