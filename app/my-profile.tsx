import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import { Stack, useRouter, useRootNavigationState } from 'expo-router';
import { useFocusEffect } from '@react-navigation/native';
import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, ScrollView, StyleSheet, Text, TouchableOpacity, useWindowDimensions, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { SafeAreaView } from 'react-native-safe-area-context';
import { apiUrl, fetchJson, isAuthenticationResponseError } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';

const fallback = 'ثبت نشده';

function verificationLabel(verification: any): string {
  if (verification?.upgrade_request_status === 'pending') return 'در انتظار بررسی';
  if (verification?.upgrade_request_status === 'rejected') return 'نیازمند اصلاح';
  if (verification?.level === 'gold') return 'احراز هویت طلایی تأیید شده';
  if (verification?.level === 'silver') return 'احراز هویت نقره‌ای تأیید شده';
  if (verification?.phone_verified) return 'شماره موبایل تأیید شده';
  if (verification?.email_verified) return 'ایمیل تأیید شده';
  return 'احراز هویت نشده';
}

function accountStatus(user: any): string {
  if (user?.is_active !== undefined && user?.is_active !== null) {
    return Number(user.is_active) === 1 ? 'فعال' : 'غیرفعال';
  }
  if (user?.account_status === 'active' || user?.status === 'active') return 'فعال';
  if (user?.account_status === 'inactive' || user?.status === 'inactive') return 'غیرفعال';
  return fallback;
}

function levelTitle(verification: any): string {
  if (verification?.level_title) return String(verification.level_title);
  if (verification?.level === 'gold') return 'طلایی';
  if (verification?.level === 'silver') return 'نقره‌ای';
  return 'برنزی';
}

function displayValue(value: unknown): string {
  const text = String(value ?? '').trim();
  return text || fallback;
}

