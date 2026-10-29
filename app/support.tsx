import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { Stack, useRouter } from 'expo-router';
import React from 'react';
import { Linking, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { showError } from '@/lib/toast';
import { appColors } from '@/lib/theme';

const WHATSAPP_URL = 'https://wa.me/989194998020';

const quickHelp = [
  { icon: 'receipt-outline' as const, text: 'وضعیت سفارش‌ها را در بخش سفارش‌ها بررسی کنید.' },
  { icon: 'shield-checkmark-outline' as const, text: 'وضعیت احراز هویت را در سطح دسترسی مشاهده کنید.' },
  { icon: 'refresh-outline' as const, text: 'از آخرین نسخه اپلیکیشن استفاده کنید.' },
];

const faqs = ['چگونه موجودی خود را افزایش دهم؟', 'چگونه سطح کاربری خود را ارتقا دهم؟', 'چگونه با پشتیبانی تماس بگیرم؟'];

export default function SupportScreen() {
  const router = useRouter();

  const handleWhatsApp = async () => {
    try {
      await Linking.openURL(WHATSAPP_URL);
    } catch {
      showError('خطا', 'امکان باز کردن واتساپ وجود ندارد.');
    }
  };

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.screen}>
        <View style={styles.container}>
          <View style={styles.header}>
            <TouchableOpacity style={styles.backButton} onPress={() => router.back()} accessibilityRole="button" accessibilityLabel="بازگشت">
              <Ionicons name="arrow-forward" size={21} color="#233b49" />
            </TouchableOpacity>
            <Text style={styles.headerTitle}>پشتیبانی</Text>
            <View style={styles.headerSpace} />
          </View>

          <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={styles.content}>
            <LinearGradient colors={['#123e4e', '#147d70']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.heroCard}>
              <View style={styles.heroCircle} />
              <View style={styles.heroIcon}><Ionicons name="headset-outline" size={31} color="#b9f4df" /></View>
              <Text style={styles.heroTitle}>پشتیبانی آفاریکس</Text>
              <Text style={styles.heroSubtitle}>در صورت داشتن سوال یا مشکل، تیم پشتیبانی آماده پاسخگویی به شماست.</Text>
              <View style={styles.heroAccent} />
            </LinearGradient>

            <View style={styles.sectionHeading}>
              <Text style={styles.sectionTitle}>راه‌های ارتباطی</Text>
              <Text style={styles.sectionCaption}>از مسیر رسمی زیر با تیم آفاریکس در ارتباط باشید</Text>
            </View>
            <View style={styles.contactCard}>
              <View style={styles.contactTop}>
                <View style={styles.whatsappIcon}><Ionicons name="logo-whatsapp" size={23} color="appColors.primary" /></View>
                <View style={styles.contactCopy}>
                  <Text style={styles.cardTitle}>پشتیبانی در واتساپ</Text>
                  <Text style={styles.cardText}>برای ارتباط مستقیم با تیم پشتیبانی از طریق واتساپ پیام ارسال کنید.</Text>
                </View>
              </View>
              <View style={styles.idRow}>
                <Text style={styles.contactId}>+989194998020</Text>
                <Text style={styles.idLabel}>شماره واتساپ</Text>
              </View>
              <TouchableOpacity style={styles.contactButton} onPress={handleWhatsApp} activeOpacity={0.85} accessibilityRole="button" accessibilityLabel="ارتباط با پشتیبانی در واتساپ">
                <Ionicons name="open-outline" size={18} color="#fff" />
                <Text style={styles.contactButtonText}>ارتباط با پشتیبانی</Text>
              </TouchableOpacity>
            </View>

            <View style={styles.sectionHeading}><Text style={styles.sectionTitle}>کمک سریع</Text></View>
            <View style={styles.helpCard}>
              <Text style={styles.helpTitle}>قبل از تماس بررسی کنید</Text>
              {quickHelp.map((item) => (
                <View key={item.text} style={styles.helpItem}>
                  <View style={styles.helpIcon}><Ionicons name={item.icon} size={17} color="appColors.primary" /></View>
                  <Text style={styles.helpText}>{item.text}</Text>
                </View>
              ))}
            </View>

            <View style={styles.sectionHeading}><Text style={styles.sectionTitle}>سوالات متداول</Text></View>
            <View style={styles.faqCard}>
              {faqs.map((question, index) => (
                <View key={question} style={[styles.faqItem, index === faqs.length - 1 && styles.lastFaqItem]}>
                  <Text style={styles.faqText}>{question}</Text>
                  <Ionicons name="chevron-back" size={18} color="appColors.textMuted" />
                </View>
              ))}
            </View>
          </ScrollView>
        </View>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: appColors.background },
  screen: { flex: 1, backgroundColor: appColors.background },
  container: { flex: 1, width: '100%', maxWidth: 680, alignSelf: 'center', paddingHorizontal: 16, paddingTop: 14 },
  header: { height: 52, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 },
  backButton: { width: 44, height: 44, borderRadius: 14, backgroundColor: appColors.surface, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: appColors.surfaceRaised },
  headerTitle: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text },
  headerSpace: { width: 44, height: 44 },
  content: { paddingBottom: 34 },
  heroCard: { minHeight: 190, borderRadius: 24, padding: 22, justifyContent: 'center', overflow: 'hidden', shadowColor: '#0d5960', shadowOpacity: 0.2, shadowRadius: 14, shadowOffset: { width: 0, height: 7 }, elevation: 4 },
  heroCircle: { position: 'absolute', width: 220, height: 220, borderRadius: 110, right: -85, top: -85, backgroundColor: 'rgba(255,255,255,0.08)' },
  heroIcon: { width: 59, height: 59, borderRadius: 18, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(183,244,222,0.16)', marginBottom: 15 },
  heroTitle: { fontFamily: 'VazirmatnBold', fontSize: 24, color: '#fff', textAlign: 'right' },
  heroSubtitle: { fontFamily: 'Vazirmatn', fontSize: 12, lineHeight: 22, color: appColors.textSecondary, textAlign: 'right', marginTop: 5 },
  heroAccent: { position: 'absolute', left: 22, bottom: 22, width: 34, height: 4, borderRadius: 2, backgroundColor: '#7de2ba' },
  sectionHeading: { alignItems: 'flex-end', marginTop: 23, marginBottom: 11 },
  sectionTitle: { fontFamily: 'VazirmatnBold', fontSize: 17, color: appColors.text },
  sectionCaption: { fontFamily: 'Vazirmatn', fontSize: 10, color: appColors.textMuted, marginTop: 3 },
  contactCard: { backgroundColor: appColors.surface, borderRadius: 21, padding: 18, borderWidth: 1, borderColor: appColors.surfaceRaised, shadowColor: '#193b49', shadowOpacity: 0.07, shadowRadius: 12, shadowOffset: { width: 0, height: 4 }, elevation: 2 },
  contactTop: { flexDirection: 'row-reverse', alignItems: 'flex-start' },
  whatsappIcon: { width: 48, height: 48, borderRadius: 15, alignItems: 'center', justifyContent: 'center', backgroundColor: appColors.surfaceRaised, marginLeft: 13 },
  contactCopy: { flex: 1, alignItems: 'flex-end' },
  cardTitle: { width: '100%', fontFamily: 'VazirmatnBold', fontSize: 15, color: appColors.text, textAlign: 'right' },
  cardText: { width: '100%', fontFamily: 'Vazirmatn', fontSize: 11, lineHeight: 21, color: appColors.textSecondary, textAlign: 'right', marginTop: 5 },
  idRow: { flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', marginTop: 17, paddingVertical: 11, paddingHorizontal: 13, borderRadius: 13, backgroundColor: appColors.surfaceRaised },
  contactId: { fontFamily: 'VazirmatnBold', fontSize: 15, color: '#147e69' },
  idLabel: { fontFamily: 'Vazirmatn', fontSize: 10, color: '#829598' },
  contactButton: { minHeight: 50, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'center', gap: 8, borderRadius: 14, backgroundColor: '#01F8FD', marginTop: 13, shadowColor: appColors.primary, shadowOpacity: 0.18, shadowRadius: 8, shadowOffset: { width: 0, height: 4 }, elevation: 2 },
  contactButtonText: { fontFamily: 'VazirmatnBold', fontSize: 13, color: '#00090A' },
  helpCard: { backgroundColor: appColors.surface, borderRadius: 20, padding: 18, borderWidth: 1, borderColor: appColors.surfaceRaised },
  helpTitle: { fontFamily: 'VazirmatnBold', fontSize: 14, color: appColors.textSecondary, textAlign: 'right', marginBottom: 6 },
  helpItem: { minHeight: 47, flexDirection: 'row-reverse', alignItems: 'center', borderTopWidth: 1, borderTopColor: appColors.surfaceRaised },
  helpIcon: { width: 31, height: 31, borderRadius: 10, alignItems: 'center', justifyContent: 'center', backgroundColor: appColors.surfaceRaised, marginLeft: 10 },
  helpText: { flex: 1, fontFamily: 'Vazirmatn', fontSize: 11, lineHeight: 19, color: appColors.textSecondary, textAlign: 'right' },
  faqCard: { backgroundColor: appColors.surface, borderRadius: 20, paddingHorizontal: 17, borderWidth: 1, borderColor: appColors.surfaceRaised },
  faqItem: { minHeight: 55, flexDirection: 'row-reverse', alignItems: 'center', borderBottomWidth: 1, borderBottomColor: appColors.surfaceRaised },
  lastFaqItem: { borderBottomWidth: 0 },
  faqText: { flex: 1, fontFamily: 'Vazirmatn', fontSize: 12, color: appColors.text, textAlign: 'right' },
});
