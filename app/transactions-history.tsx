import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { Stack, useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAuth } from '@/lib/auth-context';
import { apiUrl } from '@/lib/api';
import { AppBottomNav } from '@/components/app-bottom-nav';
import { appColors } from '@/lib/theme';

type TransactionType = 'deposit' | 'withdraw' | 'remittance' | string;
type FilterType = 'all' | 'deposit' | 'withdraw' | 'remittance';

type TransactionItem = {
  id: string;
  raw_id: string;
  source: string;
  amount: string;
  tracking_code: string;
  type: TransactionType;
  status: string;
  receipt_image: string;
  created_at: string;
  description: string;
  fee_amount: string;
  total_amount: string;
  receipt_full_url: string;
};

const toSafeString = (value: unknown) => {
  if (value === null || value === undefined) return '';
  return String(value);
};

const normalizeTransaction = (item: any): TransactionItem => ({
  id: toSafeString(item?.id),
  raw_id: toSafeString(item?.raw_id),
  source: toSafeString(item?.source),
  amount: toSafeString(item?.amount),
  tracking_code: toSafeString(item?.tracking_code),
  type: (() => {
    const type = toSafeString(item?.type).toLowerCase();
    if (type === 'withdraw' || type === 'withdrawal') return 'withdraw';
    if (type === 'deposit') return 'deposit';
    if (type === 'remittance' || type === 'transfer') return 'remittance';
    if (type === 'debt_payment') return 'debt_payment';
    return type;
  })(),
  status: toSafeString(item?.status),
  receipt_image: toSafeString(item?.receipt_image),
  created_at: toSafeString(item?.created_at),
  description: toSafeString(item?.description),
  fee_amount: toSafeString(item?.fee_amount),
  total_amount: toSafeString(item?.total_amount),
  receipt_full_url: toSafeString(item?.receipt_full_url),
});

const toPersianNum = (num: string | number) => {
  if (num === null || num === undefined) return '';
  const farsiDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
  return num.toString().replace(/\d/g, (x) => farsiDigits[Number(x)]);
};

const formatPrice = (value: number) => {
  const safeValue = Number.isFinite(value) ? Math.abs(Math.round(value)) : 0;
  const formatted = safeValue.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return toPersianNum(formatted);
};

const getTransactionMeta = (type: TransactionType) => {
  if (type === 'deposit') {
    return {
      label: 'واریز وجه',
      icon: 'arrow-down-circle-outline' as const,
      iconColor: '#0ed874',
      iconBackground: appColors.surfaceRaised,
      amountSign: '+',
      amountColorStyle: styles.textAmountGreen,
    };
  }

  if (type === 'topup') {
    return {
      label: 'خرید شارژ',
      icon: 'phone-portrait-outline' as const,
      iconColor: appColors.primary,
      iconBackground: appColors.surfaceRaised,
      amountSign: '-',
      amountColorStyle: styles.textAmountTeal,
    };
  }

  if (type === 'remittance') {
    return {
      label: 'ثبت حواله',
      icon: 'swap-horizontal-outline' as const,
      iconColor: appColors.primary,
      iconBackground: appColors.surfaceRaised,
      amountSign: '-',
      amountColorStyle: styles.textAmountPurple,
    };
  }

  if (type === 'debt_payment') {
    return {
      label: 'تسویه بدهی',
      icon: 'checkmark-done-circle-outline' as const,
      iconColor: appColors.primary,
      iconBackground: appColors.surfaceRaised,
      amountSign: '',
      amountColorStyle: styles.textAmountTeal,
    };
  }

  return {
    label: 'برداشت وجه',
    icon: 'arrow-up-circle-outline' as const,
    iconColor: '#ef4444',
    iconBackground: appColors.surfaceRaised,
    amountSign: '-',
    amountColorStyle: styles.textAmountRed,
  };
};

const getStatusMeta = (status: string) => {
  const normalized = status?.toLowerCase?.() || '';

  if (normalized === 'approved' || normalized === 'paid' || normalized === 'success' || normalized === 'completed') {
    return {
      text: 'موفق',
      badgeStyle: styles.bgApproved,
      textStyle: styles.textApproved,
    };
  }

  if (normalized === 'rejected' || normalized === 'failed' || normalized === 'cancelled') {
    return {
      text: 'رد شده',
      badgeStyle: styles.bgRejected,
      textStyle: styles.textRejected,
    };
  }

  return {
    text: 'در انتظار',
    badgeStyle: styles.bgPending,
    textStyle: styles.textPending,
  };
};

