import { appColors } from '@/lib/theme';
import { Ionicons } from '@expo/vector-icons';
import * as ImagePicker from 'expo-image-picker';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Image, ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { apiUrl, fetchJson } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { showError, showSuccess } from '@/lib/toast';

type Picked = { uri: string; name?: string; mimeType?: string; type?: string; size?: number };

export default function VerificationUpgradeScreen() {
  const router = useRouter();
  const params = useLocalSearchParams<{ type?: string }>();
  const type = params.type === 'gold' ? 'gold' : 'silver';
  const { userToken } = useAuth();
  const [identity, setIdentity] = useState<Picked | null>(null);
  const [selfie, setSelfie] = useState<Picked | null>(null);
  const [video, setVideo] = useState<Picked | null>(null);
  const [status, setStatus] = useState<any>(null);
  const [loading, setLoading] = useState(false);

  const loadStatus = useCallback(async () => {
    if (!userToken) return;
    try {
      const body = new URLSearchParams({ api_token: userToken, request_type: type, action: 'status' });
      const result = await fetchJson<any>('verification-request.php', { method: 'POST', body: body.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      setStatus(result?.data || null);
    } catch { /* The upload screen remains usable when there is no previous request. */ }
  }, [type, userToken]);

  useEffect(() => { loadStatus(); }, [loadStatus]);

  const pick = async (kind: 'image' | 'video', target?: 'identity' | 'selfie') => {
    const needsCamera = kind === 'video' || target === 'selfie';
    if (needsCamera) {
      const permission = await ImagePicker.requestCameraPermissionsAsync();
      if (!permission.granted) { showError('\u0645\u062c\u0648\u0632 \u062f\u0648\u0631\u0628\u06cc\u0646', '\u0628\u0631\u0627\u06cc \u062b\u0628\u062a \u0633\u0644\u0641\u06cc \u0648 \u0648\u06cc\u062f\u06cc\u0648\u060c \u062f\u0633\u062a\u0631\u0633\u06cc \u0628\u0647 \u062f\u0648\u0631\u0628\u06cc\u0646 \u0631\u0627 \u0641\u0639\u0627\u0644 \u06a9\u0646\u06cc\u062f.'); return; }
    }
    const result = needsCamera
      ? await ImagePicker.launchCameraAsync({ mediaTypes: kind === 'image' ? ['images'] : ['videos'], allowsEditing: kind === 'image', quality: 0.8, videoMaxDuration: 60 })
      : await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], allowsEditing: true, quality: 0.8 });
    if (result.canceled || !result.assets[0]) return;
    const asset = result.assets[0];
    const picked: Picked = { uri: asset.uri, name: asset.fileName || undefined, mimeType: asset.mimeType || undefined, type: kind, size: asset.fileSize || undefined };
    if (kind === 'video') setVideo(picked); else if (target === 'selfie') setSelfie(picked); else setIdentity(picked);
  };

  const submit = async () => {
    if (!userToken || loading) return;
    if (type === 'silver' && (!identity || !selfie)) { showError('خطا', 'تصویر مدرک هویتی و سلفی با مدرک را انتخاب کنید.'); return; }
    if (type === 'gold' && !video) { showError('خطا', 'ویدیوی احراز هویت را انتخاب کنید.'); return; }
    if (type === 'silver' && [identity, selfie].some((file) => (file?.size || 0) > 8 * 1024 * 1024)) { showError('حجم فایل زیاد است', 'حجم هر تصویر باید حداکثر ۸ مگابایت باشد.'); return; }
    if (type === 'gold' && (video?.size || 0) > 35 * 1024 * 1024) { showError('حجم فایل زیاد است', 'حجم ویدیو باید حداکثر ۳۵ مگابایت باشد.'); return; }
    setLoading(true);
    try {
      const form = new FormData();
      form.append('api_token', userToken); form.append('request_type', type);
      const add = (field: string, file: Picked) => form.append(field, { uri: file.uri, name: file.name || `${field}.${type === 'gold' ? 'mp4' : 'jpg'}`, type: file.mimeType || (type === 'gold' ? 'video/mp4' : 'image/jpeg') } as any);
      if (type === 'silver') { add('identity_document', identity!); add('selfie', selfie!); } else add('video', video!);
      const response = await fetch(apiUrl('verification-request.php'), { method: 'POST', headers: { Accept: 'application/json' }, body: form });
      const result = await response.json();
      if (!response.ok || result?.success !== true) throw new Error(result?.message || 'ارسال درخواست انجام نشد.');
      showSuccess('ارسال شد', 'درخواست شما برای بررسی ارسال شد.');
      setStatus({ status: 'pending', request_type: type });
    } catch (error: any) { showError('خطا', error?.message || 'ارسال فایل‌ها انجام نشد.'); } finally { setLoading(false); }
  };

  const needsMoreInfo = status?.status === 'needs_more_info';
  const rejected = status?.status === 'rejected' || needsMoreInfo;
  const pending = status?.status === 'pending';
  const approved = status?.status === 'approved';
  return <SafeAreaView style={styles.safeArea}><Stack.Screen options={{ headerShown: false }} /><View style={styles.container}>
    <View style={styles.header}><TouchableOpacity style={styles.back} onPress={() => router.back()}><Ionicons name="arrow-forward" size={22} color="#374151" /></TouchableOpacity><Text style={styles.title}>{type === 'silver' ? 'احراز هویت نقره‌ای' : 'احراز هویت طلایی'}</Text><View style={styles.space} /></View>
    <ScrollView contentContainerStyle={styles.content} keyboardShouldPersistTaps="handled">
      {status?.admin_note && <View style={styles.rejected}><Text style={styles.rejectedTitle}>{needsMoreInfo ? 'پیام بررسی احراز هویت' : 'یادداشت بررسی'}</Text><Text style={styles.rejectedText}>{status.admin_note}</Text></View>}
      {type === 'silver' ? <>
        <Text style={styles.instructions}>برای ارتقاء به سطح نقره‌ای، تصویر واضح مدرک هویتی و یک سلفی در حالی که مدرک را در دست دارید ارسال کنید.</Text>
        <PickerCard title="تصویر مدرک هویتی" value={identity} onPick={() => pick('image', 'identity')} onRemove={() => setIdentity(null)} />
        <PickerCard title="سلفی با مدرک هویتی" value={selfie} onPick={() => identity ? pick('image', 'selfie') : showError('راهنما', 'ابتدا تصویر مدرک هویتی را انتخاب کنید.')} onRemove={() => setSelfie(null)} />
      </> : <>{/*
        <Text style={styles.instructions}>{'\u0648\u06cc\u062f\06cc\u0648\06cc \06a9\0648\062a\0627\0647 \06a9\0647 \06cc\06a9\0646\06cc\062f \06a9\0647 \06\u0686\0647\u0631\u0647 \0634\0645\0627 \0648 \0645\062f\0631\06a9 \0634\0646\u0627\0633\u0627\06cc\06cc \062f\0631 \062a\0635\0648\06cc\0631 \0645\0634\062e\0635 \0628\0627\0634\062f \0648 \0628\0627 \06cc\06a9 \062d\0631\06a9\062a \0633\u0627\062f\0647 \062f\0633\062a \062a\u0627\u06cc\06cc\062f \06a9\0646\06cc\062f \06a9\0647 \0648\06cc\062f\06cc\0648 \0632\0646\062f\0647 \0648 \0645\062a\0639\0644\0642 \0628\0647 \062e\0648\062f \0634\u0645\0627\0633\062a.'}</Text>
        */}<Text style={styles.instructions}>ویدیو باید شامل نمایش مدرک هویتی و گفتن نام و نام خانوادگی، نمایش کارت بانکی به نام خودتان با پوشاندن CVV و تاریخ انقضا، و نمایش واضح چهره باشد.</Text>
        <View style={styles.warning}><Text style={styles.warningText}>کارت بانکی باید به نام خودتان باشد. CVV و تاریخ انقضا را حتماً بپوشانید.</Text><Text style={styles.sentence}>این ویدیو بابت احراز هویت در اپلیکیشن افغان دیجیتال است.</Text></View>
        <PickerCard title="ویدیوی احراز هویت" value={video} onPick={() => pick('video')} onRemove={() => setVideo(null)} video />
        <Text style={styles.instructions}>{'\u0648\u06cc\u062f\u06cc\u0648\u06cc \u06a9\u0648\u062a\u0627\u0647 \u062e\u0648\u062f \u0631\u0627 \u0636\u0628\u0637 \u06a9\u0646\u06cc\u062f \u06a9\u0647 \u0686\u0647\u0631\u0647 \u0634\u0645\u0627 \u0648 \u0645\u062f\u0631\u06a9 \u0634\u0646\u0627\u0633\u0627\u06cc\u06cc \u062f\u0631 \u062a\u0635\u0648\u06cc\u0631 \u0645\u0634\u062e\u0635 \u0628\u0627\u0634\u062f \u0648 \u0628\u0627 \u06cc\u06a9 \u062d\u0631\u06a9\u062a \u0633\u0627\u062f\u0647 \u062f\u0633\u062a \u062a\u0627\u06cc\u06cc\u062f \u06a9\u0646\u06cc\u062f \u06a9\u0647 \u0648\u06cc\u062f\u06cc\u0648 \u0632\u0646\u062f\u0647 \u0648 \u0645\u062a\u0639\u0644\u0642 \u0628\u0647 \u062e\u0648\u062f \u0634\u0645\u0627\u0633\u062a.'}</Text>
      </>}
      {rejected && <View style={styles.rejected}><Text style={styles.rejectedTitle}>درخواست رد شده است</Text><Text style={styles.rejectedText}>{status?.rejection_reason || 'لطفاً فایل‌ها را اصلاح و دوباره ارسال کنید.'}</Text></View>}
      {pending && <View style={styles.pending}><Text style={styles.pendingText}>درخواست شما در حال بررسی است.</Text></View>}
      {approved && <View style={styles.approved}><Text style={styles.approvedText}>درخواست شما تأیید شده است.</Text></View>}
      {!pending && !approved && <TouchableOpacity style={[styles.submit, loading && styles.disabled]} onPress={submit} disabled={loading}>{loading ? <ActivityIndicator color="#fff" /> : <Text style={styles.submitText}>{rejected ? 'ارسال مجدد درخواست' : 'ارسال برای بررسی'}</Text>}</TouchableOpacity>}
    </ScrollView>
  </View></SafeAreaView>;
}

