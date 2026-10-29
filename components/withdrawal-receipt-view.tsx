import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import React, { useMemo } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';

import { formatWithdrawalReceipt, WithdrawalReceiptModel } from '@/lib/withdrawal-receipt';

export function WithdrawalReceiptView({ receipt }: { receipt: WithdrawalReceiptModel }) {
  const view = useMemo(() => formatWithdrawalReceipt(receipt), [receipt]);
  const success = view.stateTone === 'success';
  const pending = view.stateTone === 'pending';

  return (
    <View style={styles.receipt}>
      <View style={styles.topLine} />
      <Image source={require('@/assets/images/logo.png')} style={styles.logo} resizeMode="contain" />
      <Text style={styles.brand}>{view.brand}</Text>
      <Text style={styles.subtitle}>رسید رسمی انتقال وجه</Text>

      <View style={[styles.resultBox, success ? styles.successBox : pending ? styles.pendingBox : styles.dangerBox]}>
        <View style={[styles.resultIcon, success ? styles.successIcon : pending ? styles.pendingIcon : styles.dangerIcon]}>
          <Ionicons name={success ? 'checkmark' : pending ? 'time-outline' : 'close'} size={25} color="#fff" />
        </View>
        <Text style={styles.receiptTitle}>رسید انتقال وجه</Text>
        <Text style={[styles.message, success ? styles.successText : pending ? styles.pendingText : styles.dangerText]}>{view.message}</Text>
      </View>

      <View style={styles.details}>
        <ReceiptRow label="انتقال از" value={view.displaySourceAccount} ltr />
        <ReceiptRow label="نام صاحب حساب" value={view.sourceName} />
        <ReceiptRow label="به مقصد" value={view.displayDestinationCard} ltr />
        <ReceiptRow label="گیرنده" value={view.displayRecipientName} />
        <ReceiptRow label="شماره پیگیری" value={view.displayTrackingNumber} ltr />
        <ReceiptRow label="زمان" value={view.displayTime || 'ثبت نشده'} last />
      </View>

      <View style={styles.details}>
        <ReceiptRow label="مبلغ اصلی" value={view.displayAmountToman} />
        <ReceiptRow label={view.displayFeeLabel} value={view.displayFeeAmount} />
        <ReceiptRow label="مبلغ نهایی کسر شده" value={view.displayTotalCharged} last />
      </View>

      <View style={styles.amountBox}>
        <Text style={styles.amountLabel}>مبلغ رسید</Text>
        <Text style={styles.amountValue}>{view.displayAmount}</Text>
      </View>

      <View style={styles.statusBox}>
        <Text style={styles.statusLabel}>وضعیت</Text>
        <View style={[styles.statusPill, success ? styles.successBox : pending ? styles.pendingBox : styles.dangerBox]}>
          <Text style={[styles.statusText, success ? styles.successText : pending ? styles.pendingText : styles.dangerText]}>{view.statusLabel}</Text>
        </View>
      </View>

      <View style={styles.notice}>
        {view.notice.map((line) => <Text key={line} style={styles.noticeText}>{line}</Text>)}
      </View>

      <View style={styles.footer}>
        {!!view.supportPhone && <Text style={styles.support}>پشتیبانی: {view.supportPhone}</Text>}
        <Text style={styles.website}>{view.website}</Text>
      </View>
    </View>
  );
}