export default function TransactionsHistoryScreen() {
  const router = useRouter();
  const { userId, userToken, isAuthenticated, isInitialized } = useAuth();

  const [filter, setFilter] = useState<FilterType>('all');
  const [transactions, setTransactions] = useState<TransactionItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [errorText, setErrorText] = useState('');
  const requestInFlight = useRef(false);

  useEffect(() => {
    if (__DEV__) console.log('[AFARIEX-DEBUG][TX_HISTORY] screen mounted');
  }, []);

  const fetchTransactions = useCallback(async () => {
    if (requestInFlight.current || !isInitialized) return;
    requestInFlight.current = true;
    setLoading(true);
    try {
      setErrorText('');

      if (!isAuthenticated) {
        setTransactions([]);
        setErrorText('شما وارد حساب کاربری نشده‌اید.');
        return;
      }

      if (!userToken) {
        setTransactions([]);
        setErrorText('توکن کاربر در اپ پیدا نشد. لطفاً یک بار خارج شوید و دوباره وارد شوید.');
        return;
      }

      const endpoint = apiUrl('transactions-history.php');
      const tokenValue = String(userToken);
      const body = new URLSearchParams();
      body.append('api_token', tokenValue);
      body.append('token', tokenValue);
      const requestStartedAt = Date.now();

      if (__DEV__) {
        console.log('[AFARIEX-DEBUG][TX_HISTORY] request start', {
          endpoint,
          method: 'POST',
          bodyFields: ['api_token', 'token'],
          hasBearerToken: Boolean(tokenValue),
          userIdAvailable: Boolean(userId),
        });
      }

      let response: Response;
      try {
        response = await fetch(endpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            Accept: 'application/json',
            Authorization: `Bearer ${tokenValue}`,
          },
          body: body.toString(),
        });
      } catch (networkError) {
        if (__DEV__) {
          console.log('[AFARIEX-DEBUG][TX_HISTORY] network failure', {
            endpoint,
            elapsedMs: Date.now() - requestStartedAt,
            error: networkError instanceof Error ? networkError.message : String(networkError),
          });
        }
        setTransactions([]);
        setErrorText('ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی و دوباره تلاش کنید.');
        return;
      }

      const responseText = await response.text();
      if (__DEV__) {
        console.log('[AFARIEX-DEBUG][TX_HISTORY] response received', {
          endpoint,
          status: response.status,
          ok: response.ok,
          contentType: response.headers.get('content-type') ?? 'unknown',
          elapsedMs: Date.now() - requestStartedAt,
          bodyLength: responseText.length,
        });
      }

      let data: any = null;
      try {
        data = responseText ? JSON.parse(responseText) : null;
      } catch (parseError) {
        if (__DEV__) {
          console.log('[AFARIEX-DEBUG][TX_HISTORY] JSON parse failed', {
            endpoint,
            status: response.status,
            error: parseError instanceof Error ? parseError.message : String(parseError),
          });
        }
        setTransactions([]);
        setErrorText(`پاسخ سرور برای تاریخچه تراکنش‌ها معتبر نیست. (HTTP ${response.status})`);
        return;
      }

      if (response.ok && data?.status === 'success' && Array.isArray(data.data)) {
        const rows = data.data;
        const list = rows.flatMap((item: any, index: number) => {
          try {
            const normalized = normalizeTransaction(item);
            if (!normalized.id && !normalized.raw_id) {
              if (__DEV__) console.log('[AFARIEX-DEBUG][TX_HISTORY] malformed row skipped', { index });
              return [];
            }
            return [normalized];
          } catch (rowError) {
            if (__DEV__) console.log('[AFARIEX-DEBUG][TX_HISTORY] row normalization failed', { index, error: rowError });
            return [];
          }
        });
        if (__DEV__) console.log('[AFARIEX-DEBUG][TX_HISTORY] normalized rows count =', list.length);
        setTransactions(list);
        return;
      }

      const serverMessage = toSafeString(data?.message);
      const serverCode = toSafeString(data?.code);
      if (__DEV__) {
        console.log('[AFARIEX-DEBUG][TX_HISTORY] server error response', {
          endpoint,
          status: response.status,
          code: serverCode || 'unknown',
          message: serverMessage,
        });
      }
      setTransactions([]);
      setErrorText(
        serverMessage
          ? `${serverMessage} (HTTP ${response.status})`
          : serverCode
            ? `دریافت تاریخچه تراکنشها ممکن نشد. (${serverCode}، HTTP ${response.status})`
            : 'دریافت تاریخچه تراکنشها ممکن نشد.'
      );
    } catch (error) {
      if (__DEV__) {
        console.log('[AFARIEX-DEBUG][TX_HISTORY] unexpected error', {
          error: error instanceof Error ? error.message : String(error),
        });
      }
      setTransactions([]);
      setErrorText('دریافت تاریخچه تراکنشها ممکن نشد.');
    } finally {
      setLoading(false);
      setRefreshing(false);
      requestInFlight.current = false;
    }
  }, [isAuthenticated, isInitialized, userId, userToken]);

  useEffect(() => {
    if (isInitialized) void fetchTransactions();
  }, [fetchTransactions, isInitialized]);

  const onRefresh = () => {
    if (requestInFlight.current) return;
    setRefreshing(true);
    void fetchTransactions();
  };

  const filteredTransactions = transactions.filter((tx) => {
    if (filter === 'all') return true;
    return tx.type === filter;
  });

  const openWithdrawalReceipt = (tx: TransactionItem) => {
    const withdrawalId = tx.raw_id || tx.id;
    if (!withdrawalId) return;
    if (__DEV__) console.log('[RECEIPT_HISTORY] opening withdrawal id =', withdrawalId);
    router.push({ pathname: '/withdrawal-receipt', params: { id: withdrawalId } } as any);
  };

  const tabs: { key: FilterType; label: string }[] = [
    { key: 'all', label: 'همه' },
    { key: 'deposit', label: 'واریزی‌ها' },
    { key: 'withdraw', label: 'پرداختی‌ها' },
    { key: 'remittance', label: 'حواله‌ها' },
  ];

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />

      <View style={styles.outerBackground}>
        <View style={styles.boxedContainer}>
          <View style={styles.header}>
            <Text style={styles.headerTitle}>تاریخچه تراکنش‌ها</Text>

            <TouchableOpacity style={styles.backBtn} onPress={() => router.back()}>
              <Ionicons name="chevron-forward" size={24} color="#4b5563" />
            </TouchableOpacity>
          </View>

          <View style={styles.tabsWrapper}>
            <View style={styles.tabsContainer}>
              {tabs.map((tab) => (
                <TouchableOpacity
                  key={tab.key}
                  style={[styles.tabBtn, filter === tab.key && styles.tabBtnActive]}
                  onPress={() => setFilter(tab.key)}
                >
                  <Text style={[styles.tabText, filter === tab.key && styles.tabTextActive]}>
                    {tab.label}
                  </Text>
                </TouchableOpacity>
              ))}
            </View>
          </View>

          <ScrollView
            contentContainerStyle={styles.listContainer}
            showsVerticalScrollIndicator={false}
            refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
          >
            {loading ? (
              <View style={styles.emptyStateContainer}>
                <ActivityIndicator size="large" color="#0ed874" />
                <Text style={styles.loadingText}>در حال دریافت تراکنش‌ها...</Text>
              </View>
            ) : errorText ? (
              <View style={styles.emptyStateContainer}>
                <View style={styles.emptyIconCircle}>
                  <Ionicons name="warning-outline" size={40} color="#ef4444" />
                </View>

                <Text style={styles.emptyStateTitle}>خطا در دریافت اطلاعات</Text>
                <Text style={styles.emptyStateSub}>{errorText}</Text>

                <TouchableOpacity style={styles.retryButton} onPress={fetchTransactions}>
                  <Text style={styles.retryButtonText}>تلاش دوباره</Text>
                </TouchableOpacity>
              </View>
            ) : filteredTransactions.length === 0 ? (
              <View style={styles.emptyStateContainer}>
                <View style={styles.emptyIconCircle}>
                  <Ionicons name="receipt-outline" size={40} color="#9ca3af" />
                </View>

                <Text style={styles.emptyStateTitle}>تراکنشی یافت نشد</Text>
                <Text style={styles.emptyStateSub}>
                  در این دسته‌بندی هنوز هیچ تراکنشی ثبت نشده است.
                </Text>
              </View>
            ) : (
              filteredTransactions.map((tx, index) => {
                const transactionMeta = getTransactionMeta(tx.type);
                const statusMeta = getStatusMeta(tx.status);

                const parsedAmount = Number(tx.amount || 0);
                const safeAmount = Number.isFinite(parsedAmount) ? parsedAmount : 0;
                const feeAmount = Number(tx.fee_amount || 0);
                const totalAmount = Number(tx.total_amount || tx.amount || 0);
                const hasFee = Number.isFinite(feeAmount) && feeAmount > 0;

                const titleText = tx.description || transactionMeta.label;

                return (
                  <View key={tx.id || `${tx.source}-${tx.raw_id}-${index}`} style={styles.txCard}>
                    <View style={styles.txMainRow}>
                      <View style={[styles.txIconCircle, { backgroundColor: transactionMeta.iconBackground }]}>
                        <Ionicons
                          name={transactionMeta.icon}
                          size={22}
                          color={transactionMeta.iconColor}
                        />
                      </View>

                      <View style={styles.txLeft}>
                        <Text style={[styles.txAmount, transactionMeta.amountColorStyle]}>
                          {transactionMeta.amountSign} {formatPrice(safeAmount)}{' '}
                          <Text style={styles.currencyText}>تومان</Text>
                        </Text>

                        <View style={[styles.badge, statusMeta.badgeStyle]}>
                          <Text style={[styles.badgeText, statusMeta.textStyle]}>
                            {statusMeta.text}
                          </Text>
                        </View>
                      </View>

                      <View style={styles.txRight}>
                        <Text style={styles.txTitle}>{titleText}</Text>

                        {!!tx.created_at && (
                          <Text style={styles.txDateCode}>
                            {toPersianNum(tx.created_at)}
                          </Text>
                        )}

                        {!!tx.tracking_code && (
                          <Text style={styles.txDateCode}>
                            کد رهگیری: {toPersianNum(tx.tracking_code)}
                          </Text>
                        )}

                        {!!tx.receipt_full_url && (
                          <Text style={styles.txDateCode}>رسید ثبت شده</Text>
                        )}

                        {hasFee && (
                          <Text style={styles.txDateCode}>
                            کارمزد: {formatPrice(feeAmount)} تومان · کسر شده: {formatPrice(totalAmount)} تومان
                          </Text>
                        )}
                      </View>
                    </View>

                    {tx.type === 'withdraw' && (
                      <TouchableOpacity style={styles.receiptButton} onPress={() => openWithdrawalReceipt(tx)} activeOpacity={0.85}>
                        <Ionicons name="receipt-outline" size={18} color="#0b8f72" />
                        <Text style={styles.receiptButtonText}>مشاهده رسید</Text>
                      </TouchableOpacity>
                    )}
                  </View>
                );
              })
            )}
          </ScrollView>
        </View>
        <AppBottomNav />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: {
    flex: 1,
    backgroundColor: appColors.surface,
  },
  outerBackground: {
    flex: 1,
    backgroundColor: appColors.background,
    paddingHorizontal: 18,
    paddingTop: 18,
    paddingBottom: 70,
  },
  boxedContainer: {
    flex: 1,
    backgroundColor: appColors.background,
    overflow: 'hidden',
    shadowOpacity: 0,
    shadowRadius: 0,
    elevation: 0,
  },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    paddingVertical: 20,
    borderBottomWidth: 1,
    borderBottomColor: appColors.surfaceRaised,
    position: 'relative',
  },
  headerTitle: {
    fontSize: 16,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    color: appColors.text,
  },
  backBtn: {
    position: 'absolute',
    right: 20,
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: appColors.surfaceRaised,
    alignItems: 'center',
    justifyContent: 'center',
  },
  tabsWrapper: {
    padding: 15,
    backgroundColor: appColors.background,
    borderBottomWidth: 1,
    borderBottomColor: appColors.surfaceRaised,
  },
  tabsContainer: {
    flexDirection: 'row-reverse',
    backgroundColor: appColors.surfaceRaised,
    borderRadius: 12,
    padding: 4,
  },
  tabBtn: {
    flex: 1,
    paddingVertical: 10,
    borderRadius: 8,
    alignItems: 'center',
  },
  tabBtnActive: {
    backgroundColor: appColors.background,
    shadowOpacity: 0,
    shadowRadius: 0,
    elevation: 0,
  },
  tabText: {
    color: appColors.textMuted,
    fontSize: 12,
    fontFamily: 'Vazirmatn',
    fontWeight: '500',
  },
  tabTextActive: {
    color: appColors.primary,
    fontWeight: 'bold',
  },
  listContainer: {
    padding: 0,
    flexGrow: 1,
    paddingBottom: 70,
  },
  txCard: {
    backgroundColor: appColors.surface,
    padding: 16,
    marginBottom: 12,
    borderWidth: 0,
    shadowOpacity: 0,
    shadowRadius: 0,
    elevation: 0,
  },
  txMainRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  txIconCircle: {
    width: 42,
    height: 42,
    borderRadius: 21,
    alignItems: 'center',
    justifyContent: 'center',
    marginLeft: 12,
  },
  txRight: {
    flex: 1,
    alignItems: 'flex-end',
    justifyContent: 'center',
  },
  txTitle: {
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    color: appColors.text,
    textAlign: 'right',
    marginBottom: 6,
  },
  txDateCode: {
    fontSize: 11,
    fontFamily: 'Vazirmatn',
    color: appColors.textMuted,
    textAlign: 'right',
    marginTop: 2,
  },
  txLeft: {
    alignItems: 'flex-start',
    justifyContent: 'center',
    marginLeft: 15,
  },
  receiptButton: {
    alignSelf: 'flex-end',
    marginTop: 12,
    minHeight: 38,
    paddingHorizontal: 14,
    borderRadius: 10,
    borderWidth: 1,
    borderColor: '#99f6e4',
    backgroundColor: appColors.surfaceRaised,
    flexDirection: 'row-reverse',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 7,
  },
  receiptButtonText: {
    fontFamily: 'VazirmatnBold',
    fontSize: 12,
    color: appColors.primary,
    textAlign: 'center',
  },
  txAmount: {
    fontSize: 15,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    textAlign: 'left',
    marginBottom: 6,
  },
  currencyText: {
    fontSize: 10,
    fontFamily: 'Vazirmatn',
    fontWeight: 'normal',
  },
  textAmountGreen: {
    color: appColors.primary,
  },
  textAmountRed: {
    color: '#ef4444',
  },
  textAmountPurple: {
    color: '#8b5cf6',
  },
  textAmountTeal: {
    color: appColors.primary,
  },
  badge: {
    paddingVertical: 4,
    paddingHorizontal: 8,
    borderRadius: 6,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: {
    fontSize: 10,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
  },
  bgPending: {
    backgroundColor: '#fef3c7',
  },
  textPending: {
    color: '#d97706',
  },
  bgApproved: {
    backgroundColor: '#e6f6f2',
  },
  textApproved: {
    color: appColors.primary,
  },
  bgRejected: {
    backgroundColor: '#fee2e2',
  },
  textRejected: {
    color: '#dc2626',
  },
  emptyStateContainer: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 60,
    paddingHorizontal: 20,
  },
  emptyIconCircle: {
    width: 80,
    height: 80,
    borderRadius: 40,
    backgroundColor: appColors.surfaceRaised,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 16,
  },
  emptyStateTitle: {
    fontSize: 16,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    color: '#374151',
    marginBottom: 8,
    textAlign: 'center',
  },
  emptyStateSub: {
    fontSize: 13,
    fontFamily: 'Vazirmatn',
    color: '#9ca3af',
    textAlign: 'center',
    lineHeight: 22,
  },
  loadingText: {
    marginTop: 12,
    fontSize: 13,
    fontFamily: 'Vazirmatn',
    color: appColors.textMuted,
  },
  retryButton: {
    marginTop: 18,
    backgroundColor: appColors.primary,
    paddingHorizontal: 22,
    paddingVertical: 10,
    borderRadius: 10,
  },
  retryButtonText: {
    color: '#ffffff',
    fontSize: 13,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
  },
});
