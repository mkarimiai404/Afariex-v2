import { Ionicons } from '@expo/vector-icons';
import React, { useMemo } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { appColors } from '@/lib/theme';
import { formatTopupAmount, formatTopupMobile, topupStatusLabel, TopupReceiptModel } from '@/lib/topup-receipt';

export function TopupReceiptView({ receipt }: { receipt: TopupReceiptModel }) {
  const success = receipt.status === 'success';
  const pending = receipt.status === 'pending';
  const statusColor = success ? '#22c55e' : pending ? '#f59e0b' : '#ef4444';
  const statusIcon = success ? 'checkmark-circle' : pending ? 'time' : 'close-circle';
  const statusLabel = useMemo(() => topupStatusLabel(receipt.status), [receipt.status]);
  return <View style={styles.card}>
    <View style={styles.topLine} />
    <Image source={require('../assets/images/logo.png')} style={styles.logo} resizeMode="contain" />
    <Text style={styles.brand}>آفاریکس</Text>
    <Text style={styles.subtitle}>رسید خرید شارژ موبایل</Text>
    <View style={[styles.statusBox, { borderColor: statusColor }]}>
      <Ionicons name={statusIcon as keyof typeof Ionicons.glyphMap} size={42} color={statusColor} />
      <Text style={[styles.status, { color: statusColor }]}>{statusLabel}</Text>
      {!!receipt.message && <Text style={styles.message}>{receipt.message}</Text>}
    </View>
    <View style={styles.rows}>
      <Row label="شماره موبایل" value={formatTopupMobile(receipt.mobile)} ltr />
      <Row label="اپراتور" value={receipt.operatorLabel} />
      {!!receipt.chargeType && <Row label="نوع شارژ" value={receipt.chargeType === 'normal' ? 'عادی' : 'شگفت‌انگیز / مستقیم'} />}
      <Row label="مبلغ" value={formatTopupAmount(receipt.amount)} />
      {(receipt.commissionAmount ?? 0) > 0 && <Row label="کارمزد" value={formatTopupAmount(receipt.commissionAmount ?? 0)} />}
      {(receipt.commissionAmount ?? 0) > 0 && <Row label="مبلغ نهایی کسرشده" value={formatTopupAmount(receipt.totalAmount ?? receipt.amount)} />}
      {!!receipt.refCode && <Row label="کد مرجع" value={receipt.refCode} ltr />}
      {!!receipt.trackingCode && <Row label="کد پیگیری" value={receipt.trackingCode} ltr />}
      {!!receipt.orderId && <Row label="شماره سفارش" value={receipt.orderId} ltr last={!receipt.refCode && !receipt.trackingCode} />}
    </View>
  </View>;
}

function Row({ label, value, ltr = false, last = false }: { label: string; value: string; ltr?: boolean; last?: boolean }) {
  return <View style={[styles.row, last && styles.lastRow]}><Text style={styles.label}>{label}</Text><Text style={[styles.value, ltr && styles.ltr]}>{value}</Text></View>;
}

const styles = StyleSheet.create({
  card: { backgroundColor: appColors.surface, borderRadius: 20, padding: 18, borderWidth: 1, borderColor: appColors.surfaceRaised, overflow: 'hidden' },
  topLine: { height: 5, borderRadius: 5, backgroundColor: appColors.primary, marginHorizontal: -18, marginTop: -18, marginBottom: 18 },
  logo: { width: 64, height: 64, alignSelf: 'center' },
  brand: { fontFamily: 'VazirmatnBold', fontSize: 22, color: appColors.primary, textAlign: 'center', marginTop: 5 },
  subtitle: { fontFamily: 'Vazirmatn', fontSize: 11, color: appColors.textSecondary, textAlign: 'center', marginTop: 3 },
  statusBox: { borderWidth: 1, borderRadius: 15, padding: 15, marginTop: 18, alignItems: 'center' },
  status: { fontFamily: 'VazirmatnBold', fontSize: 17, marginTop: 7 },
  message: { fontFamily: 'Vazirmatn', fontSize: 12, color: appColors.textSecondary, textAlign: 'center', marginTop: 7, writingDirection: 'rtl' },
  rows: { borderColor: appColors.surfaceRaised, borderWidth: 1, borderRadius: 13, marginTop: 14, overflow: 'hidden' },
  row: { minHeight: 52, flexDirection: 'row-reverse', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: appColors.surfaceRaised, paddingHorizontal: 13, gap: 12 },
  lastRow: { borderBottomWidth: 0 },
  label: { width: 95, fontFamily: 'Vazirmatn', fontSize: 11, color: appColors.textSecondary, textAlign: 'right' },
  value: { flex: 1, fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.text, textAlign: 'right', writingDirection: 'rtl' },
  ltr: { direction: 'ltr', writingDirection: 'ltr', textAlign: 'right' },
});
