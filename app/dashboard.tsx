import React, { useEffect, useState } from 'react';
import { Alert, AppState, View, Text, StyleSheet, TouchableOpacity, ScrollView } from 'react-native';
import { useRouter, Stack, useRootNavigationState } from 'expo-router';
import { useFocusEffect } from '@react-navigation/native';
import { Ionicons, MaterialCommunityIcons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { SafeAreaView } from 'react-native-safe-area-context';

import { fetchJson } from '@/lib/api';
import { getRuntimeApiToken } from '@/lib/auth-runtime';
import { useAuth } from '@/lib/auth-context';
import { AppBottomNav } from '@/components/app-bottom-nav';
import { appColors } from '@/lib/theme';

const toPersianNum = (num: string | number) => {
  const farsiDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
  return num.toString().replace(/\d/g, (x) => farsiDigits[parseInt(x)]);
};

const formatPrice = (price: number) => {
  const formatted = price.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return toPersianNum(formatted);
};

const normalizeBalance = (value: unknown): number => {
  const numericValue = Number(typeof value === 'string' ? value.replace(/,/g, '').trim() : value);
  return Number.isFinite(numericValue) ? numericValue : 0;
};

export default function DashboardScreen() {
  const router = useRouter();
  const rootNavigationState = useRootNavigationState();
  const navigationReady = Boolean(rootNavigationState?.key);
  const { userId, userName, isAuthenticated, isInitialized, isSubuser, phoneVerified, kycStatus } = useAuth();
  const [walletBalance, setWalletBalance] = useState(0);
  const [userDebt, setUserDebt] = useState(0);
  const [loadError, setLoadError] = useState(false);
  const [verificationReady, setVerificationReady] = useState<boolean | null>(null);
  const unreadCount = 1;
  const financialFeaturesLocked = isSubuser && (verificationReady === false || (verificationReady === null && (!phoneVerified || kycStatus !== 'approved')));

  const openFinancialFeature = (path: string) => {
    if (financialFeaturesLocked) {
      Alert.alert('تکمیل امنیت حساب', 'برای استفاده از خدمات مالی، ابتدا تایید تلفن و احراز هویت را تکمیل کنید.');
      return;
    }
    router.push(path as any);
  };

  const fetchUserData = React.useCallback(async () => {
    if (!navigationReady || !isInitialized) {
      return;
    }

    try {
      const requestToken = getRuntimeApiToken();
      if (!isAuthenticated || !userId || !requestToken) {
        return;
      }

      setLoadError(false);

      const payload = new URLSearchParams();
      payload.append('user_id', userId);
      payload.append('id', userId);
      payload.append('uid', userId);
      payload.append('api_token', requestToken);
      payload.append('token', requestToken);
      payload.append('user_token', requestToken);

      const data = await fetchJson<any>('dashboard.php', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: payload.toString(),
      });

      const balanceValue =
        data?.balance ??
        data?.user?.balance ??
        data?.data?.balance ??
        data?.result?.balance;
      const verification = data?.verification ?? data?.data?.verification ?? data?.user?.verification;
      if (verification && typeof verification === 'object') {
        setVerificationReady(Boolean(verification.phone_verified) && ['silver', 'gold'].includes(String(verification.level ?? '').toLowerCase()));
      }

      const debtValue =
        data?.debt?.outstanding_toman ??
        data?.user?.debt?.outstanding_toman ??
        data?.data?.debt?.outstanding_toman ??
        0;

      setWalletBalance(normalizeBalance(balanceValue));
      setUserDebt(normalizeBalance(debtValue));
    } catch (error) {
      if (__DEV__) console.error('Error fetching balance:', error);
      setLoadError(true);
    }
  }, [isAuthenticated, isInitialized, navigationReady, userId]);

  useEffect(() => {
    if (!navigationReady || !isInitialized) {
      return;
    }

    if (!isAuthenticated) {
      setWalletBalance(0);
      setUserDebt(0);
      return;
    }
  }, [isAuthenticated, isInitialized, navigationReady]);

  useFocusEffect(
    React.useCallback(() => {
      if (navigationReady && isInitialized && isAuthenticated) {
        fetchUserData();
      }
    }, [fetchUserData, isAuthenticated, isInitialized, navigationReady])
  );

  useEffect(() => {
    const subscription = AppState.addEventListener('change', (nextState) => {
      if (nextState === 'active' && navigationReady && isInitialized && isAuthenticated) {
        fetchUserData();
      }
    });

    return () => subscription.remove();
  }, [fetchUserData, isAuthenticated, isInitialized, navigationReady]);

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />

      <View style={styles.outerBackground}>
        <ScrollView
          style={styles.mainContent}
          contentContainerStyle={styles.scrollContent}
          showsVerticalScrollIndicator={false}
        >
          <View style={styles.topHeader}>
            <TouchableOpacity
              style={styles.userInfo}
              onPress={() => router.push('/profile' as any)}
              activeOpacity={0.8}
              accessibilityRole="button"
              accessibilityLabel="پروفایل کاربر"
            >
              <View style={styles.avatar}>
                <Ionicons name="person" size={22} color={appColors.primary} />
              </View>
              <View style={styles.welcomeText}>
                <Text style={styles.welcomeSub}>سلام، خوش آمدید</Text>
                <Text style={styles.welcomeName}>{userName || 'کاربر گرامی'}</Text>
              </View>
            </TouchableOpacity>

            <TouchableOpacity style={styles.notificationBtn} onPress={() => router.push('/notifications' as any)}>
              <Ionicons name="notifications-outline" size={20} color="#4b5563" />
              {unreadCount > 0 && <View style={styles.notifBadgeIndicator} />}
            </TouchableOpacity>
          </View>

          <LinearGradient
            colors={[appColors.surfaceRaised, appColors.surface]}
            start={{ x: 0, y: 0 }}
            end={{ x: 1, y: 1 }}
            style={styles.walletCard}
          >
            <View style={styles.walletDecoration} />
            <Text style={styles.walletTitle}>موجودی قابل استفاده</Text>
            <View style={styles.walletBalanceContainer}>
              <Text style={styles.walletAmount}>{formatPrice(walletBalance)}</Text>
              <Text style={styles.walletCurrency}>تومان</Text>
            </View>
            <View style={styles.debtRow}>
              <Text style={styles.debtLabel}>بدهی</Text>
              <Text style={styles.debtValue}>{formatPrice(userDebt)} تومان</Text>
            </View>

            <View style={styles.walletActions}>
              <TouchableOpacity style={styles.btnDeposit} onPress={() => openFinancialFeature('/add-balance')}>
                <Text style={styles.btnDepositText}>افزایش موجودی</Text>
              </TouchableOpacity>

              <TouchableOpacity style={styles.btnHistory} onPress={() => router.push('/transactions-history' as any)}>
                <Text style={styles.btnHistoryText}>تاریخچه</Text>
              </TouchableOpacity>
            </View>
          </LinearGradient>

          <View style={styles.servicesSection}>
            {loadError && (
              <View style={styles.loadError}>
                <Text style={styles.loadErrorText}>دریافت اطلاعات داشبورد انجام نشد.</Text>
                <TouchableOpacity onPress={fetchUserData} style={styles.retryButton}>
                  <Text style={styles.retryButtonText}>تلاش مجدد</Text>
                </TouchableOpacity>
              </View>
            )}
            <Text style={styles.sectionTitle}>خدمات آفاریکس</Text>

            <View style={styles.servicesGrid}>
              <TouchableOpacity style={styles.serviceItem} onPress={() => openFinancialFeature('/add-remittance')}>
                <View style={[styles.iconWrapper, { backgroundColor: appColors.surfaceRaised }]}>
                  <MaterialCommunityIcons
                    name="send"
                    size={24}
                    color={appColors.primary}
                    style={{ transform: [{ rotate: '-45deg' }] }}
                  />
                </View>
                <Text style={styles.serviceText}>حواله</Text>
              </TouchableOpacity>

              <TouchableOpacity style={styles.serviceItem} onPress={() => openFinancialFeature('/topup')}>
                <View style={[styles.iconWrapper, { backgroundColor: appColors.surfaceRaised }]}>
                  <Ionicons name="phone-portrait" size={24} color={appColors.primary} />
                </View>
                <Text style={styles.serviceText}>خرید شارژ</Text>
              </TouchableOpacity>

              <TouchableOpacity style={styles.serviceItem} onPress={() => openFinancialFeature('/add-balance')}>
                <View style={[styles.iconWrapper, { backgroundColor: appColors.surfaceRaised }]}>
                  <Ionicons name="wallet" size={24} color={appColors.primary} />
                </View>
                <Text style={styles.serviceText}>کیف پول</Text>
              </TouchableOpacity>

              <TouchableOpacity style={styles.serviceItem} onPress={() => router.push('/profile' as any)}>
                <View style={[styles.iconWrapper, { backgroundColor: appColors.surfaceRaised }]}>
                  <Ionicons name="headset" size={24} color={appColors.primary} />
                </View>
                <Text style={styles.serviceText}>پشتیبانی</Text>
              </TouchableOpacity>

              <TouchableOpacity style={styles.serviceItem} onPress={() => openFinancialFeature('/withdraw')}>
                <View style={[styles.iconWrapper, { backgroundColor: appColors.surfaceRaised }]}>
                  <Ionicons name="arrow-down-circle" size={24} color={appColors.primary} />
                </View>
                <Text style={styles.serviceText}>برداشت وجه</Text>
              </TouchableOpacity>
            </View>
          </View>
        </ScrollView>

        <AppBottomNav />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: {
    flex: 1,
    backgroundColor: appColors.background,
  },
  outerBackground: {
    flex: 1,
    backgroundColor: appColors.background,
  },
  mainContent: {
    flex: 1,
  },
  scrollContent: {
    flexGrow: 1,
    paddingHorizontal: 18,
    paddingTop: 18,
    paddingBottom: 70,
  },
  topHeader: {
    flexDirection: 'row-reverse',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingBottom: 20,
  },
  userInfo: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  avatar: {
    width: 42,
    height: 42,
    backgroundColor: appColors.surface,
    borderRadius: 21,
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 10,
  },
  welcomeText: {
    justifyContent: 'center',
  },
  welcomeSub: {
    fontSize: 11,
    fontFamily: 'Vazirmatn',
    color: appColors.textMuted,
    textAlign: 'right',
  },
  welcomeName: {
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    color: appColors.text,
    textAlign: 'right',
    marginTop: 2,
  },
  notificationBtn: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: appColors.surface,
    justifyContent: 'center',
    alignItems: 'center',
    position: 'relative',
  },
  notifBadgeIndicator: {
    position: 'absolute',
    top: 0,
    right: 0,
    width: 12,
    height: 12,
    backgroundColor: '#ef4444',
    borderRadius: 6,
    borderWidth: 2,
    borderColor: '#ffffff',
  },
  walletCard: {
    marginBottom: 20,
    borderRadius: 20,
    borderWidth: 1,
    borderColor: appColors.accent,
    padding: 20,
    overflow: 'hidden',
    position: 'relative',
  },
  walletDecoration: {
    position: 'absolute',
    top: -20,
    right: -20,
    width: 100,
    height: 100,
    backgroundColor: 'rgba(255,255,255,0.1)',
    borderRadius: 50,
  },
  walletTitle: {
    fontSize: 12,
    fontFamily: 'Vazirmatn',
    color: appColors.textSecondary,
    opacity: 0.9,
    marginBottom: 8,
    textAlign: 'right',
  },
  walletBalanceContainer: {
    flexDirection: 'row-reverse',
    alignItems: 'baseline',
    marginBottom: 20,
  },
  walletAmount: {
    fontSize: 26,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    color: appColors.primary,
  },
  walletCurrency: {
    fontSize: 12,
    fontFamily: 'Vazirmatn',
    color: appColors.textSecondary,
    opacity: 0.9,
    marginRight: 5,
  },
  debtRow: {
    flexDirection: 'row-reverse',
    alignItems: 'center',
    justifyContent: 'space-between',
    backgroundColor: appColors.surface,
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 9,
    marginBottom: 16,
  },
  debtLabel: {
    fontSize: 12,
    fontFamily: 'Vazirmatn',
    color: appColors.textSecondary,
    opacity: 0.9,
  },
  debtValue: {
    fontSize: 13,
    fontFamily: 'Vazirmatn',
    color: appColors.text,
    fontWeight: 'bold',
    textAlign: 'left',
  },
  walletActions: {
    flexDirection: 'row-reverse',
    gap: 10,
    justifyContent: 'space-between',
  },
  btnDeposit: {
    flex: 1,
    backgroundColor: appColors.primary,
    paddingVertical: 10,
    borderRadius: 12,
    alignItems: 'center',
  },
  btnDepositText: {
    color: appColors.background,
    fontSize: 13,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
  },
  btnHistory: {
    flex: 1,
    backgroundColor: appColors.surfaceRaised,
    paddingVertical: 10,
    borderRadius: 12,
    alignItems: 'center',
  },
  btnHistoryText: {
    color: appColors.primary,
    fontSize: 13,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
  },
  servicesSection: {
    paddingTop: 4,
  },
  loadError: { alignItems: 'center', padding: 12, marginBottom: 16, borderRadius: 12, backgroundColor: '#fff4df' },
  loadErrorText: { fontFamily: 'Vazirmatn', color: '#7c551d', fontSize: 12, marginBottom: 8 },
  retryButton: { paddingHorizontal: 16, paddingVertical: 8, borderRadius: 9, backgroundColor: '#01F8FD' },
  retryButtonText: { fontFamily: 'Vazirmatn', color: '#fff', fontSize: 12, fontWeight: 'bold' },
  sectionTitle: {
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    color: appColors.text,
    marginBottom: 15,
    textAlign: 'right',
  },
  servicesGrid: {
    flexDirection: 'row-reverse',
    justifyContent: 'space-between',
    gap: 12,
  },
  serviceItem: {
    flex: 1,
    alignItems: 'center',
  },
  iconWrapper: {
    width: 52,
    height: 52,
    borderRadius: 16,
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 8,
  },
  serviceText: {
    fontSize: 11,
    fontFamily: 'Vazirmatn',
    color: appColors.text,
    fontWeight: '500',
    textAlign: 'center',
  },
});
