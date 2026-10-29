import { appColors } from '@/lib/theme';
import { Ionicons, MaterialCommunityIcons } from '@expo/vector-icons';
import * as Clipboard from 'expo-clipboard';
import { Stack, useRouter } from 'expo-router';
import React from 'react';
import { Linking, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { SafeAreaView } from 'react-native-safe-area-context';
import { showError, showSuccess } from '@/lib/toast';

const RUBIKA_ID = '@Afariex';
// Reuse the official Rubika destination already used by the application.
const RUBIKA_URL = 'https://rubika.ir/Afariex2026';

const suitableFor = [
  { icon: 'swap-horizontal', text: 'صرافی‌ها' },
  { icon: 'business-outline', text: 'مجموعه‌های مالی' },
  { icon: 'trending-up-outline', text: 'کسب‌وکارهای حوزه ارز دیجیتال' },
  { icon: 'people-outline', text: 'شرکای تجاری' },
];

const benefits = [
  { icon: 'chatbubbles-outline', text: 'ارتباط مستقیم با تیم آفاریکس' },
  { icon: 'git-compare-outline', text: 'فرصت توسعه همکاری‌های مشترک' },
  { icon: 'layers-outline', text: 'دسترسی به خدمات و زیرساخت‌های مالی' },
];

export default function PartnershipScreen() {
  const router = useRouter();

  const handleRubika = async () => {
    try {
      if (await Linking.canOpenURL(RUBIKA_URL)) {
        await Linking.openURL(RUBIKA_URL);
        return;
      }
    } catch {
      // Fall through to the safe copy action below.
    }
    try {
      await Clipboard.setStringAsync(RUBIKA_ID);
      showSuccess('آیدی کپی شد', 'آیدی روبیکا در کلیپ‌بورد ذخیره شد.');
    } catch {
      showError('خطا', 'امکان باز کردن یا کپی آیدی روبیکا وجود ندارد.');
    }
  };

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.screen}>
        <View style={styles.container}>
          <View style={styles.header}>
            <TouchableOpacity style={styles.backButton} onPress={() => router.back()} accessibilityRole="button" accessibilityLabel="بازگشت"><Ionicons name="arrow-forward" size={21} color="#243247" /></TouchableOpacity>
            <Text style={styles.title}>همکاری با ما</Text>
            <View style={styles.headerSpace} />
          </View>

          <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
            <LinearGradient colors={['#123d4d', '#087a67']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.hero}>
              <View style={styles.heroIcon}><MaterialCommunityIcons name="handshake-outline" size={30} color="#b9f3dc" /></View>
              <Text style={styles.heroTitle}>همکاری با ما</Text>
              <Text style={styles.heroSubtitle}>همراه ما در توسعه خدمات مالی دیجیتال باشید</Text>
              <View style={styles.heroLine} />
              <Text style={styles.heroNote}>ارتباطات حرفه‌ای، آغاز فرصت‌های مشترک</Text>
            </LinearGradient>

            <View style={styles.messageCard}>
              <View style={styles.sectionHeading}><View style={styles.headingIcon}><Ionicons name="briefcase-outline" size={19} color="#0e9270" /></View><Text style={styles.sectionTitle}>همکاری تجاری با آفاریکس</Text></View>
              <Text style={styles.message}>اگر صرافی، مجموعه مالی یا فعال حوزه ارز دیجیتال هستید و تمایل دارید با آفاریکس همکاری داشته باشید، تیم ما آماده بررسی پیشنهادهای همکاری و ایجاد ارتباطات تجاری جدید است.</Text>
              <Text style={styles.message}>برای شروع همکاری و دریافت اطلاعات بیشتر، از طریق راه ارتباطی زیر با ما در ارتباط باشید.</Text>
            </View>

            <InfoCard title="مناسب برای" icon="target" items={suitableFor} />
            <InfoCard title="مزایای همکاری" icon="star-outline" items={benefits} />

            <View style={styles.contactCard}>
              <View style={styles.contactHeading}><View style={styles.rubikaIcon}><MaterialCommunityIcons name="message-text-outline" size={21} color="#fff" /></View><View style={styles.contactTitleWrap}><Text style={styles.sectionTitle}>ارتباط با تیم همکاری</Text><Text style={styles.contactCaption}>پاسخ‌گویی از طریق کانال رسمی آفاریکس</Text></View></View>
              <View style={styles.contactRow}><Text style={styles.contactValue}>{RUBIKA_ID}</Text><Text style={styles.contactLabel}>آیدی روبیکا</Text></View>
              <TouchableOpacity style={styles.contactButton} onPress={handleRubika} activeOpacity={0.85} accessibilityRole="button" accessibilityLabel="ارتباط در روبیکا"><MaterialCommunityIcons name="open-in-new" size={18} color="#fff" /><Text style={styles.contactButtonText}>ارتباط در روبیکا</Text></TouchableOpacity>
            </View>
          </ScrollView>
        </View>
      </View>
    </SafeAreaView>
  );
}

