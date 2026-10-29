import React, { useMemo } from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';

import { formatRemittanceReceipt, RemittanceReceiptModel } from '@/lib/remittance-receipt';
import { appColors } from '@/lib/theme';

export function RemittanceReceiptView({ receipt }: { receipt: RemittanceReceiptModel }) {
  const view = useMemo(() => formatRemittanceReceipt(receipt), [receipt]);

  return (
    <View style={styles.receipt}>
      <View style={styles.topLine} />
      <Image source={require('@/assets/images/logo.png')} style={styles.logo} resizeMode="contain" />
      <Text style={styles.brand}>{view.brand}</Text>
      <Text style={styles.subtitle}>تأییدیه رسمی ثبت حواله</Text>

      <View style={styles.confirmation}>
        <Text style={styles.confirmationText}>تأییدیه شماره حواله: <Text style={styles.tracking}>{view.tracking}</Text></Text>
      </View>

      <View style={styles.details}>
        <ReceiptRow label="تاریخ" value={view.date} />
        <ReceiptRow label="فرستنده" value={view.sender} />
        <ReceiptRow label="گیرنده" value={view.receiver} />
        {view.destinationCountry ? <ReceiptRow label="کشور مقصد" value={view.destinationCountry} /> : null}
        <ReceiptRow label="نمایندگی / مقصد" value={view.destination} last />
      </View>

      <View style={styles.details}>
        <ReceiptRow label="مبلغ حواله" value={`${view.originalAmountToman} تومان`} />
        <ReceiptRow label={view.feeLabel} value={`${view.feeAmountToman} تومان`} />
        <ReceiptRow label="مبلغ نهایی کسر شده" value={`${view.totalChargedToman} تومان`} last />
      </View>

      <View style={styles.amountBox}>
        <Text style={styles.amountLabel}>مبلغ دریافتی حواله</Text>
        <Text style={styles.amountValue}>{view.amount} «{view.amountWords}» افغانی</Text>
      </View>

      <View style={styles.statusBox}>
        <Text style={styles.statusLabel}>وضعیت حواله</Text>
        <View style={styles.statusPill}><Text style={styles.statusText}>{view.customerStatus}</Text></View>
      </View>

      <View style={styles.notice}>
        {view.notice.map((line) => <Text key={line} style={styles.noticeText}>{line}</Text>)}
      </View>
      <Text style={styles.footer}>{view.footer}</Text>
    </View>
  );
}

function ReceiptRow({ label, value, last = false }: { label: string; value: string; last?: boolean }) {
  return (
    <View style={[styles.row, last && styles.lastRow]}>
      <Text style={styles.label}>{label}</Text>
      <Text style={styles.value}>{value}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  receipt: { backgroundColor: appColors.surface, borderRadius: 20, padding: 18, borderWidth: 1, borderColor: appColors.surfaceRaised, overflow: 'hidden' },
  topLine: { height: 5, borderRadius: 5, backgroundColor: appColors.primary, marginHorizontal: -18, marginTop: -18, marginBottom: 18 },
  logo: { width: 64, height: 64, alignSelf: 'center' },
  brand: { fontFamily: 'VazirmatnBold', fontSize: 21, color: appColors.primary, textAlign: 'center', marginTop: 5 },
  subtitle: { fontFamily: 'Vazirmatn', fontSize: 11, color: '#718096', textAlign: 'center', marginTop: 3 },
  confirmation: { backgroundColor: appColors.surfaceRaised, borderColor: '#99f6e4', borderWidth: 1, borderRadius: 13, padding: 14, marginTop: 18 },
  confirmationText: { fontFamily: 'VazirmatnBold', fontSize: 15, color: '#0f5e50', textAlign: 'center', writingDirection: 'rtl' },
  tracking: { color: appColors.primary },
  details: { borderColor: appColors.surfaceRaised, borderWidth: 1, borderRadius: 13, marginTop: 14, overflow: 'hidden' },
  row: { minHeight: 50, flexDirection: 'row-reverse', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: '#e8eff4', paddingHorizontal: 13 },
  lastRow: { borderBottomWidth: 0 },
  label: { width: 74, fontFamily: 'Vazirmatn', fontSize: 11, color: '#64748b', textAlign: 'right' },
  value: { flex: 1, fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.text, textAlign: 'right' },
  amountBox: { backgroundColor: appColors.surface, borderRadius: 15, padding: 17, marginTop: 14 },
  amountLabel: { fontFamily: 'Vazirmatn', fontSize: 11, color: '#d1fae5', textAlign: 'center' },
  amountValue: { fontFamily: 'VazirmatnBold', fontSize: 18, lineHeight: 32, color: '#fff', textAlign: 'center', writingDirection: 'rtl', marginTop: 4 },
  statusBox: { flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', borderColor: appColors.surfaceRaised, borderWidth: 1, borderRadius: 13, padding: 13, marginTop: 14 },
  statusLabel: { fontFamily: 'Vazirmatn', fontSize: 12, color: appColors.textSecondary },
  statusPill: { backgroundColor: appColors.surfaceRaised, borderColor: '#a7f3d0', borderWidth: 1, borderRadius: 99, paddingVertical: 6, paddingHorizontal: 13 },
  statusText: { fontFamily: 'VazirmatnBold', fontSize: 11, color: '#047857' },
  notice: { backgroundColor: appColors.surface, borderRightColor: appColors.primary, borderRightWidth: 4, borderRadius: 10, padding: 13, marginTop: 14 },
  noticeText: { fontFamily: 'Vazirmatn', fontSize: 11, lineHeight: 22, color: appColors.textSecondary, textAlign: 'right', writingDirection: 'rtl' },
  footer: { fontFamily: 'VazirmatnBold', fontSize: 12, color: appColors.primary, textAlign: 'center', marginTop: 18 },
});
