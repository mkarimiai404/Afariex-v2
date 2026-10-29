import { Slot, usePathname, useRootNavigationState, useRouter } from 'expo-router';
import { useFonts } from 'expo-font';
import * as SplashScreen from 'expo-splash-screen';
import React, { useEffect, useRef } from 'react';
import { StyleSheet, View } from 'react-native';
import Toast from 'react-native-toast-message';

import { AuthProvider, useAuth } from '@/lib/auth-context';
import { getRuntimeApiToken } from '@/lib/auth-runtime';
import { applyGlobalFont, PROJECT_FONT_FAMILIES } from '@/lib/apply-global-font';
import { toastConfig } from '@/lib/toast';
import { appColors } from '@/lib/theme';

SplashScreen.preventAutoHideAsync().catch(() => undefined);

const projectFonts = {
  [PROJECT_FONT_FAMILIES.regular]: require('../assets/fonts/Vazirmatn-Regular.ttf'),
  [PROJECT_FONT_FAMILIES.medium]: require('../assets/fonts/Vazirmatn-Medium.ttf'),
  [PROJECT_FONT_FAMILIES.semiBold]: require('../assets/fonts/Vazirmatn-SemiBold.ttf'),
  [PROJECT_FONT_FAMILIES.bold]: require('../assets/fonts/Vazirmatn-Bold.ttf'),
  [PROJECT_FONT_FAMILIES.extraBold]: require('../assets/fonts/Vazirmatn-ExtraBold.ttf'),
  [PROJECT_FONT_FAMILIES.black]: require('../assets/fonts/Vazirmatn-Black.ttf'),
  [PROJECT_FONT_FAMILIES.light]: require('../assets/fonts/Vazirmatn-Light.ttf'),
  [PROJECT_FONT_FAMILIES.extraLight]: require('../assets/fonts/Vazirmatn-ExtraLight.ttf'),
  [PROJECT_FONT_FAMILIES.thin]: require('../assets/fonts/Vazirmatn-Thin.ttf'),
};

const PUBLIC_AUTH_PATHS = new Set(['/', '/login', '/register']);

function AuthRouteBoundary({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const navigationState = useRootNavigationState();
  const { isAuthenticated, isInitialized } = useAuth();
  const initialEntryPending = useRef(true);
  const navigationReady = Boolean(navigationState?.key);
  const publicRoute = PUBLIC_AUTH_PATHS.has(pathname);
  const authStatus = !isInitialized || (!isAuthenticated && Boolean(getRuntimeApiToken()))
    ? 'initializing'
    : isAuthenticated
      ? 'authenticated'
      : 'unauthenticated';

  // کاربر لاگین‌نشده فقط زمانی ریدایرکت شود که در یک صفحه خصوصی (غیر عمومی) باشد
  const redirectingToLogin = navigationReady && authStatus === 'unauthenticated' && !publicRoute;

  useEffect(() => {
    if (!navigationReady || authStatus === 'initializing') return;
    initialEntryPending.current = false;
    if (__DEV__) console.log('[AFARIEX-DEBUG-STARTUP] route decision', { pathname, authStatus, publicRoute, redirectingToLogin });
    if (redirectingToLogin) router.replace('/login' as any);
  }, [authStatus, navigationReady, pathname, publicRoute, redirectingToLogin, router]);

  useEffect(() => {
    if (navigationReady && authStatus !== 'initializing' && !redirectingToLogin) {
      SplashScreen.hideAsync().catch(() => undefined);
    }
  }, [authStatus, navigationReady, redirectingToLogin]);

  return (
    <View style={styles.routeContainer}>
      {children}
      {(authStatus === 'initializing' || redirectingToLogin) && <View style={styles.routeShield} pointerEvents="auto" />}
    </View>
  );
}

export default function RootLayout() {
  const [fontsLoaded, fontError] = useFonts(projectFonts);

  if (!fontsLoaded && !fontError) return null;
  if (fontsLoaded) applyGlobalFont();

  return (
    <AuthProvider>
      <AuthRouteBoundary>
        <Slot />
      </AuthRouteBoundary>
      <Toast config={toastConfig} />
    </AuthProvider>
  );
}

const styles = StyleSheet.create({
  routeContainer: { flex: 1 },
  routeShield: {
    ...StyleSheet.absoluteFillObject,
    backgroundColor: appColors.background,
  },
});
