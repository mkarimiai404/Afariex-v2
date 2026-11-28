import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Stack, useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { ActivityIndicator, Alert, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useAuth } from '@/lib/auth-context';
import { fetchJson, isApiResponseError } from '@/lib/api';
import { appColors } from '@/lib/theme';

type Package = { id: string; title: string; operator: string; internet_type: string; sim_type: string; amount: number };
type Receipt = { status: 'success' | 'failed' | 'pending'; order_id: string; client_request_id: string; product_title: string; amount: number; fee_amount: number; total_amount: number; mobile: string; tracking_code?: string | null };
const operators = [{ id: 'MTN', label: 'ایرانسل' }, { id: 'MCI', label: 'همراه اول' }, { id: 'RTL', label: 'رایتل' }, { id: 'SHT', label: 'شاتل' }];
const durations: Record<string, string> = { hourly: 'ساعتی', daily: 'روزانه', weekly: 'هفتگی', monthly: 'ماهانه', yearly: 'سالانه', amazing: 'شگفت‌انگیز', TDLTE: 'TDLTE' };
const fmt = (v: number) => (Number.isFinite(Number(v)) ? Number(v).toLocaleString('fa-IR') : '۰');
const digits = (s: string) => s.replace(/[۰-۹]/g, c => String(c.charCodeAt(0) - 1776)).replace(/[٠-٩]/g, c => String(c.charCodeAt(0) - 1632));
const uuid = () => { const b = new Uint8Array(16); if (globalThis.crypto?.getRandomValues) globalThis.crypto.getRandomValues(b); else b.forEach((_,i)=>{b[i]=Math.floor(Math.random()*256);}); b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;const h=Array.from(b,n=>n.toString(16).padStart(2,'0')).join('');return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`; };
const post = (endpoint: string, token: string, data: Record<string, unknown>) => fetchJson<any>(endpoint, { method:'POST', headers:{ 'Content-Type':'application/json',Authorization:`Bearer ${token}` }, body: JSON.stringify({ api_token: token, ...data }) });

export default function InternetScreen() {
 const router = useRouter();
 const { userId,userToken,isAuthenticated,isInitialized } = useAuth();
 const [items,setItems]=useState<Package[]>([]);const [loading,setLoading]=useState(true);const [working,setWorking]=useState(false);
 const [operator,setOperator]=useState('MTN');const [sim,setSim]=useState('credit');const [mobile,setMobile]=useState('');const [duration,setDuration]=useState('all');
 const [selected,setSelected]=useState('');const [fee,setFee]=useState<{fee_amount:number;total_amount:number}|null>(null);
 const [receipt,setReceipt]=useState<Receipt|null>(null);const [pendingId,setPendingId]=useState('');
 const storageKey=`afariex_internet_pending_${encodeURIComponent(userId||'')}`;
 const load=useCallback(async()=>{
  if(!userToken)return;
  setLoading(true);
  try { const r=await post('internet-packages.php',userToken,{});setItems(Array.isArray(r?.data?.packages)?r.data.packages:[]); }
  catch{Alert.alert('خطا','فهرست بسته‌ها در دسترس نیست. لطفاً دوباره تلاش کنید.');}
  finally{setLoading(false);}
 },[userToken]);
 useEffect(()=>{if(isInitialized&&!isAuthenticated)router.replace('/login' as any);},[isInitialized,isAuthenticated,router]);
 useEffect(()=>{if(isInitialized&&isAuthenticated)load();},[isInitialized,isAuthenticated,load]);
 useEffect(()=>{if(userId) AsyncStorage.getItem(storageKey).then(v=>{if(v)setPendingId(v);}).catch(()=>{});},[userId,storageKey]);
 const visible=useMemo(()=>items.filter(p=>p.operator===operator&&p.sim_type===sim&&(duration==='all'||p.internet_type===duration)),[items,operator,sim,duration]);
 const choice=useMemo(()=>items.find(p=>p.id===selected&&p.operator===operator&&p.sim_type===sim),[items,selected,operator,sim]);
 useEffect(()=>{setSelected('');setFee(null);},[operator,sim,duration]);
 useEffect(()=>{
  let alive=true;setFee(null);
  if(!choice||!userToken)return;
  const body=new URLSearchParams({api_token:userToken,service_code:'internet_package',amount:String(choice.amount)});
  fetchJson<any>('service-fee-preview.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()})
   .then(r=>{if(alive&&r?.success===true){const f=r?.data?.fee;if(f)setFee({fee_amount:Number(f.fee_amount),total_amount:Number(f.total_amount)});}})
   .catch(()=>{});return()=>{alive=false};
 },[choice,userToken]);
 const check=useCallback(async(id:string)=>{
  if(!userToken||!id)return;
  setWorking(true);
  try {const r=await post('internet-package-status.php',userToken,{client_request_id:id});
   const d=r?.data as Receipt | undefined;
   if(d){setReceipt(d);if(d.status!=='pending'){setPendingId('');await AsyncStorage.removeItem(storageKey);}else setPendingId(id);}
  }catch(error){
   if(isApiResponseError(error) && error.responseCode==='ORDER_NOT_FOUND'){
     await AsyncStorage.removeItem(storageKey);setPendingId('');
     Alert.alert('سفارش ثبت نشده','درخواستی با این شناسه در سرور پیدا نشد. می‌توانید خرید تازه‌ای انجام دهید.');
   }else Alert.alert('وضعیت نامشخص','استعلام انجام نشد. شناسه سفارش حفظ شده است؛ از خرید مجدد خودداری کنید.');
  }
  finally{setWorking(false);}
 },[userToken,storageKey]);
 const buy=async()=>{
  const normalized=digits(mobile).replace(/[\s().-]/g,'');
  if(!/^09\d{9}$/.test(normalized)){Alert.alert('شماره نامعتبر','شماره باید ۱۱ رقم و با ۰۹ شروع شود.');return;}
  if(!choice||!fee||!userToken||!userId||working)return;
  if(pendingId){Alert.alert('سفارش در انتظار','ابتدا وضعیت سفارش قبلی را بررسی کنید.');return;}
  Alert.alert('تأیید خرید بسته اینترنت',`${choice.title}\nشماره: ${normalized}\nمبلغ: ${fmt(choice.amount)} تومان\nکارمزد: ${fmt(fee.fee_amount)} تومان\nمجموع برداشت از کیف پول: ${fmt(fee.total_amount)} تومان`,[
   {text:'انصراف',style:'cancel'},
   {text:'پرداخت از کیف پول',onPress:async()=>{
    const requestId=uuid();setWorking(true);
    // Persist before request: prevent accidental second provider purchase on network timeout.
    try{await AsyncStorage.setItem(storageKey,requestId);setPendingId(requestId);
     const r=await post('internet-package-create.php',userToken,{client_request_id:requestId,package_id:choice.id,operator,sim_type:sim,mobile:normalized});
     const d=r?.data as Receipt|undefined;
     if(d){setReceipt(d);if(d.status!=='pending'){await AsyncStorage.removeItem(storageKey);setPendingId('');}}
     else Alert.alert('وضعیت نامشخص','شناسه درخواست ذخیره شده است؛ وضعیت را بررسی کنید.');
    }catch(error){
     if(isApiResponseError(error)&&error.status>=400&&error.status<500){
       await AsyncStorage.removeItem(storageKey);setPendingId('');
       Alert.alert('خرید انجام نشد',error.safeMessage||'اطلاعات خرید یا موجودی کیف پول را بررسی کنید.');
     }else Alert.alert('وضعیت نامشخص','ممکن است درخواست ثبت شده باشد. دوباره خرید نکنید؛ از دکمه پیگیری استفاده کنید.');
    }
    finally{setWorking(false);}
   }}]);
 };
 return <SafeAreaView style={s.screen}><Stack.Screen options={{headerShown:false}}/><View style={s.top}><TouchableOpacity accessibilityRole="button" onPress={()=>router.back()} style={s.back}><Ionicons name="arrow-forward" size={23} color="#234052"/></TouchableOpacity><Text style={s.heading}>خرید بسته اینترنت</Text><View style={{width:42}}/></View>
 <ScrollView contentContainerStyle={s.content} keyboardShouldPersistTaps="handled">
  <View style={s.hero}><Ionicons name="wifi" size={32} color="#a5f3fc"/><Text style={s.heroTitle}>اینترنت، سریع و بدون دردسر</Text><Text style={s.heroSub}>بسته مناسب را انتخاب کنید و هزینه را مستقیم از کیف پول آفاریکس بپردازید.</Text></View>
  {pendingId!==''&&<View style={s.alert}><Text style={s.alertTitle}>یک سفارش در انتظار بررسی دارید</Text><Text style={s.muted}>تا مشخص شدن وضعیت از خرید مجدد خودداری کنید.</Text><TouchableOpacity style={s.secondary} disabled={working} onPress={()=>check(pendingId)}><Text style={s.secondaryText}>پیگیری سفارش معلق</Text></TouchableOpacity></View>}
  <View style={s.card}><Text style={s.label}>اپراتور</Text><View style={s.wrap}>{operators.map(o=><TouchableOpacity key={o.id} style={[s.chip,operator===o.id&&s.chipOn]} onPress={()=>setOperator(o.id)}><Text style={[s.chipText,operator===o.id&&s.chipTextOn]}>{o.label}</Text></TouchableOpacity>)}</View><Text style={s.label}>نوع سیم‌کارت</Text><View style={s.wrap}>{[['credit','اعتباری'],['permanent','دائمی']].map(([id,label])=><TouchableOpacity key={id} style={[s.chip,sim===id&&s.chipOn]} onPress={()=>setSim(id)}><Text style={[s.chipText,sim===id&&s.chipTextOn]}>{label}</Text></TouchableOpacity>)}</View><Text style={s.label}>شماره موبایل مقصد</Text><TextInput style={s.input} placeholder="۰۹۱۲۳۴۵۶۷۸۹" placeholderTextColor="#94a3b8" keyboardType="phone-pad" maxLength={15} value={mobile} onChangeText={setMobile}/></View>
  <View style={s.card}><View style={s.section}><Text style={s.sectionTitle}>بسته‌های قابل خرید</Text><TouchableOpacity onPress={load} accessibilityRole="button"><Ionicons name="refresh" size={22} color="#0891b2"/></TouchableOpacity></View><ScrollView horizontal showsHorizontalScrollIndicator={false} style={{marginBottom:12}} contentContainerStyle={{gap:8}}>{[['all','همه'],...Object.entries(durations)].map(([id,label])=><TouchableOpacity key={id} onPress={()=>setDuration(id)} style={[s.smallChip,duration===id&&s.smallChipOn]}><Text style={[s.smallText,duration===id&&s.chipTextOn]}>{label}</Text></TouchableOpacity>)}</ScrollView>
  {loading?<ActivityIndicator size="large" color="#0891b2"/>:visible.length===0?<Text style={s.empty}>در این دسته بسته فعالی وجود ندارد. بسته‌ها باید ابتدا در پنل مدیریت از آینکس دریافت و فعال شوند.</Text>:visible.map(p=><TouchableOpacity key={p.id} style={[s.package,selected===p.id&&s.packageSelected]} onPress={()=>setSelected(p.id)}><View style={s.packageIcon}><Ionicons name="cellular" size={21} color="#0891b2"/></View><View style={{flex:1}}><Text style={s.packageTitle}>{p.title}</Text><Text style={s.muted}>{durations[p.internet_type]||p.internet_type} · {p.sim_type==='credit'?'اعتباری':'دائمی'}</Text></View><View style={{alignItems:'flex-end'}}><Text style={s.price}>{fmt(p.amount)}</Text><Text style={s.muted}>تومان</Text></View><Ionicons name={selected===p.id?'checkmark-circle':'ellipse-outline'} size={21} color={selected===p.id?'#0891b2':'#94a3b8'}/></TouchableOpacity>)}
  </View>
  {choice&&<View style={s.card}><Text style={s.sectionTitle}>خلاصه خرید</Text><View style={s.line}><Text style={s.muted}>قیمت بسته</Text><Text style={s.value}>{fmt(choice.amount)} تومان</Text></View><View style={s.line}><Text style={s.muted}>کارمزد خدمت</Text><Text style={s.value}>{fee?`${fmt(fee.fee_amount)} تومان`:'در حال محاسبه'}</Text></View><View style={[s.line,{borderBottomWidth:0}]}><Text style={s.total}>جمع پرداختی از کیف پول</Text><Text style={s.total}>{fee?fmt(fee.total_amount):'—'} تومان</Text></View></View>}
  <TouchableOpacity style={[s.buy,(!choice||!fee||working||!!pendingId)&&s.disabled]} disabled={!choice||!fee||working||!!pendingId} onPress={buy}>{working?<ActivityIndicator color="white"/>:<><Ionicons name="shield-checkmark" size={20} color="white"/><Text style={s.buyText}>خرید امن از کیف پول</Text></>}</TouchableOpacity>
  {receipt&&<View style={s.card}><Text style={s.sectionTitle}>رسید سفارش</Text><Text style={[s.receiptStatus,{color:receipt.status==='success'?'#047857':receipt.status==='failed'?'#b91c1c':'#b45309'}]}>{receipt.status==='success'?'خرید موفق':receipt.status==='failed'?'ناموفق؛ مبلغ مسترد شد':'در انتظار تأیید آینکس'}</Text><Text style={s.muted}>{receipt.product_title}</Text><Text style={s.muted}>شناسه سفارش: {receipt.order_id}</Text><Text style={s.muted}>کد پیگیری: {receipt.tracking_code||'در انتظار'}</Text><Text style={s.value}>مبلغ نهایی: {fmt(receipt.total_amount)} تومان</Text>{receipt.status==='pending'&&<TouchableOpacity style={s.secondary} disabled={working} onPress={()=>check(receipt.client_request_id)}><Text style={s.secondaryText}>استعلام وضعیت</Text></TouchableOpacity>}</View>}
 </ScrollView></SafeAreaView>;
}
const s=StyleSheet.create({screen:{flex:1,backgroundColor:appColors.background},top:{height:64,flexDirection:'row-reverse',alignItems:'center',justifyContent:'space-between',paddingHorizontal:18},back:{width:42,height:42,justifyContent:'center',alignItems:'center',backgroundColor:'white',borderRadius:13},heading:{fontFamily:'VazirmatnBold',fontSize:18,color:'#153a4b'},content:{paddingHorizontal:17,paddingBottom:60,gap:15},hero:{backgroundColor:'#0e7490',borderRadius:24,padding:23,alignItems:'flex-end',gap:9},heroTitle:{fontFamily:'VazirmatnBold',fontSize:20,color:'white'},heroSub:{fontFamily:'Vazirmatn',color:'#e0f7fa',fontSize:12,textAlign:'right',lineHeight:23},card:{padding:18,borderRadius:20,backgroundColor:'white',borderWidth:1,borderColor:'#e2e8f0',gap:12},label:{textAlign:'right',fontFamily:'VazirmatnBold',color:'#334155',fontSize:12,marginTop:3},wrap:{flexDirection:'row-reverse',flexWrap:'wrap',gap:8},chip:{paddingVertical:10,paddingHorizontal:15,borderRadius:12,backgroundColor:'#f1f5f9',borderWidth:1,borderColor:'#e2e8f0'},chipOn:{backgroundColor:'#0e7490',borderColor:'#0e7490'},chipText:{fontFamily:'VazirmatnBold',fontSize:11,color:'#475569'},chipTextOn:{color:'white'},input:{borderWidth:1,borderColor:'#cbd5e1',backgroundColor:'#f8fafc',borderRadius:12,height:50,paddingHorizontal:14,textAlign:'right',fontFamily:'Vazirmatn',color:'#0f172a'},section:{flexDirection:'row-reverse',alignItems:'center',justifyContent:'space-between'},sectionTitle:{fontFamily:'VazirmatnBold',fontSize:16,color:'#153a4b',textAlign:'right'},smallChip:{paddingHorizontal:13,paddingVertical:9,borderRadius:12,backgroundColor:'#f1f5f9'},smallChipOn:{backgroundColor:'#0e7490'},smallText:{fontFamily:'Vazirmatn',color:'#475569',fontSize:11},package:{flexDirection:'row-reverse',alignItems:'center',gap:9,padding:12,borderWidth:1,borderColor:'#e2e8f0',borderRadius:14},packageSelected:{borderColor:'#0891b2',backgroundColor:'#f0fdfa'},packageIcon:{width:40,height:40,backgroundColor:'#ecfeff',borderRadius:12,alignItems:'center',justifyContent:'center'},packageTitle:{fontFamily:'VazirmatnBold',fontSize:12,textAlign:'right',color:'#0f172a'},muted:{fontFamily:'Vazirmatn',fontSize:11,color:'#64748b',textAlign:'right',lineHeight:20},price:{fontFamily:'VazirmatnBold',fontSize:12,color:'#0e7490'},empty:{fontFamily:'Vazirmatn',fontSize:12,textAlign:'center',color:'#64748b',lineHeight:24,paddingVertical:22},line:{flexDirection:'row-reverse',justifyContent:'space-between',borderBottomWidth:1,borderBottomColor:'#f1f5f9',paddingVertical:9},value:{fontFamily:'VazirmatnBold',fontSize:12,color:'#1e293b'},total:{fontFamily:'VazirmatnBold',fontSize:13,color:'#047857'},buy:{backgroundColor:'#0e7490',borderRadius:16,minHeight:58,alignItems:'center',justifyContent:'center',flexDirection:'row-reverse',gap:10},buyText:{fontFamily:'VazirmatnBold',fontSize:15,color:'white'},disabled:{opacity:.5},alert:{backgroundColor:'#fffbeb',padding:16,borderRadius:17,borderWidth:1,borderColor:'#fde68a',gap:8},alertTitle:{fontFamily:'VazirmatnBold',color:'#92400e',textAlign:'right'},secondary:{backgroundColor:'#ecfeff',padding:11,borderRadius:12,alignItems:'center'},secondaryText:{fontFamily:'VazirmatnBold',fontSize:12,color:'#0e7490'},receiptStatus:{fontFamily:'VazirmatnBold',fontSize:15,textAlign:'right'}});
