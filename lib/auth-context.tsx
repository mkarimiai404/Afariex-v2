import AsyncStorage from '@react-native-async-storage/async-storage';
import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { registerAuthExpiryHandler } from '@/lib/api';
import { clearRuntimeApiToken, setRuntimeApiToken } from '@/lib/auth-runtime';
import { clearBiometricSession } from '@/lib/biometric-auth';

const AUTH_STORAGE_KEY = 'afariex_auth_session';
const LEGACY_AUTH_KEYS = ['api_token', 'userToken', 'user_token', 'user_id', 'userId'] as const;

type AuthSession = {
  userId: string | null;
  userToken: string | null;
  userName: string | null;
  userMobile: string | null;
  userBalance: number | null;
  isSubuser: boolean;
  phoneVerified: boolean;
  kycStatus: string;
};

type AuthContextValue = AuthSession & {
  isAuthenticated: boolean;
  isInitialized: boolean;
  signIn: (session: Partial<AuthSession>) => Promise<void>;
  signOut: () => Promise<void>;
  setUserBalance: (balance: number | null) => void;
};

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

const emptySession = (): AuthSession => ({
  userId: null,
  userToken: null,
  userName: null,
  userMobile: null,
  userBalance: null,
  isSubuser: false,
  phoneVerified: false,
  kycStatus: 'pending',
});

const clearStoredAuth = async () => {
  const results = await Promise.allSettled([
    AsyncStorage.removeItem(AUTH_STORAGE_KEY),
    ...LEGACY_AUTH_KEYS.map((key) => AsyncStorage.removeItem(key)),
  ]);
  if (__DEV__) console.log('[Auth] logout storage cleanup', { success: results.every((result) => result.status === 'fulfilled') });
};

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [session, setSession] = useState<AuthSession>({
    userId: null,
    userToken: null,
    userName: null,
    userMobile: null,
    userBalance: null,
    isSubuser: false,
    phoneVerified: false,
    kycStatus: 'pending',
  });
  const [isInitialized, setIsInitialized] = useState(false);
  const pendingSignIns = useRef<{ token: string; resolve: () => void }[]>([]);

  useEffect(() => {
    let active = true;
    // Authentication is deliberately process-scoped. On every fresh runtime,
    // delete keys written by older builds without ever reading/restoring them.
    clearStoredAuth()
      .catch(() => undefined)
      .finally(() => {
        if (active) {
          setIsInitialized(true);
        }
      });
    return () => { active = false; };
  }, []);

  useEffect(() => registerAuthExpiryHandler(async () => {
    clearRuntimeApiToken();
    setSession(emptySession());
    await clearBiometricSession();
    await clearStoredAuth();
  }), []);

  useEffect(() => {
    const committedToken = session.userToken;
    if (!committedToken) return;
    const ready = pendingSignIns.current.filter((pending) => pending.token === committedToken);
    pendingSignIns.current = pendingSignIns.current.filter((pending) => pending.token !== committedToken);
    ready.forEach((pending) => pending.resolve());
  }, [session]);

  const signIn = useCallback((nextSession: Partial<AuthSession>) => {
    const token = setRuntimeApiToken(String(nextSession.userToken ?? ''));
    return new Promise<void>((resolve) => {
      pendingSignIns.current.push({ token, resolve });
      setSession((prev) => ({ ...prev, ...nextSession, userToken: token }));
    });
  }, []);

  const signOut = useCallback(async () => {
    clearRuntimeApiToken();
    setSession(emptySession());
    pendingSignIns.current.splice(0).forEach((pending) => pending.resolve());
    await clearStoredAuth();
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      ...session,
      isInitialized,
      isAuthenticated: Boolean(session.userToken?.trim()),
      signIn,
      signOut,
      setUserBalance: (balance) => {
        setSession((prev) => ({
          ...prev,
          userBalance: balance,
        }));
      },
    }),
    [session, isInitialized, signIn, signOut]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
}
