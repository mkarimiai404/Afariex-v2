import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import { LinearGradient } from 'expo-linear-gradient';
import { Stack, useRouter } from 'expo-router';
import React from 'react';
import { ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

const values = [
  {
    title: 'امنیت',
    text: 'حفاظت از اطلاعات و دارایی کاربران اولویت اصلی ماست.',
    icon: 'shield-checkmark-outline' as const,
    color: appColors.primary,
    background: '#e8f7f2',
  },
  {
    title: 'سادگی',
    text: 'تلاش می‌کنیم خدمات مالی را ساده و قابل دسترس کنیم.',
    icon: 'sparkles-outline' as const,
    color: '#3975c9',
    background: '#edf4ff',
  },
  {
    title: 'اعتماد',
    text: 'شفافیت و رضایت کاربران پایه همکاری ماست.',
    icon: 'people-outline' as const,
    color: '#9467d6',
    background: '#f3edff',
  },
];

export default function AboutScreen() {
  const router = useRouter();

  return (
    <SafeAreaView style={styles.safeArea}>
      <Stack.Screen options={{ headerShown: false }} />
      <View style={styles.screen}>
        <View style={styles.container}>
          <View style={styles.header}>
            <TouchableOpacity
              style={styles.backButton}
              onPress={() => router.back()}
              accessibilityRole="button"
              accessibilityLabel="بازگشت"
            >
              <Ionicons name="arrow-forward" size={21} color="#233b49" />
            </TouchableOpacity>
            <Text style={styles.headerTitle}>درباره ما</Text>
            <View style={styles.headerSpace} />
          </View>

          <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={styles.content}>
            <LinearGradient colors={['#123e4e', '#0e806e']} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.heroCard}>
              <View style={styles.heroGlow} />
              <View style={styles.heroIcon}>
                <Ionicons name="business-outline" size={30} color="#b9f4df" />
              </View>
              <View style={styles.heroCopy}>
                <Text style={styles.heroTitle}>درباره آفاریکس</Text>
                <Text style={styles.heroSubtitle}>راهکاری نوین برای مدیریت خدمات مالی دیجیتال</Text>
              </View>
              <View style={styles.heroAccent} />
            </LinearGradient>

            <InfoCard icon="information-circle-outline" title="آفاریکس چیست؟">
              آفاریکس یک پلتفرم خدمات مالی دیجیتال است که با هدف ساده‌تر کردن فرآیندهای مالی، ارائه تجربه‌ای امن و سریع برای کاربران طراحی شده است.
            </InfoCard>

            <InfoCard icon="compass-outline" title="ماموریت ما">
              هدف آفاریکس ایجاد بستری ساده، امن و قابل اعتماد برای ارائه خدمات مالی دیجیتال و ایجاد تجربه‌ای بهتر برای کاربران است.
            </InfoCard>

            <View style={styles.sectionHeading}>
              <Text style={styles.sectionTitle}>ارزش‌های ما</Text>
              <Text style={styles.sectionCaption}>اصولی که مسیر آفاریکس را شکل می‌دهند</Text>
            </View>
            <View style={styles.valuesGrid}>
              {values.map((value) => (
                <View key={value.title} style={styles.valueCard}>
                  <View style={[styles.valueIcon, { backgroundColor: value.background }]}>
                    <Ionicons name={value.icon} size={22} color={value.color} />
                  </View>
                  <Text style={styles.valueTitle}>{value.title}</Text>
                  <Text style={styles.valueText}>{value.text}</Text>
                </View>
              ))}
            </View>

            <View style={styles.contactCard}>
              <View style={styles.contactIcon}>
                <Ionicons name="heart-outline" size={24} color="appColors.primary" />
              </View>
              <View style={styles.contactCopy}>
                <Text style={styles.contactTitle}>همراه آفاریکس باشید</Text>
                <Text style={styles.contactText}>برای دریافت خدمات، پیشنهاد همکاری یا ارتباط با تیم آفاریکس، از بخش‌های مختلف اپلیکیشن استفاده کنید.</Text>
              </View>
            </View>
          </ScrollView>
        </View>
      </View>
    </SafeAreaView>
  );
}

