import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import { Stack, useRouter, useRootNavigationState } from 'expo-router';
import { useFocusEffect } from '@react-navigation/native';
import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { fetchJson } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { showError } from '@/lib/toast';

const LEVELS = [
  { key: 'bronze', title: 'برنزی', color: '#b45309' },
  { key: 'silver', title: 'نقره‌ای', color: appColors.textMuted },
  { key: 'gold', title: 'طلایی', color: '#ca8a04' },
];

const money = (value: unknown) => value === null || value === undefined ? 'تنظیم نشده' : `${Number(value || 0).toLocaleString('en-US')} تومان`;

export default function AccessLevelScreen() {
  const router = useRouter();
  const rootState = useRootNavigationState();
  const { userToken, isAuthenticated, isInitialized } = useAuth();
  const [state, setState] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  const loadState = useCallback(async () => {
    if (!userToken) return;
    try {
      const body = new URLSearchParams({ api_token: String(userToken) });
      const result = await fetchJson<any>('profile.php', { method: 'POST', body: body.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      setState({ ...(result?.access_level || {}), ...(result?.verification || {}), ...(result?.limits || {}), mobile: result?.user?.mobile || null });
    } catch {
      showError('خطا', 'دریافت اطلاعات سطح کاربری انجام نشد.');
    } finally {
      setLoading(false);
    }
  }, [userToken]);

  useEffect(() => {
    if (!rootState?.key || !isInitialized) return;
    if (!isAuthenticated) { router.replace('/login' as any); return; }
    loadState();
  }, [isAuthenticated, isInitialized, loadState, rootState?.key, router]);

  useFocusEffect(useCallback(() => { if (isAuthenticated) loadState(); }, [isAuthenticated, loadState]));

  const level = state?.level || 'bronze';
  const levelIndex = Math.max(0, LEVELS.findIndex((item) => item.key === level));
  const pending = state?.upgrade_request_status === 'pending';
  const requestType = state?.upgrade_request_type;

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.container}>
        <View style={styles.header}>
          <TouchableOpacity style={styles.backButton} onPress={() => router.back()}><Ionicons name="arrow-forward" size={22} color="#374151" /></TouchableOpacity>
          <Text style={styles.title}>سطح دسترسی</Text><View style={styles.headerSpace} />
        </View>
        {loading ? <ActivityIndicator color="#0ed874" style={styles.loader} /> : <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
          <View style={styles.levelRow}>{LEVELS.map((item, index) => <View key={item.key} style={[styles.levelPill, index <= levelIndex && { borderColor: item.color, backgroundColor: `${item.color}12` }]}><Text style={[styles.levelText, index <= levelIndex && { color: item.color }]}>{item.title}</Text></View>)}</View>
          <View style={styles.card}>
            <Ionicons name="shield-checkmark" size={34} color={LEVELS[levelIndex].color} />
            <Text style={styles.levelTitle}>سطح فعلی: {state?.level_title || LEVELS[levelIndex].title}</Text>
            <Text style={styles.phone}>شماره موبایل: {state?.mobile || '****'}</Text>
            <Text style={[styles.status, !state?.bronze_eligible && styles.unverified]}>{state?.bronze_eligible ? 'شماره موبایل یا ایمیل تأیید شده است' : 'شماره موبایل یا ایمیل هنوز تأیید نشده است'}</Text>
            {!state?.phone_verified && <TouchableOpacity style={styles.secondaryButton} onPress={() => router.push('/phone-verification' as any)}><Text style={styles.secondaryText}>تأیید شماره موبایل</Text></TouchableOpacity>}
          </View>
          <View style={styles.infoCard}>
            <Text style={styles.label}>سقف تراکنش روزانه</Text><Text style={styles.value}>{money(state?.daily_limit)}</Text>
            <Text style={styles.label}>مصرف امروز</Text><Text style={styles.valueSmall}>{money(state?.used_today)}</Text>
            <Text style={styles.label}>باقی‌مانده امروز</Text><Text style={styles.valueSmall}>{money(state?.remaining_today)}</Text>
          </View>
          {level === 'bronze' && <View style={styles.actionBox}><Text style={styles.description}>برای افزایش سقف روزانه، تصویر مدرک هویتی و سلفی با مدرک را ارسال کنید.</Text><TouchableOpacity style={styles.button} onPress={() => router.push('/verification-upgrade?type=silver' as any)} disabled={pending && requestType === 'silver'}><Text style={styles.buttonText}>{pending && requestType === 'silver' ? 'درخواست نقره‌ای در حال بررسی است' : 'ارتقاء به سطح نقره‌ای'}</Text></TouchableOpacity></View>}
          {level === 'silver' && <View style={styles.actionBox}><Text style={styles.description}>برای دریافت سطح طلایی، ویدیوی احراز هویت و کارت بانکی به نام خود را ارسال کنید.</Text><TouchableOpacity style={styles.button} onPress={() => router.push('/verification-upgrade?type=gold' as any)} disabled={pending && requestType === 'gold'}><Text style={styles.buttonText}>{pending && requestType === 'gold' ? 'درخواست طلایی در حال بررسی است' : 'ارتقاء به سطح طلایی'}</Text></TouchableOpacity></View>}
          {state?.upgrade_request_status === 'rejected' && <View style={styles.warning}><Text style={styles.warningText}>درخواست قبلی رد شده است: {state?.rejection_reason || 'لطفاً مدارک را اصلاح و دوباره ارسال کنید.'}</Text></View>}
          {state?.upgrade_request_status === 'approved' && <View style={styles.approved}><Text style={styles.approvedText}>درخواست احراز هویت شما تأیید شده است.</Text></View>}
        </ScrollView>}
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: appColors.background }, container: { flex: 1, paddingHorizontal: 18, paddingTop: 18 },
  header: { flexDirection: 'row-reverse', justifyContent: 'space-between', alignItems: 'center', marginBottom: 18 }, backButton: { width: 40, height: 40, borderRadius: 20, backgroundColor: appColors.surface, alignItems: 'center', justifyContent: 'center' }, title: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text }, headerSpace: { width: 40 }, loader: { marginTop: 50 }, content: { paddingBottom: 32 },
  levelRow: { flexDirection: 'row-reverse', gap: 8, marginBottom: 14 }, levelPill: { flex: 1, borderWidth: 1, borderColor: appColors.surfaceRaised, borderRadius: 12, paddingVertical: 10, alignItems: 'center' }, levelText: { fontFamily: 'VazirmatnBold', fontSize: 12, color: appColors.textMuted },
  card: { backgroundColor: appColors.surface, borderRadius: 20, padding: 22, alignItems: 'center', marginBottom: 14 }, levelTitle: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text, marginTop: 10 }, phone: { fontFamily: 'Vazirmatn', color: appColors.textMuted, marginTop: 6 }, status: { fontFamily: 'Vazirmatn', color: '#059669', marginTop: 8, textAlign: 'center' }, unverified: { color: '#dc2626' }, secondaryButton: { marginTop: 14, padding: 8 }, secondaryText: { fontFamily: 'VazirmatnBold', color: appColors.primary },
  infoCard: { backgroundColor: appColors.surface, borderRadius: 16, padding: 20, marginBottom: 14 }, label: { fontFamily: 'Vazirmatn', fontSize: 12, color: appColors.textMuted, textAlign: 'right', marginTop: 5 }, value: { fontFamily: 'VazirmatnBold', fontSize: 22, color: appColors.primary, textAlign: 'right', marginBottom: 8 }, valueSmall: { fontFamily: 'VazirmatnBold', fontSize: 16, color: appColors.textSecondary, textAlign: 'right', marginBottom: 5 },
  actionBox: { backgroundColor: appColors.surface, borderRadius: 16, padding: 20 }, description: { fontFamily: 'Vazirmatn', color: appColors.textSecondary, textAlign: 'right', lineHeight: 24 }, button: { backgroundColor: appColors.primary, borderRadius: 12, minHeight: 52, alignItems: 'center', justifyContent: 'center', marginTop: 16, paddingHorizontal: 12 }, buttonText: { fontFamily: 'VazirmatnBold', color: '#fff' }, warning: { backgroundColor: '#fffbeb', borderRadius: 12, padding: 16, marginTop: 14 }, warningText: { fontFamily: 'Vazirmatn', color: '#92400e', textAlign: 'right' }, approved: { backgroundColor: appColors.surfaceRaised, borderRadius: 12, padding: 16, marginTop: 14 }, approvedText: { fontFamily: 'Vazirmatn', color: '#047857', textAlign: 'right' },
});
