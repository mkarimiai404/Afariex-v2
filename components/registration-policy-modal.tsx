import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import React, { useEffect, useState } from 'react';
import { Modal, SafeAreaView, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';

export type PolicySection = 'terms' | 'privacy';

const privacySections = [
  { title: 'مقدمه', body: 'این سیاست توضیح می‌دهد آفارایکس برای ارائه خدمات، چه اطلاعاتی را دریافت و چگونه از آن‌ها محافظت می‌کند. استفاده از خدمات به معنی صرف‌نظر کردن کاربر از حقوق قانونی او نیست.' },
  { title: 'اطلاعاتی که دریافت می‌کنیم', body: 'اطلاعات ثبت‌نام، اطلاعات تماس، داده‌های لازم برای درخواست‌های خدماتی و سوابق فنی ضروری برای امنیت و عملکرد سامانه دریافت می‌شوند. آفارایکس از دریافت اطلاعات غیرضروری برای ثبت رضایت خودداری می‌کند.' },
  { title: 'اطلاعات احراز هویت', body: 'در صورت استفاده از خدماتی که نیازمند احراز هویت هستند، اطلاعات و مدارک لازم برای بررسی هویت و سطح دسترسی کاربر دریافت و فقط برای ارائه امن خدمات و رعایت الزامات قابل اعمال پردازش می‌شوند.' },
  { title: 'تراکنش‌ها و حواله‌ها', body: 'اطلاعات لازم درباره درخواست‌های مالی و حواله‌ها، از جمله مبلغ، طرف‌های مرتبط، مقصد، وضعیت و زمان ثبت، برای اجرای درخواست، پیگیری، پشتیبانی و نگهداری سوابق ضروری پردازش می‌شوند.' },
  { title: 'نحوه استفاده از اطلاعات', body: 'اطلاعات برای ایجاد و مدیریت حساب، ارائه خدمات درخواستی، پشتیبانی، پیشگیری از سوءاستفاده و رعایت الزامات قانونی قابل اعمال استفاده می‌شوند.' },
  { title: 'حفظ و امنیت اطلاعات', body: 'آفارایکس متناسب با ماهیت اطلاعات از کنترل‌های فنی و اجرایی معقول برای کاهش خطر دسترسی غیرمجاز، افشا، تغییر یا از بین رفتن اطلاعات استفاده می‌کند. با این حال، کاربر نیز باید دستگاه و اطلاعات ورود خود را امن نگه دارد.' },
  { title: 'اشتراک اطلاعات در موارد قانونی یا خدمات ضروری', body: 'اطلاعات فقط در حد لازم برای ارائه خدمات ضروری، رسیدگی به درخواست معتبر قانونی یا حفاظت از امنیت کاربران و سامانه در اختیار اشخاص مجاز قرار می‌گیرد.' },
  { title: 'نگهداری اطلاعات', body: 'اطلاعات فقط تا زمانی نگهداری می‌شوند که برای ارائه خدمات، امنیت، رسیدگی به اختلافات یا الزامات قانونی لازم باشند و سپس مطابق رویه‌های امن مدیریت می‌شوند.' },
  { title: 'مسئولیت کاربر در حفظ اطلاعات ورود', body: 'کاربر مسئول نگهداری امن رمز عبور، PIN و ابزارهای دسترسی به حساب خود است و باید هرگونه دسترسی مشکوک را در سریع‌ترین زمان ممکن به آفارایکس اطلاع دهد.' },
  { title: 'تغییرات سیاست', body: 'ممکن است این سیاست برای هماهنگی با خدمات یا الزامات جدید به‌روزرسانی شود. نسخه جدید و زمان اجرای آن از مسیرهای مناسب داخل سامانه اطلاع‌رسانی خواهد شد.' },
  { title: 'راه ارتباط با آفارایکس', body: 'برای پرسش‌های مرتبط با حریم خصوصی، از راه‌های ارتباطی رسمی درج‌شده در اپلیکیشن یا وب‌سایت آفارایکس با پشتیبانی تماس بگیرید.' },
];

const termsSections = [
  { title: 'صحت اطلاعات واردشده', body: 'کاربر موظف است اطلاعات صحیح، کامل و متعلق به خود را وارد کند و در صورت تغییر، آن‌ها را از مسیرهای در دسترس به‌روز نگه دارد.' },
  { title: 'اطلاعات گیرنده و حواله', body: 'مسئولیت بررسی نام، شماره تماس، مقصد و سایر اطلاعات گیرنده پیش از ثبت حواله بر عهده کاربر است. اطلاعات نادرست می‌تواند باعث تأخیر یا نیاز به پیگیری شود.' },
  { title: 'رعایت قوانین و محدودیت‌های خدمات', body: 'کاربر باید از خدمات در چارچوب قوانین قابل اعمال، محدودیت‌های اعلام‌شده و اهداف مجاز استفاده کند.' },
  { title: 'بررسی یا رد تراکنش', body: 'آفارایکس می‌تواند در موارد ضروری امنیتی، وجود اطلاعات ناقص، مغایرت یا الزامات قانونی، درخواست را بررسی بیشتر کند یا از انجام آن خودداری کند.' },
  { title: 'حفظ حساب، رمز و PIN', body: 'حفظ محرمانگی اطلاعات ورود، رمز عبور و PIN بر عهده کاربر است. عملیات انجام‌شده از حساب باید فوراً در صورت مشکوک بودن به پشتیبانی گزارش شود.' },
  { title: 'استفاده غیرمجاز یا متقلبانه', body: 'استفاده از حساب دیگران، ارائه اطلاعات جعلی، دور زدن کنترل‌های امنیتی یا هرگونه استفاده متقلبانه و آسیب‌زا ممنوع است.' },
  { title: 'تغییر شرایط و اطلاع‌رسانی', body: 'شرایط استفاده ممکن است با تغییر خدمات یا الزامات قابل اعمال به‌روزرسانی شود. نسخه‌های بعدی پیش از اجرا از مسیرهای مناسب به کاربران اطلاع‌رسانی می‌شوند.' },
];

export function RegistrationPolicyModal({ visible, initialSection, onBack, onAccept }: {
  visible: boolean;
  initialSection: PolicySection;
  onBack: () => void;
  onAccept: () => void;
}) {
  const [section, setSection] = useState<PolicySection>(initialSection);
  useEffect(() => { if (visible) setSection(initialSection); }, [initialSection, visible]);
  const content = section === 'terms' ? termsSections : privacySections;

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onBack}>
      <SafeAreaView style={styles.safe}>
        <View style={styles.header}>
          <TouchableOpacity style={styles.backIcon} onPress={onBack} accessibilityRole="button" accessibilityLabel="بازگشت"><Ionicons name="arrow-forward" size={22} color="#334e5b" /></TouchableOpacity>
          <Text style={styles.headerTitle}>قوانین و حریم خصوصی</Text><View style={styles.headerSpace} />
        </View>
        <View style={styles.tabs}>
          <TouchableOpacity style={[styles.tab, section === 'terms' && styles.activeTab]} onPress={() => setSection('terms')}><Text style={[styles.tabText, section === 'terms' && styles.activeTabText]}>شرایط استفاده</Text></TouchableOpacity>
          <TouchableOpacity style={[styles.tab, section === 'privacy' && styles.activeTab]} onPress={() => setSection('privacy')}><Text style={[styles.tabText, section === 'privacy' && styles.activeTabText]}>سیاست حفظ حریم خصوصی</Text></TouchableOpacity>
        </View>
        <ScrollView contentContainerStyle={styles.content} showsVerticalScrollIndicator={false}>
          <Text style={styles.title}>{section === 'terms' ? 'قوانین و شرایط استفاده آفارایکس' : 'سیاست حفظ حریم خصوصی آفارایکس'}</Text>
          <Text style={styles.intro}>لطفاً متن زیر را با دقت مطالعه کنید.</Text>
          {content.map((item) => <View key={item.title} style={styles.section}><Text style={styles.sectionTitle}>{item.title}</Text><Text style={styles.body}>{item.body}</Text></View>)}
        </ScrollView>
        <View style={styles.actions}>
          <TouchableOpacity style={styles.acceptButton} onPress={onAccept} accessibilityRole="button"><Text style={styles.acceptText}>قبول و ادامه</Text></TouchableOpacity>
          <TouchableOpacity style={styles.backButton} onPress={onBack} accessibilityRole="button"><Text style={styles.backText}>بازگشت</Text></TouchableOpacity>
        </View>
      </SafeAreaView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: appColors.background },
  header: { height: 60, paddingHorizontal: 16, flexDirection: 'row-reverse', alignItems: 'center', justifyContent: 'space-between' },
  backIcon: { width: 42, height: 42, borderRadius: 13, backgroundColor: appColors.surface, borderWidth: 1, borderColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' },
  headerTitle: { fontFamily: 'VazirmatnBold', fontSize: 17, color: appColors.text }, headerSpace: { width: 42 },
  tabs: { flexDirection: 'row-reverse', marginHorizontal: 16, padding: 4, borderRadius: 14, backgroundColor: appColors.surfaceRaised },
  tab: { flex: 1, minHeight: 43, alignItems: 'center', justifyContent: 'center', borderRadius: 11, paddingHorizontal: 5 }, activeTab: { backgroundColor: appColors.surface },
  tabText: { fontFamily: 'Vazirmatn', fontSize: 11, color: appColors.textMuted, textAlign: 'center' }, activeTabText: { fontFamily: 'VazirmatnBold', color: appColors.primary },
  content: { width: '100%', maxWidth: 720, alignSelf: 'center', paddingHorizontal: 20, paddingTop: 22, paddingBottom: 24 },
  title: { fontFamily: 'VazirmatnBold', fontSize: 20, lineHeight: 32, color: appColors.text, textAlign: 'right' },
  intro: { fontFamily: 'Vazirmatn', fontSize: 12, color: appColors.textMuted, textAlign: 'right', marginTop: 5 },
  section: { backgroundColor: appColors.surface, borderWidth: 1, borderColor: appColors.surfaceRaised, borderRadius: 14, padding: 15, marginTop: 12 },
  sectionTitle: { fontFamily: 'VazirmatnBold', fontSize: 13, color: '#165e55', textAlign: 'right' },
  body: { fontFamily: 'Vazirmatn', fontSize: 12, lineHeight: 24, color: appColors.textSecondary, textAlign: 'right', writingDirection: 'rtl', marginTop: 6 },
  actions: { padding: 16, paddingTop: 10, borderTopWidth: 1, borderTopColor: '#e2eaec', backgroundColor: appColors.surface, gap: 9 },
  acceptButton: { minHeight: 50, borderRadius: 14, backgroundColor: appColors.primary, alignItems: 'center', justifyContent: 'center' }, acceptText: { fontFamily: 'VazirmatnBold', fontSize: 14, color: '#fff' },
  backButton: { minHeight: 46, borderRadius: 14, borderWidth: 1, borderColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' }, backText: { fontFamily: 'Vazirmatn', fontSize: 13, color: appColors.textSecondary },
});
