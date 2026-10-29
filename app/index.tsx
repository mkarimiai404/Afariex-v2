import React, { useEffect } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, Image } from 'react-native';
import { Feather } from '@expo/vector-icons'; // برای آیکون پشتیبانی
import { useRouter, Stack, useRootNavigationState } from 'expo-router'; // Stack اضافه شد
import { SafeAreaView } from 'react-native-safe-area-context';
import { useAuth } from '@/lib/auth-context';
import { appColors } from '@/lib/theme';

export default function WelcomeScreen() {
  const router = useRouter();
  const rootNavigationState = useRootNavigationState();
  const { isAuthenticated, isInitialized } = useAuth();

  useEffect(() => {
    if (__DEV__) console.log('[WELCOME_RUNTIME] index screen mounted');
  }, []);

  useEffect(() => {
    if (!rootNavigationState?.key) return;
    if (!isInitialized) return;
    if (isAuthenticated) {
      router.replace('/dashboard' as any);
    }
  }, [isAuthenticated, isInitialized, router, rootNavigationState?.key]);

  return (
    <SafeAreaView style={styles.container}>
      {/* این خط هدر پیش‌فرض را حذف می‌کند */}
      <Stack.Screen options={{ headerShown: false }} />

      <View style={styles.content}>
        {/* بخش لوگو */}
        <View style={styles.logoContainer}>
          <Image
            source={require('../assets/images/logo.png')}
            style={styles.logo}
            resizeMode="contain"
          />
        </View>

        {/* عناوین */}
        <Text style={styles.title}>به آفاریکس خوش آمدید</Text>
        <Text style={styles.subtitle}>سامانه جامع خدمات مالی و حواله</Text>

        {/* دکمه‌های اصلی */}
        <TouchableOpacity
          style={[styles.btn, styles.btnPrimary]}
          onPress={() => router.push('/login')} // آدرس صفحه لاگین که بعدا میسازیم
        >
          <Text style={styles.btnPrimaryText}>ورود به حساب کاربری</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.btn, styles.btnOutline]}
          onPress={() => router.push('/register')} // آدرس صفحه ثبت نام
        >
          <Text style={styles.btnOutlineText}>ثبت‌نام با شماره موبایل</Text>
        </TouchableOpacity>

        {/* فاصله پرکن برای هل دادن پشتیبانی به پایین */}
        <View style={{ flex: 1 }} />

        {/* لینک پشتیبانی */}
        <TouchableOpacity style={styles.supportLink}>
          <Text style={styles.supportLinkText}>تماس با پشتیبانی</Text>
          <Feather name="headphones" size={18} color="#666" />
        </TouchableOpacity>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: appColors.background,
    justifyContent: 'center',
    alignItems: 'center',
  },
  content: {
    width: '100%',
    maxWidth: 420,
    padding: 20,
    paddingBottom: 70,
    alignItems: 'center',
  },
  logoContainer: {
    marginBottom: 20,
  },
  logo: {
    width: 90,
    height: 90,
  },
  title: {
    fontSize: 22,
    fontFamily: 'Vazirmatn',
    color: appColors.text,
    fontWeight: 'bold',
    marginBottom: 10,
    textAlign: 'center',
  },
  subtitle: {
    color: appColors.textSecondary,
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    marginBottom: 40,
    textAlign: 'center',
  },
  btn: {
    width: '100%',
    paddingVertical: 14,
    marginBottom: 15,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  btnPrimary: {
    backgroundColor: appColors.primary,
  },
  btnPrimaryText: {
    color: appColors.background,
    fontSize: 15,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
  },
  btnOutline: {
    backgroundColor: 'transparent',
    borderWidth: 2,
    borderColor: appColors.primary,
  },
  btnOutlineText: {
    color: appColors.primary,
    fontSize: 15,
    fontFamily: 'Vazirmatn',
    fontWeight: 'bold',
  },
  supportLink: {
    flexDirection: 'row',
    justifyContent: 'center',
    alignItems: 'center',
    gap: 8,
  },
  supportLinkText: {
    color: appColors.textMuted,
    fontSize: 14,
    fontFamily: 'Vazirmatn',
    marginRight: 8,
  },
});