function InfoCard({ icon, title, children }: { icon: keyof typeof Ionicons.glyphMap; title: string; children: string }) {
  return (
    <View style={styles.infoCard}>
      <View style={styles.infoIcon}><Ionicons name={icon} size={22} color="appColors.primary" /></View>
      <Text style={styles.cardTitle}>{title}</Text>
      <Text style={styles.cardText}>{children}</Text>
    </View>
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
  heroCard: { minHeight: 178, borderRadius: 24, padding: 22, justifyContent: 'center', overflow: 'hidden', shadowColor: '#0d5960', shadowOpacity: 0.2, shadowRadius: 14, shadowOffset: { width: 0, height: 7 }, elevation: 4 },
  heroGlow: { position: 'absolute', width: 210, height: 210, borderRadius: 105, right: -88, top: -80, backgroundColor: 'rgba(255,255,255,0.08)' },
  heroIcon: { width: 58, height: 58, borderRadius: 18, alignItems: 'center', justifyContent: 'center', backgroundColor: 'rgba(183,244,222,0.16)', marginBottom: 16 },
  heroCopy: { alignItems: 'flex-end' },
  heroTitle: { width: '100%', textAlign: 'right', fontFamily: 'VazirmatnBold', fontSize: 25, color: '#fff' },
  heroSubtitle: { width: '100%', textAlign: 'right', fontFamily: 'Vazirmatn', fontSize: 12, lineHeight: 21, color: '#d9f7ec', marginTop: 5 },
  heroAccent: { position: 'absolute', left: 22, bottom: 22, width: 34, height: 4, borderRadius: 2, backgroundColor: '#7de2ba' },
  infoCard: { backgroundColor: appColors.surface, borderRadius: 21, padding: 20, marginTop: 14, borderWidth: 1, borderColor: appColors.surfaceRaised, shadowColor: '#193b49', shadowOpacity: 0.06, shadowRadius: 12, shadowOffset: { width: 0, height: 4 }, elevation: 2 },
  infoIcon: { width: 44, height: 44, borderRadius: 14, alignItems: 'center', justifyContent: 'center', backgroundColor: appColors.surfaceRaised, marginBottom: 13 },
  cardTitle: { fontFamily: 'VazirmatnBold', fontSize: 16, color: appColors.text, textAlign: 'right' },
  cardText: { fontFamily: 'Vazirmatn', fontSize: 12, lineHeight: 24, color: appColors.textSecondary, textAlign: 'right', marginTop: 7 },
  sectionHeading: { alignItems: 'flex-end', marginTop: 24, marginBottom: 12 },
  sectionTitle: { fontFamily: 'VazirmatnBold', fontSize: 17, color: appColors.text },
  sectionCaption: { fontFamily: 'Vazirmatn', fontSize: 10, color: appColors.textMuted, marginTop: 3 },
  valuesGrid: { flexDirection: 'row-reverse', gap: 9 },
  valueCard: { flex: 1, minHeight: 170, backgroundColor: appColors.surface, borderRadius: 18, padding: 13, alignItems: 'flex-end', borderWidth: 1, borderColor: appColors.surfaceRaised },
  valueIcon: { width: 40, height: 40, borderRadius: 13, alignItems: 'center', justifyContent: 'center', marginBottom: 12 },
  valueTitle: { width: '100%', fontFamily: 'VazirmatnBold', fontSize: 13, color: appColors.text, textAlign: 'right' },
  valueText: { width: '100%', fontFamily: 'Vazirmatn', fontSize: 10, lineHeight: 19, color: appColors.textSecondary, textAlign: 'right', marginTop: 6 },
  contactCard: { flexDirection: 'row-reverse', alignItems: 'flex-start', backgroundColor: appColors.surfaceRaised, borderRadius: 20, padding: 18, marginTop: 18, borderWidth: 1, borderColor: appColors.accent },
  contactIcon: { width: 46, height: 46, borderRadius: 15, alignItems: 'center', justifyContent: 'center', backgroundColor: appColors.surface, marginLeft: 13 },
  contactCopy: { flex: 1, alignItems: 'flex-end' },
  contactTitle: { width: '100%', fontFamily: 'VazirmatnBold', fontSize: 15, color: appColors.text, textAlign: 'right' },
  contactText: { width: '100%', fontFamily: 'Vazirmatn', fontSize: 11, lineHeight: 21, color: appColors.textSecondary, textAlign: 'right', marginTop: 5 },
});
