import { Ionicons } from '@expo/vector-icons';
import { usePathname, useRouter } from 'expo-router';
import React from 'react';
import { StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { appColors } from '@/lib/theme';

type NavKey = 'remittance' | 'wallet' | 'topup' | 'support';

const items: Array<{
  key: NavKey;
  label: string;
  icon: keyof typeof Ionicons.glyphMap;
  route: string;
}> = [
  { key: 'remittance', label: 'حواله', icon: 'swap-horizontal', route: '/add-remittance' },
  { key: 'wallet', label: 'کیف پول', icon: 'wallet', route: '/add-balance' },
  { key: 'topup', label: 'خرید شارژ', icon: 'phone-portrait', route: '/topup' },
  { key: 'support', label: 'پشتیبانی', icon: 'headset', route: '/profile' },
];

export function AppBottomNav() {
  const router = useRouter();
  const pathname = usePathname();

  const activeKey: NavKey | null = pathname?.includes('add-remittance')
    ? 'remittance'
    : pathname?.includes('add-balance')
      ? 'wallet'
      : pathname?.includes('topup')
        ? 'topup'
        : pathname?.includes('profile')
          ? 'support'
        : null;

  return (
    <View style={styles.bar}>
      {items.map((item) => {
        const active = activeKey === item.key;
        return (
          <TouchableOpacity
            key={item.key}
            style={[styles.item, active && styles.itemActive]}
            onPress={() => router.push(item.route as any)}
            activeOpacity={0.8}
          >
            <Ionicons name={item.icon} size={22} color={active ? appColors.primary : appColors.textMuted} />
            <Text style={[styles.label, active && styles.labelActive]}>{item.label}</Text>
          </TouchableOpacity>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  bar: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    width: '100%',
    height: 65,
    backgroundColor: appColors.background,
    borderTopWidth: 1,
    borderTopColor: appColors.surfaceRaised,
    flexDirection: 'row-reverse',
    justifyContent: 'space-around',
    alignItems: 'center',
    zIndex: 9999,
  },
  item: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 4,
    height: '100%',
  },
  itemActive: {
    backgroundColor: appColors.surface,
  },
  label: {
    fontSize: 10,
    fontFamily: 'Vazirmatn',
    color: appColors.textMuted,
  },
  labelActive: {
    color: appColors.primary,
    fontWeight: 'bold',
  },
});