function PickerCard({ title, value, onPick, onRemove, video }: { title: string; value: Picked | null; onPick: () => void; onRemove: () => void; video?: boolean }) {
  return <View style={styles.picker}><Text style={styles.pickerTitle}>{title}</Text>{value && !video && <Image source={{ uri: value.uri }} style={styles.preview} />}{value && video && <View style={styles.videoPreview}><Ionicons name="videocam" size={28} color="appColors.primary" /><Text style={styles.fileName}>ویدیو انتخاب شد</Text></View>}<View style={styles.pickerActions}><TouchableOpacity style={styles.choose} onPress={onPick}><Text style={styles.chooseText}>{value ? 'جایگزینی' : 'انتخاب فایل'}</Text></TouchableOpacity>{value && <TouchableOpacity onPress={onRemove}><Text style={styles.remove}>حذف</Text></TouchableOpacity>}</View></View>;
}

const styles = StyleSheet.create({ safeArea: { flex: 1, backgroundColor: appColors.background }, container: { flex: 1, padding: 18 }, header: { flexDirection: 'row-reverse', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }, back: { width: 40, height: 40, borderRadius: 20, backgroundColor: appColors.surface, alignItems: 'center', justifyContent: 'center' }, title: { fontFamily: 'VazirmatnBold', fontSize: 18, color: appColors.text }, space: { width: 40 }, content: { paddingBottom: 32 }, instructions: { fontFamily: 'Vazirmatn', color: appColors.textSecondary, textAlign: 'right', lineHeight: 25, marginBottom: 14 }, picker: { backgroundColor: appColors.surface, borderRadius: 16, padding: 16, marginBottom: 14 }, pickerTitle: { fontFamily: 'VazirmatnBold', color: appColors.text, textAlign: 'right', marginBottom: 12 }, preview: { width: '100%', height: 150, borderRadius: 12, resizeMode: 'cover' }, videoPreview: { height: 90, borderRadius: 12, backgroundColor: appColors.surfaceRaised, alignItems: 'center', justifyContent: 'center' }, fileName: { fontFamily: 'Vazirmatn', color: appColors.primary, marginTop: 6 }, pickerActions: { flexDirection: 'row-reverse', alignItems: 'center', gap: 16, marginTop: 12 }, choose: { backgroundColor: appColors.surfaceRaised, borderRadius: 10, paddingHorizontal: 14, paddingVertical: 10 }, chooseText: { fontFamily: 'VazirmatnBold', color: appColors.primary }, remove: { fontFamily: 'Vazirmatn', color: '#dc2626' }, warning: { backgroundColor: '#fffbeb', borderRadius: 14, padding: 16, marginBottom: 14 }, warningText: { fontFamily: 'VazirmatnBold', color: '#92400e', textAlign: 'right', lineHeight: 24 }, sentence: { fontFamily: 'Vazirmatn', color: '#78350f', textAlign: 'right', marginTop: 10, lineHeight: 24 }, pending: { backgroundColor: appColors.surfaceRaised, borderRadius: 12, padding: 14, marginTop: 4 }, pendingText: { fontFamily: 'Vazirmatn', color: '#1d4ed8', textAlign: 'right' }, approved: { backgroundColor: appColors.surfaceRaised, borderRadius: 12, padding: 14, marginTop: 4 }, approvedText: { fontFamily: 'Vazirmatn', color: '#047857', textAlign: 'right' }, rejected: { backgroundColor: '#fef2f2', borderRadius: 12, padding: 14, marginTop: 4 }, rejectedTitle: { fontFamily: 'VazirmatnBold', color: '#b91c1c', textAlign: 'right' }, rejectedText: { fontFamily: 'Vazirmatn', color: '#991b1b', textAlign: 'right', marginTop: 5 }, submit: { minHeight: 52, borderRadius: 12, backgroundColor: appColors.primary, alignItems: 'center', justifyContent: 'center', marginTop: 18 }, disabled: { opacity: 0.65 }, submitText: { fontFamily: 'VazirmatnBold', color: '#fff' } });