function ReceiptRow({ label, value, ltr = false, last = false }: { label: string; value: string; ltr?: boolean; last?: boolean }) {
  return (
    <View style={[styles.row, last && styles.lastRow]}>
      <Text style={styles.label}>{label}</Text>
      <Text style={[styles.value, ltr && styles.ltr]}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  receipt: { backgroundColor: appColors.surface, borderRadius: 20, padding: 18, borderWidth: 1, borderColor: appColors.surfaceRaised, overflow: 'hidden' },
  topLine: { height: 5, borderRadius: 5, backgroundColor: appColors.primary, marginHorizontal: -18, marginTop: -18, marginBottom: 18 },
  logo: { width: 64, height: 64, alignSelf: 'center' },
  brand: { fontFamily: 'VazirmatnBold', fontSize: 22, color: appColors.primary, textAlign: 'center', marginTop: 5 },
  subtitle: { fontFamily: 'Vazirmatn', fontSize: 11, color: appColors.textSecondary, textAlign: 'center', marginTop: 3 },
  resultBox: { borderWidth: 1, borderRadius: 15, padding: 15, marginTop: 18, alignItems: 'center' },
  resultIcon: { width: 46, height: 46, borderRadius: 23, alignItems: 'center', justifyContent: 'center', marginBottom: 9 },
  successBox: { backgroundColor: appColors.surfaceRaised, borderColor: '#a7f3d0' },
  pendingBox: { backgroundColor: appColors.surfaceRaised, borderColor: '#f59e0b' },
  dangerBox: { backgroundColor: appColors.surfaceRaised, borderColor: '#f87171' },
  successIcon: { backgroundColor: '#047857' },
  pendingIcon: { backgroundColor: '#c2410c' },
  dangerIcon: { backgroundColor: '#b91c1c' },
  receiptTitle: { fontFamily: 'VazirmatnBold', fontSize: 17, color: appColors.text, textAlign: 'center' },
  message: { fontFamily: 'VazirmatnBold', fontSize: 12, textAlign: 'center', marginTop: 6, writingDirection: 'rtl' },
  successText: { color: '#86efac' },
  pendingText: { color: '#fbbf24' },
  dangerText: { color: '#fca5a5' },
  details: { borderColor: appColors.surfaceRaised, borderWidth: 1, borderRadius: 13, marginTop: 14, overflow: 'hidden' },
  row: { minHeight: 52, flexDirection: 'row-reverse', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: appColors.surfaceRaised, paddingHorizontal: 13, gap: 12 },
  lastRow: { borderBottomWidth: 0 },
  label: { width: 92, fontFamily: 'Vazirmatn', fontSize: 11, color: appColors.textSecondary, textAlign: 'right' },
  value: { flex: 1, fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.text, textAlign: 'right', writingDirection: 'rtl' },
  ltr: { direction: 'ltr', writingDirection: 'ltr', textAlign: 'right' },
  amountBox: { backgroundColor: appColors.primary, borderRadius: 15, padding: 17, marginTop: 14 },
  amountLabel: { fontFamily: 'Vazirmatn', fontSize: 11, color: '#d1fae5', textAlign: 'center' },
  amountValue: { fontFamily: 'VazirmatnBold', fontSize: 20, lineHeight: 34, color: '#fff', textAlign: 'center', writingDirection: 'rtl', marginTop: 4 },
  statusBox: { flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', borderColor: appColors.surfaceRaised, borderWidth: 1, borderRadius: 13, padding: 13, marginTop: 14 },
  statusLabel: { fontFamily: 'Vazirmatn', fontSize: 12, color: appColors.textSecondary },
  statusPill: { borderWidth: 1, borderRadius: 99, paddingVertical: 6, paddingHorizontal: 13 },
  statusText: { fontFamily: 'VazirmatnBold', fontSize: 11 },
  notice: { backgroundColor: appColors.background, borderRightColor: appColors.primary, borderRightWidth: 4, borderRadius: 10, padding: 13, marginTop: 14 },
  noticeText: { fontFamily: 'Vazirmatn', fontSize: 11, lineHeight: 22, color: appColors.textSecondary, textAlign: 'right', writingDirection: 'rtl' },
  footer: { borderTopWidth: 1, borderTopColor: '#dce7ef', marginTop: 18, paddingTop: 14, alignItems: 'center' },
  support: { fontFamily: 'VazirmatnBold', fontSize: 12, color: appColors.primary, textAlign: 'center', marginBottom: 4 },
  website: { fontFamily: 'VazirmatnBold', fontSize: 12, color: appColors.primary, textAlign: 'center', writingDirection: 'ltr' },
});