function InfoCard({ title, icon, items }: { title: string; icon: keyof typeof MaterialCommunityIcons.glyphMap; items: { icon: string; text: string }[] }) {
  return <View style={styles.infoCard}><View style={styles.sectionHeading}><View style={styles.headingIcon}><MaterialCommunityIcons name={icon} size={19} color="#0e9270" /></View><Text style={styles.sectionTitle}>{title}</Text></View><View style={styles.infoItems}>{items.map((item) => <View key={item.text} style={styles.infoItem}><View style={styles.itemIcon}><Ionicons name={item.icon as keyof typeof Ionicons.glyphMap} size={17} color="#0e9270" /></View><Text style={styles.itemText}>{item.text}</Text></View>)}</View></View>;
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: appColors.background }, screen: { flex: 1, backgroundColor: appColors.background }, container: { flex: 1, width: '100%', maxWidth: 820, alignSelf: 'center', paddingHorizontal: 16, paddingTop: 14 },
  header: { height: 52, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }, backButton: { width: 44, height: 44, borderRadius: 14, backgroundColor: appColors.surface, alignItems: 'center', justifyContent: 'center', borderWidth: 1, borderColor: appColors.surfaceRaised }, title: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text }, headerSpace: { width: 44, height: 44 }, content: { paddingBottom: 34 },
  hero: { borderRadius: 28, minHeight: 235, padding: 26, alignItems: 'flex-end', shadowColor: '#123c4a', shadowOpacity: 0.2, shadowRadius: 20, shadowOffset: { width: 0, height: 10 }, elevation: 5 }, heroIcon: { width: 66, height: 66, borderRadius: 22, backgroundColor: 'rgba(255,255,255,0.16)', borderWidth: 1, borderColor: 'rgba(255,255,255,0.2)', alignItems: 'center', justifyContent: 'center' }, heroTitle: { width: '100%', fontFamily: 'VazirmatnBold', fontSize: 27, lineHeight: 38, color: '#fff', textAlign: 'right', marginTop: 21 }, heroSubtitle: { width: '100%', fontFamily: 'Vazirmatn', fontSize: 14, lineHeight: 25, color: '#e0f4ee', textAlign: 'right', marginTop: 8 }, heroLine: { width: '100%', height: 1, backgroundColor: 'rgba(255,255,255,0.2)', marginTop: 23 }, heroNote: { width: '100%', fontFamily: 'Vazirmatn', fontSize: 12, color: '#c4e9df', textAlign: 'right', marginTop: 14 },
  messageCard: { backgroundColor: appColors.surface, borderRadius: 24, padding: 22, marginTop: 18, borderWidth: 1, borderColor: appColors.surfaceRaised, shadowColor: '#173b48', shadowOpacity: 0.07, shadowRadius: 14, shadowOffset: { width: 0, height: 5 }, elevation: 2 }, sectionHeading: { flexDirection: 'row-reverse', alignItems: 'center', gap: 12 }, headingIcon: { width: 40, height: 40, borderRadius: 13, backgroundColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' }, sectionTitle: { fontFamily: 'VazirmatnBold', fontSize: 17, color: appColors.text, textAlign: 'right' }, message: { fontFamily: 'Vazirmatn', fontSize: 13, lineHeight: 25, color: appColors.textSecondary, textAlign: 'right', marginTop: 16 }, infoCard: { backgroundColor: appColors.surface, borderRadius: 24, padding: 20, marginTop: 16, borderWidth: 1, borderColor: appColors.surfaceRaised, shadowColor: '#173b48', shadowOpacity: 0.06, shadowRadius: 14, shadowOffset: { width: 0, height: 5 }, elevation: 2 }, infoItems: { marginTop: 16, gap: 11 }, infoItem: { flexDirection: 'row-reverse', alignItems: 'center', minHeight: 66, gap: 13, paddingHorizontal: 13, paddingVertical: 11, borderRadius: 16, backgroundColor: appColors.surfaceRaised, borderWidth: 1, borderColor: appColors.surfaceRaised }, itemIcon: { width: 40, height: 40, borderRadius: 13, backgroundColor: appColors.surface, alignItems: 'center', justifyContent: 'center' }, itemText: { flex: 1, fontFamily: 'Vazirmatn', fontSize: 13, lineHeight: 21, color: appColors.textSecondary, textAlign: 'right' },
  contactCard: { backgroundColor: appColors.surface, borderRadius: 26, padding: 22, marginTop: 16, borderWidth: 1, borderColor: '#cfe9df', shadowColor: '#0d8067', shadowOpacity: 0.13, shadowRadius: 18, shadowOffset: { width: 0, height: 7 }, elevation: 3 }, contactHeading: { flexDirection: 'row-reverse', alignItems: 'center', gap: 13 }, rubikaIcon: { width: 50, height: 50, borderRadius: 16, backgroundColor: '#3b82f6', alignItems: 'center', justifyContent: 'center' }, contactTitleWrap: { flex: 1, alignItems: 'flex-end' }, contactCaption: { fontFamily: 'Vazirmatn', fontSize: 11, color: appColors.textSecondary, textAlign: 'right', marginTop: 5 }, contactRow: { flexDirection: 'row-reverse', alignItems: 'baseline', justifyContent: 'space-between', borderTopWidth: 1, borderTopColor: appColors.surfaceRaised, marginTop: 21, paddingTop: 18 }, contactLabel: { fontFamily: 'Vazirmatn', fontSize: 12, color: appColors.textSecondary }, contactValue: { fontFamily: 'VazirmatnBold', fontSize: 23, color: appColors.text, letterSpacing: 0.5 }, contactButton: { minHeight: 52, borderRadius: 16, marginTop: 20, backgroundColor: '#01F8FD', flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'center', gap: 9, shadowColor: '#01F8FD', shadowOpacity: 0.2, shadowRadius: 9, shadowOffset: { width: 0, height: 4 }, elevation: 2 }, contactButtonText: { fontFamily: 'VazirmatnBold', fontSize: 14, color: '#fff' },
});
