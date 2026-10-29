import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import { Stack, useRouter, useRootNavigationState } from 'expo-router';
import React, { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { fetchJson } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { showError } from '@/lib/toast';
import { AppBottomNav } from '@/components/app-bottom-nav';

const toPersianNum = (num: string | number) => {
  if (!num) return '';
  const farsiDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
  return num.toString().replace(/\d/g, (x) => farsiDigits[parseInt(x)]);
};

export default function NotificationsScreen() {
  const router = useRouter();
  const rootNavigationState = useRootNavigationState();
  const { userToken, userId, isAuthenticated } = useAuth();
  const [notifications, setNotifications] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const fetchNotifications = async () => {
  setLoading(true);
  try {
    if (!isAuthenticated) {
      router.replace('/login' as any);
      return;
    }
    if (!userId && !userToken) {
      showError('خطا', 'اطلاعات ورود نامعتبر است. لطفاً دوباره وارد شوید.');
      return;
    }

    const payload = new URLSearchParams();
    if (userToken) {
      payload.append('api_token', userToken);
      payload.append('token', userToken);
      payload.append('user_token', userToken);
    }
    if (userId) {
      payload.append('user_id', userId);
      payload.append('id', userId);
    }

    if (__DEV__) console.log('[Notifications] requesting get_notifications.php');

    const data = await fetchJson<any>('get_notifications.php', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded',
      },
      body: payload.toString(),
    });

    if (__DEV__) console.log('[Notifications] parsed response:', data);

    // ********** اصلاح اصلی دقیقاً اینجاست **********
    if (data?.success === true && Array.isArray(data.rows)) {
      setNotifications(data.rows);
    } else {
      showError('خطا', data.message || 'خطا در دریافت اطلاعات');
      setNotifications([]);
    }

  } catch (error) {
    if (__DEV__) console.warn('[Notifications] request failed:', error);
    if (error instanceof Error) {
      if (__DEV__) {
        console.log('[Notifications] error message:', error.message);
        console.log('[Notifications] error cause:', error.cause);
      }
    }
    showError('خطای ارتباط', 'خطا در ارتباط با سرور');
    setNotifications([]);
  } finally {
    setLoading(false);
  }
};


  useEffect(() => {
    if (!rootNavigationState?.key) return;
    fetchNotifications();
  }, [isAuthenticated, userId, userToken, rootNavigationState?.key]);

  const renderNotification = ({ item }: { item: any }) => (
    <View style={[styles.notificationCard, item.is_read == 0 && styles.unreadCard]}>
      <View style={styles.iconContainer}>
        <Ionicons name="notifications" size={24} color={item.is_read == 0 ? appColors.primary : appColors.textMuted} />
      </View>
      <View style={styles.textContainer}>
        <Text style={styles.title}>{item.title}</Text>
        <Text style={styles.message}>{item.message}</Text>
        <Text style={styles.date}>{toPersianNum(item.created_at)}</Text>
      </View>
    </View>
  );

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />

      <View style={styles.outerBackground}>
        <View style={styles.boxedContainer}>

          <View style={styles.customHeader}>
            <TouchableOpacity onPress={() => router.back()} style={styles.backBtn}>
              <Ionicons name="arrow-forward" size={24} color={appColors.primary} />
            </TouchableOpacity>
            <Text style={styles.headerTitle}>اعلانات</Text>
            <View style={{ width: 40 }} />
          </View>

          {loading ? (
            <View style={styles.centerContainer}>
              <ActivityIndicator size="large" color={appColors.primary} />
            </View>
          ) : notifications.length === 0 ? (
            <View style={styles.centerContainer}>
              <Ionicons name="notifications-off-outline" size={60} color={appColors.textMuted} />
              <Text style={styles.emptyText}>هیچ اعلانی یافت نشد.</Text>
            </View>
          ) : (
            <FlatList
              data={notifications}
              keyExtractor={(item) => item.id.toString()}
              renderItem={renderNotification}
              contentContainerStyle={styles.listContainer}
              showsVerticalScrollIndicator={false}
            />
          )}

        </View>
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
  customHeader: {
    flexDirection: 'row-reverse',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 20,
    paddingVertical: 20,
    borderBottomWidth: 1,
    borderBottomColor: appColors.surfaceRaised,
  },
  backBtn: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: appColors.surfaceRaised,
    justifyContent: 'center',
    alignItems: 'center',
  },
  headerTitle: {
    fontSize: 16,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    color: appColors.text,
  },
  centerContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 20,
  },
  listContainer: {
    padding: 15,
    paddingBottom: 70,
  },
  notificationCard: {
    flexDirection: 'row-reverse',
    backgroundColor: appColors.background,
    padding: 15,
    marginBottom: 10,
    borderWidth: 0,
  },
  unreadCard: {
    backgroundColor: appColors.surfaceRaised,
  },
  iconContainer: {
    marginLeft: 15,
    justifyContent: 'center',
    alignItems: 'center',
  },
  textContainer: {
    flex: 1,
  },
  title: {
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
    color: appColors.text,
    marginBottom: 5,
    textAlign: 'right',
  },
  message: {
    fontSize: 12,
    fontFamily: 'Vazirmatn',
    color: appColors.textSecondary,
    marginBottom: 8,
    textAlign: 'right',
    lineHeight: 20,
  },
  date: {
    fontSize: 10,
    fontFamily: 'Vazirmatn',
    color: appColors.textMuted,
    textAlign: 'left',
  },
  emptyText: {
    color: appColors.textMuted,
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    marginTop: 15,
  },
});