export default function MyProfileScreen() {
  const router = useRouter();
  const navigationState = useRootNavigationState();
  const { userId, userToken, isAuthenticated, isInitialized } = useAuth();
  const { width } = useWindowDimensions();
  const isWide = width >= 720;
  const [user, setUser] = useState<any>(null);
  const [verification, setVerification] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  const loadProfile = useCallback(async () => {
    if (!isInitialized) return;
    if (!isAuthenticated || !userToken) {
      setLoading(false);
      setError(false);
      router.replace('/login' as any);
      return;
    }
    setLoading(true);
    setError(false);
    try {
      const body = new URLSearchParams({ api_token: String(userToken) });
      const result = await fetchJson<any>('profile.php', { method: 'POST', body: body.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      if (__DEV__) {
        if (__DEV__) console.log('[MyProfile] response keys', {
          root: Object.keys(result || {}).sort(),
          user: Object.keys(result?.user || {}).sort(),
          verification: Object.keys(result?.verification || {}).sort(),
        });
      }
      if (result?.success !== true || !result?.user || typeof result.user !== 'object') {
        throw new Error('Invalid profile response');
      }
      setUser(result.user);
      setVerification(result.verification && typeof result.verification === 'object' ? result.verification : null);
    } catch (requestError) {
      const message = requestError instanceof Error ? requestError.message : String(requestError);
      const status = (requestError as Error & { status?: number }).status || message.match(/HTTP\s+(\d{3})/i)?.[1] || 'unknown';
      const responseCode = (requestError as Error & { responseCode?: string }).responseCode || 'unknown';
      if (__DEV__) {
        if (__DEV__) console.warn('[MyProfile] profile request failed', {
          endpoint: apiUrl('profile.php'),
          httpStatus: status,
          tokenPresent: Boolean(userToken),
          responseCode,
        });
      }
      if (isAuthenticationResponseError(requestError)) {
        setError(false);
        return;
      }
      setError(true);
    } finally {
      setLoading(false);
    }
  }, [isAuthenticated, isInitialized, router, userToken]);

  useEffect(() => {
    if (!navigationState?.key || !isInitialized) return;
    if (!isAuthenticated) { router.replace('/login' as any); return; }
    loadProfile();
  }, [isAuthenticated, isInitialized, loadProfile, navigationState?.key, router]);

  useFocusEffect(useCallback(() => { if (isAuthenticated) loadProfile(); }, [isAuthenticated, loadProfile]));

  const fullName = displayValue(
    [user?.first_name, user?.last_name].filter((value) => String(value || '').trim() !== '').join(' ') || user?.full_name || user?.name
  );
  const level = levelTitle(verification);
  const verificationText = verificationLabel(verification);
  const statusText = accountStatus(user);
  const levelKey = String(verification?.level || 'bronze').toLowerCase();
  const levelColor = levelKey === 'gold' ? '#b7791f' : levelKey === 'silver' ? '#64748b' : '#a16207';
  const isVerified = verificationText !== 'احراز هویت نشده';

  const infoSections = [
    {
      title: 'اطلاعات شخصی',
      icon: 'person-outline' as const,
      items: [
        { label: 'نام و نام خانوادگی', value: fullName, icon: 'person-outline' as const },
        { label: 'شماره تماس', value: displayValue(user?.mobile), icon: 'call-outline' as const },
        { label: 'ایمیل', value: displayValue(user?.email), icon: 'mail-outline' as const },
        { label: 'شناسه کاربری', value: displayValue(user?.id ?? userId), icon: 'key-outline' as const },
      ],
    },
    {
      title: 'وضعیت حساب',
      icon: 'shield-checkmark-outline' as const,
      items: [
        { label: 'سطح کاربری', value: level, icon: 'ribbon-outline' as const },
        { label: 'وضعیت احراز هویت', value: verificationText, icon: 'checkmark-circle-outline' as const },
        { label: 'تاریخ عضویت', value: displayValue(user?.created_at), icon: 'calendar-outline' as const },
        { label: 'وضعیت حساب', value: statusText, icon: 'pulse-outline' as const },
      ],
    },
  ];

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.screen}>
        <View style={styles.container}>
          <View style={styles.header}>
            <TouchableOpacity style={styles.backButton} onPress={() => router.back()} accessibilityRole="button" accessibilityLabel="بازگشت">
              <Ionicons name="arrow-forward" size={21} color="#243247" />
            </TouchableOpacity>
            <Text style={styles.title}>پروفایل من</Text>
            <View style={styles.headerSpace} />
          </View>

          {loading ? (
            <View style={styles.stateWrap}>
              <View style={styles.loaderCircle}><ActivityIndicator color="#10a875" size="small" /></View>
              <Text style={styles.stateTitle}>در حال دریافت اطلاعات</Text>
              <Text style={styles.stateCaption}>لطفاً چند لحظه صبر کنید</Text>
            </View>
          ) : error ? (
            <View style={styles.stateWrap}>
              <View style={styles.errorCircle}><Ionicons name="cloud-offline-outline" size={30} color="#c47a24" /></View>
              <Text style={styles.stateTitle}>دریافت اطلاعات انجام نشد</Text>
              <Text style={styles.stateCaption}>اتصال خود را بررسی کرده و دوباره تلاش کنید.</Text>
              <TouchableOpacity style={styles.retry} onPress={loadProfile} accessibilityRole="button">
                <Ionicons name="refresh-outline" size={18} color="#fff" />
                <Text style={styles.retryText}>تلاش مجدد</Text>
              </TouchableOpacity>
            </View>
          ) : (
            <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
              <LinearGradient colors={['#123c4a', '#0d665c']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.hero}>
                <View style={styles.heroTop}>
                  <View style={styles.avatar}><Ionicons name="person" size={31} color={appColors.primary} /></View>
                  <View style={styles.heroIdentity}>
                    <Text style={styles.heroName} numberOfLines={2}>{fullName}</Text>
                    <Text style={styles.heroPhone} numberOfLines={1}>{displayValue(user?.mobile)}</Text>
                  </View>
                </View>
                <View style={styles.heroDivider} />
                <View style={styles.heroBottom}>
                  <View style={styles.heroLevel}><Ionicons name="ribbon-outline" size={17} color={appColors.primary} /><Text style={styles.heroLevelText}>{level}</Text></View>
                  <View style={styles.badges}>
                    <View style={[styles.badge, { backgroundColor: `${levelColor}e8` }]}><Text style={styles.badgeText}>{levelKey === 'gold' ? 'سطح طلایی' : levelKey === 'silver' ? 'سطح نقره‌ای' : 'سطح برنزی'}</Text></View>
                    <View style={[styles.badge, statusText === 'فعال' ? styles.activeBadge : styles.mutedBadge]}><Text style={styles.badgeText}>{statusText}</Text></View>
                    {!isVerified && <View style={styles.warningBadge}><Text style={styles.warningBadgeText}>احراز هویت نشده</Text></View>}
                  </View>
                </View>
              </LinearGradient>

              <View style={[styles.sections, isWide && styles.sectionsWide]}>
                {infoSections.map((section) => (
                  <View key={section.title} style={[styles.sectionCard, isWide && styles.sectionCardWide]}>
                    <View style={styles.sectionHeading}>
                      <View style={styles.sectionIcon}><Ionicons name={section.icon} size={18} color="appColors.primary" /></View>
                      <Text style={styles.sectionTitle}>{section.title}</Text>
                    </View>
                    <View style={styles.items}>
                      {section.items.map((item, index) => (
                        <View key={item.label} style={[styles.infoItem, index === section.items.length - 1 && styles.lastItem]}>
                          <View style={styles.itemIcon}><Ionicons name={item.icon} size={18} color="#4e7a86" /></View>
                          <View style={styles.itemText}>
                            <Text style={styles.itemLabel}>{item.label}</Text>
                            <Text style={styles.itemValue} numberOfLines={2}>{item.value}</Text>
                          </View>
                        </View>
                      ))}
                    </View>
                  </View>
                ))}
              </View>
            </ScrollView>
          )}
        </View>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: appColors.background },
  screen: { flex: 1, backgroundColor: appColors.background },
  container: { flex: 1, width: '100%', maxWidth: 860, alignSelf: 'center', paddingHorizontal: 16, paddingTop: 14 },
  header: { height: 52, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 },
  backButton: { width: 44, height: 44, borderRadius: 14, backgroundColor: appColors.surface, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: appColors.surfaceRaised },
  title: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text },
  headerSpace: { width: 44, height: 44 },
  content: { width: '100%', paddingBottom: 32 },
  hero: { borderRadius: 24, padding: 20, minHeight: 190, shadowColor: '#123c4a', shadowOpacity: 0.14, shadowRadius: 16, shadowOffset: { width: 0, height: 8 }, elevation: 4 },
  heroTop: { width: '100%', flexDirection: 'row-reverse', alignItems: 'center', direction: 'rtl', gap: 18 },
  avatar: { width: 72, height: 72, borderRadius: 36, backgroundColor: 'rgba(255,255,255,0.15)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.3)', alignItems: 'center', justifyContent: 'center' },
  heroIdentity: { flex: 1, minWidth: 0, alignItems: 'flex-end', gap: 7 },
  heroName: { width: '100%', fontFamily: 'VazirmatnBold', fontSize: 21, lineHeight: 31, color: '#fff', textAlign: 'right' },
  heroPhone: { width: '100%', fontFamily: 'Vazirmatn', fontSize: 13, color: '#c9e8e1', textAlign: 'right' },
  heroDivider: { height: 1, backgroundColor: 'rgba(255,255,255,0.16)', marginTop: 22, marginBottom: 20 },
  heroBottom: { width: '100%', flexDirection: 'row-reverse', alignItems: 'flex-start', justifyContent: 'space-between', gap: 14, direction: 'rtl' },
  heroLevel: { flexDirection: 'row-reverse', alignItems: 'center', gap: 7, marginTop: 4 },
  heroLevelText: { fontFamily: 'Vazirmatn', color: '#f5d28b', fontSize: 13 },
  badges: { flex: 1, flexDirection: 'row-reverse', flexWrap: 'wrap', justifyContent: 'flex-start', alignItems: 'center', columnGap: 9, rowGap: 9, gap: 9 },
  badge: { borderRadius: 20, paddingHorizontal: 10, paddingVertical: 5 },
  badgeText: { fontFamily: 'VazirmatnBold', fontSize: 10, color: '#fff', textAlign: 'right' },
  activeBadge: { backgroundColor: '#168b67' },
  mutedBadge: { backgroundColor: '#65747a' },
  warningBadge: { backgroundColor: '#9a682c', borderRadius: 20, paddingHorizontal: 10, paddingVertical: 5 },
  warningBadgeText: { fontFamily: 'VazirmatnBold', fontSize: 10, color: '#fff8e7', textAlign: 'right' },
  sections: { marginTop: 18, gap: 16 },
  sectionsWide: { flexDirection: 'row-reverse', flexWrap: 'wrap' },
  sectionCard: { backgroundColor: appColors.surface, borderRadius: 20, borderWidth: 1, borderColor: appColors.surfaceRaised, overflow: 'hidden', paddingTop: 2, paddingBottom: 8 },
  sectionCardWide: { width: '48.8%' },
  sectionHeading: { flexDirection: 'row-reverse', alignItems: 'center', paddingHorizontal: 18, paddingTop: 16, paddingBottom: 13, gap: 11 },
  sectionIcon: { width: 32, height: 32, borderRadius: 10, backgroundColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' },
  sectionTitle: { fontFamily: 'VazirmatnBold', fontSize: 15, color: appColors.text },
  items: { paddingHorizontal: 18 },
  infoItem: { minHeight: 78, flexDirection: 'row-reverse', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: appColors.surfaceRaised, paddingVertical: 14, columnGap: 17 },
  lastItem: { borderBottomWidth: 0 },
  itemIcon: { width: 44, height: 44, flexShrink: 0, borderRadius: 13, backgroundColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' },
  itemText: { flex: 1, alignItems: 'flex-end', minWidth: 0, paddingVertical: 1 },
  itemLabel: { width: '100%', fontFamily: 'Vazirmatn', fontSize: 11, lineHeight: 17, color: appColors.textSecondary, textAlign: 'right' },
  itemValue: { width: '100%', fontFamily: 'VazirmatnBold', fontSize: 14, lineHeight: 22, color: appColors.text, textAlign: 'right', marginTop: 4 },
  stateWrap: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingBottom: 80, paddingHorizontal: 30 },
  loaderCircle: { width: 58, height: 58, borderRadius: 29, backgroundColor: '#e5f6ef', alignItems: 'center', justifyContent: 'center' },
  errorCircle: { width: 58, height: 58, borderRadius: 29, backgroundColor: '#fff3df', alignItems: 'center', justifyContent: 'center' },
  stateTitle: { fontFamily: 'VazirmatnBold', fontSize: 16, color: appColors.text, marginTop: 16, textAlign: 'center' },
  stateCaption: { fontFamily: 'Vazirmatn', fontSize: 12, color: appColors.textMuted, marginTop: 6, textAlign: 'center' },
  retry: { minHeight: 44, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'center', gap: 8, marginTop: 20, paddingHorizontal: 20, borderRadius: 12, backgroundColor: appColors.primary },
  retryText: { fontFamily: 'VazirmatnBold', color: '#fff', fontSize: 13 },
});
