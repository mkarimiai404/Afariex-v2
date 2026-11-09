import { Ionicons } from '@expo/vector-icons';
import { Stack, useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Modal, ScrollView, StyleSheet, Text, TextInput, TouchableOpacity, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { TopupReceiptView } from '@/components/topup-receipt-view';
import { AppBottomNav } from '@/components/app-bottom-nav';
import { ApiTimeoutError, fetchJson, isApiResponseError } from '@/lib/api';
import { useAuth } from '@/lib/auth-context';
import { moneyInputWords, normalizeMoneyInput } from '@/lib/money-input';
import { releaseInFlight, tryAcquireInFlight } from '@/lib/inflight-lock';
import { useMoneyInput } from '@/hooks/use-money-input';
import { appColors } from '@/lib/theme';
import { topupOperatorLabel, TopupReceiptModel } from '@/lib/topup-receipt';
import { clearPendingTopup, loadPendingTopup, savePendingTopup } from '@/lib/topup-pending';

type Operator = { title: string; min_amount: number; max_amount: number };
type OperatorsResponse = { data?: { operators?: Record<string, Operator> } };

const FALLBACK_OPERATORS: Record<string, Operator> = {
    MCI: { title: 'همراه اول', min_amount: 20000, max_amount: 500000 },
    MTN: { title: 'ایرانسل', min_amount: 22000, max_amount: 1000000 },
    RTL: { title: 'رایتل', min_amount: 22000, max_amount: 500000 },
};

function normalizeDigits(input: string): string {
    const persianNumbers = [/۰/g, /۱/g, /۲/g, /۳/g, /۴/g, /۵/g, /۶/g, /۷/g, /۸/g, /۹/g];
    const arabicNumbers = [/٠/g, /١/g, /٢/g, /٣/g, /٤/g, /٥/g, /٦/g, /٧/g, /٨/g, /٩/g];
    let output = input;
    for (let i = 0; i < 10; i++) {
        output = output.replace(persianNumbers[i], String(i)).replace(arabicNumbers[i], String(i));
    }
    return output;
}

function normalizeIranMobile(input: string): string {
    const cleaned = normalizeDigits(input).replace(/\D/g, '');
    if (cleaned.startsWith('98') && cleaned.length === 12) {
        return '0' + cleaned.slice(2);
    }
    if (cleaned.startsWith('0098') && cleaned.length === 14) {
        return '0' + cleaned.slice(4);
    }
    return cleaned;
}

function detectOperator(phone: string): string | null {
    const clean = normalizeIranMobile(phone);
    if (!clean.startsWith('09') || clean.length !== 11) return null;
    const prefix = clean.slice(0, 4);
    if (/^092[0-3]$/.test(prefix) || prefix === '0926') return 'RTL';
    if (/^091[0-9]$/.test(prefix) || /^099[0-4]$/.test(prefix)) return 'MCI';
    if (/^090[0-5]$/.test(prefix) || /^093[0-9]$/.test(prefix) || prefix === '0941') return 'MTN';
    return null;
}

async function fetchWalletBalance(userId: number, secret: string): Promise<number | null> {
    try {
        const response = await fetchJson<any>('dashboard.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                user_id: String(userId),
                id: String(userId),
                api_token: secret,
                token: secret,
                user_token: secret,
            }).toString(),
        });
        const raw = response?.balance ?? response?.user?.balance ?? response?.data?.balance ?? response?.result?.balance;
        if (raw === undefined || raw === null) return null;
        const clean = String(raw).replace(/[٬,\s]/g, '');
        const val = Number(clean);
        return isNaN(val) ? null : val;
    } catch {
        return null;
    }
}

function createClientRequestId(): string {
    const buffer = new Uint8Array(16);
    crypto.getRandomValues(buffer);
    buffer[6] = (buffer[6] & 0x0f) | 0x40;
    buffer[8] = (buffer[8] & 0x3f) | 0x80;
    const hex = [...buffer].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export default function TopupScreen() {
    const router = useRouter();
    const { user, isInitialized, isAuthenticated } = useAuth();
    const userId = user?.id ? Number(user.id) : 0;
    const secret = user?.api_token || user?.token || '';

    const [operators, setOperators] = useState<Record<string, Operator>>(FALLBACK_OPERATORS);
    const [operator, setOperator] = useState<string>('MCI');
    const [chargeType, setChargeType] = useState<ChargeType>('normal');
    const [mobile, setMobile] = useState<string>('');
    const [walletBalance, setWalletBalance] = useState<number | null>(null);
    const [balanceLoading, setBalanceLoading] = useState<boolean>(false);

    const [feePreview, setFeePreview] = useState<FeePreview | null>(null);
    const [feeLoading, setFeeLoading] = useState<boolean>(false);
    const [feeError, setFeeError] = useState<boolean>(false);

    const amountInput = useMoneyInput({ initialValue: '', min: 1000, max: 2000000 });
    const numericAmount = Number(amountInput.rawValue) || 0;

    const [step, setStep] = useState<'form' | 'confirm' | 'result'>('form');
    const [loading, setLoading] = useState<boolean>(false);
    const [pendingRestoreLoading, setPendingRestoreLoading] = useState<boolean>(true);
    const [submitting, setSubmitting] = useState<boolean>(false);
    const [lastResult, setLastResult] = useState<TopupReceiptModel | null>(null);
    const [pollingRestart, setPollingRestart] = useState<number>(0);

    const clientRequestId = useRef<string | null>(null);
    const submitInFlight = useRef<boolean>(false);
    const resultOwnerId = useRef<number>(0);
    const operatorManuallySelected = useRef<boolean>(false);
    const statusRequestInFlight = useRef<boolean>(false);
    const pollingWindow = useRef<{ startTime: number; attempts: number } | null>(null);

    useEffect(() => {
        if (isInitialized && !isAuthenticated) {
            router.replace('/login');
        }
    }, [isInitialized, isAuthenticated]);

    useEffect(() => {
        let active = true;
        if (!userId || !secret) {
            setPendingRestoreLoading(false);
            return;
        }
        setPendingRestoreLoading(true);
        loadPendingTopup(userId).then((pending) => {
            if (!active) return;
            if (pending) {
                resultOwnerId.current = userId;
                clientRequestId.current = pending.clientRequestId || null;
                setLastResult(pending);
                setStep('result');
            }
            setPendingRestoreLoading(false);
        }).catch(() => {
            if (active) setPendingRestoreLoading(false);
        });
        return () => { active = false; };
    }, [userId, secret]);

    useEffect(() => {
        if (!lastResult) return;
        if (lastResult.status === 'pending') {
            if (userId) savePendingTopup(userId, lastResult).catch(() => {});
        } else {
            if (userId) clearPendingTopup(userId).catch(() => {});
        }
    }, [lastResult, userId]);

    // Fetch Operators
    useEffect(() => {
        if (!secret) return;
        fetchJson<OperatorsResponse>('topup-operators.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${secret}` },
            body: JSON.stringify({ api_token: secret }),
        }).then((res) => {
            if (res?.data?.operators) {
                setOperators(res.data.operators);
            }
        }).catch(() => {});
    }, [secret]);

    // Fetch Balance
    useEffect(() => {
        if (!userId || !secret) return;
        setBalanceLoading(true);
        fetchWalletBalance(userId, secret).then((bal) => {
            if (bal !== null) setWalletBalance(bal);
        }).finally(() => setBalanceLoading(false));
    }, [userId, secret, lastResult]);

    // Auto-detect operator
    useEffect(() => {
        if (operatorManuallySelected.current) return;
        const detected = detectOperator(mobile);
        if (detected && operators[detected]) {
            setOperator(detected);
        }
    }, [mobile, operators]);

    // Fee preview
    useEffect(() => {
        if (numericAmount <= 0 || !secret) {
            setFeePreview(null);
            setFeeError(false);
            return;
        }
        setFeeLoading(true);
        setFeeError(false);
        fetchJson<any>('service-fee-preview.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                api_token: secret,
                service_code: 'mobile_recharge',
                amount: String(numericAmount),
            }).toString(),
        }).then((res) => {
            const feeAmt = Number(res?.data?.fee?.fee_amount || res?.data?.fee_amount || 0);
            const total = Number(res?.data?.fee?.total_amount || res?.data?.total_amount || (numericAmount + feeAmt));
            if (total < numericAmount) throw new Error('تعداد مبلغ کل نامعتبر است');
            setFeePreview({ fee_amount: feeAmt, total_amount: total });
        }).catch(() => {
            setFeeError(true);
            setFeePreview({ fee_amount: 0, total_amount: numericAmount });
        }).finally(() => setFeeLoading(false));
    }, [numericAmount, secret]);

    const currentOperatorMeta = operators[operator] || FALLBACK_OPERATORS[operator] || { title: operator, min_amount: 1000, max_amount: 2000000 };
    const totalCost = feePreview?.total_amount ?? numericAmount;
    const balanceSufficient = walletBalance !== null ? walletBalance >= totalCost : true;

    const requestTopupStatus = async (txId?: number | null, reqId?: string | null, provTxId?: string | null, ordId?: string | null) => {
        return fetchJson<any>('topup-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${secret}` },
            body: JSON.stringify({
                api_token: secret,
                transaction_id: txId || null,
                client_request_id: reqId || null,
                trans_id: provTxId || null,
                order_id: ordId || null,
            }),
        });
    };

    const submit = async () => {
        if (!secret || !userId || submitting || !tryAcquireInFlight(submitInFlight)) return;
        setSubmitting(true);
        resultOwnerId.current = userId;
        clientRequestId.current ??= createClientRequestId();
        const requestId = clientRequestId.current;

        const cleanMobile = normalizeIranMobile(mobile);

        const pendingReceipt: TopupReceiptModel = {
            transactionId: `pending_${requestId}`,
            clientRequestId: requestId,
            mobile: cleanMobile,
            operator,
            operatorLabel: topupOperatorLabel(operator),
            amount: numericAmount,
            commissionAmount: feePreview?.fee_amount ?? 0,
            totalAmount: totalCost,
            chargeType,
            status: 'pending',
            createdAt: new Date().toISOString(),
            message: 'درخواست در حال پردازش است؛ لطفاً منتظر بمانید...',
        };

        try {
            await savePendingTopup(userId, pendingReceipt).catch(() => {});

            console.log("=== SENDING TOPUP ===", {
                endpoint: 'topup-create.php',
                body: { api_token: secret, phone: cleanMobile, operator, amount: numericAmount, charge_type: chargeType, client_request_id: requestId }
            });

            const response = await fetchJson<any>('topup-create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${secret}` },
                body: JSON.stringify({
                    api_token: secret,
                    phone: cleanMobile,
                    operator,
                    amount: numericAmount,
                    charge_type: chargeType,
                    client_request_id: requestId,
                }),
            });

            console.log("=== TOPUP RESPONSE ===", response);

            if (response?.status === 'online_payment_required') {
                const onlineData = response?.data || response;
                const paymentUrl = onlineData?.url;
                const provTxId = String(onlineData?.trans_id || '');
                if (paymentUrl) {
                    const parsedUrl = new URL(paymentUrl);
                    if (parsedUrl.hostname.endsWith('inax.ir')) {
                        await WebBrowser.openBrowserAsync(paymentUrl);
                    }
                }
                const onlineReceipt: TopupReceiptModel = {
                    ...pendingReceipt,
                    transactionId: String(onlineData?.transaction_id || pendingReceipt.transactionId),
                    providerTransactionId: provTxId,
                    trackingCode: provTxId,
                    message: response?.message || 'لطفاً پرداخت آنلاین را تکمیل کنید.',
                };
                setLastResult(onlineReceipt);
                setStep('result');
                return;
            }

            const receipt = toReceipt(response, cleanMobile, operator, numericAmount, requestId, chargeType, '', pendingReceipt.createdAt);
            clearPendingTopup(userId).catch(() => {});
            setLastResult(receipt);
            setStep('result');

            const newBalance = Number(response?.data?.remaining_balance ?? response?.data?.balance);
            if (!isNaN(newBalance)) setWalletBalance(newBalance);

        } catch (error: any) {
            console.error("=== TOPUP ERROR ===", { message: error?.message, status: error?.status, data: error?.responseData, error });
            const payload = isApiResponseError(error) && error.responseData && !Array.isArray(error.responseData) ? error.responseData : null;

            if (isApiResponseError(error) && error.status === 409) {
                const existingId = Number(payload?.data?.transaction_id || 0);
                if (existingId) {
                    try {
                        const statusRes = await requestTopupStatus(existingId, requestId, null, null);
                        const receipt = toReceipt(statusRes, cleanMobile, operator, numericAmount, requestId, chargeType, String(existingId), pendingReceipt.createdAt);
                        clearPendingTopup(userId).catch(() => {});
                        setLastResult(receipt);
                        setStep('result');
                        return;
                    } catch (statusErr) {
                        console.error("Status check after 409 failed:", statusErr);
                    }
                }
            }

            if (payload?.status) {
                const receipt = toReceipt(payload, cleanMobile, operator, numericAmount, requestId, chargeType, '', pendingReceipt.createdAt);
                setLastResult(receipt);
                setStep('result');
                return;
            }

            if (!isApiResponseError(error) || error.status >= 500) {
                setLastResult(pendingReceipt);
                setStep('result');
                Alert.alert('وضعیت نامشخص', 'ارتباط با سرور برقرار شد اما پاسخ نهایی دریافت نشد. وضعیت تراکنش به صورت خودکار پیگیری خواهد شد.');
                return;
            }

            clearPendingTopup(userId).catch(() => {});
            Alert.alert('خطا در خرید شارژ', payload?.message || error?.message || 'عملیات ناموفق بود.');
        } finally {
            setSubmitting(false);
            releaseInFlight(submitInFlight);
        }
    };

    const checkStatus = async () => {
        if (!secret || !lastResult || statusRequestInFlight.current) return;
        statusRequestInFlight.current = true;
        try {
            const res = await requestTopupStatus(
                lastResult.transactionId ? Number(lastResult.transactionId) : null,
                lastResult.clientRequestId || clientRequestId.current || null,
                lastResult.providerTransactionId || null,
                lastResult.orderId || null
            );
            const receipt = toReceipt(res, lastResult.mobile, lastResult.operator, lastResult.amount, lastResult.clientRequestId, lastResult.chargeType, lastResult.transactionId, lastResult.createdAt);
            setLastResult(receipt);
            const bal = Number(res?.data?.remaining_balance ?? res?.data?.balance);
            if (!isNaN(bal)) setWalletBalance(bal);
        } catch (err: any) {
            Alert.alert('پیگیری وضعیت', err?.message || 'تراکنش هنوز در حال بررسی است یا یافت نشد.');
        } finally {
            statusRequestInFlight.current = false;
        }
    };

    // Polling effect for pending transactions
    useEffect(() => {
        if (step !== 'result' || !lastResult || lastResult.status !== 'pending' || !secret) return;

        const delays = [3000, 5000, 10000, 15000, 20000];
        let attempt = 0;
        let active = true;

        const poll = async () => {
            if (!active) return;
            try {
                const res = await requestTopupStatus(
                    lastResult.transactionId ? Number(lastResult.transactionId) : null,
                    lastResult.clientRequestId || clientRequestId.current || null,
                    lastResult.providerTransactionId || null,
                    lastResult.orderId || null
                );
                if (!active) return;
                const status = res?.status;
                if (status && status !== 'pending') {
                    const receipt = toReceipt(res, lastResult.mobile, lastResult.operator, lastResult.amount, lastResult.clientRequestId, lastResult.chargeType, lastResult.transactionId, lastResult.createdAt);
                    setLastResult(receipt);
                    return;
                }
            } catch {}

            attempt++;
            if (attempt < delays.length && active) {
                setTimeout(poll, delays[attempt]);
            }
        };

        const timer = setTimeout(poll, delays[0]);
        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [step, lastResult?.status, pollingRestart, secret]);

    if (!isInitialized || loading || pendingRestoreLoading) {
        return (
            <SafeAreaView style={styles.centerContainer}>
                <ActivityIndicator size="large" color={appColors.primary} />
            </SafeAreaView>
        );
    }

    return (
        <SafeAreaView style={styles.container} edges={['top']}>
            <Stack.Screen options={{ headerShown: false }} />
            <View style={styles.header}>
                <TouchableOpacity onPress={() => router.back()} style={styles.backButton}>
                    <Ionicons name="arrow-forward" size={24} color={appColors.text} />
                </TouchableOpacity>
                <Text style={styles.headerTitle}>خرید شارژ مستقیم</Text>
            </View>

            {step === 'form' && (
                <ScrollView contentContainerStyle={styles.scrollContent}>
                    <View style={styles.card}>
                        <Text style={styles.label}>شماره موبایل گیرنده</Text>
                        <TextInput
                            style={styles.input}
                            placeholder="مثال: 09123456789"
                            placeholderTextColor="#999"
                            keyboardType="phone-pad"
                            maxLength={11}
                            value={mobile}
                            onChangeText={setMobile}
                        />

                        <Text style={styles.label}>انتخاب اپراتور</Text>
                        <View style={styles.operatorRow}>
                            {Object.entries(operators).map(([key, meta]) => (
                                <TouchableOpacity
                                    key={key}
                                    style={[styles.operatorButton, operator === key && styles.operatorActive]}
                                    onPress={() => {
                                        operatorManuallySelected.current = true;
                                        setOperator(key);
                                    }}
                                >
                                    <Text style={[styles.operatorText, operator === key && styles.operatorTextActive]}>{meta.title}</Text>
                                </TouchableOpacity>
                            ))}
                        </View>

                        <Text style={styles.label}>نوع شارژ</Text>
                        <View style={styles.operatorRow}>
                            <TouchableOpacity
                                style={[styles.operatorButton, chargeType === 'normal' && styles.operatorActive]}
                                onPress={() => setChargeType('normal')}
                            >
                                <Text style={[styles.operatorText, chargeType === 'normal' && styles.operatorTextActive]}>عادی</Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                                style={[styles.operatorButton, chargeType === 'amazing' && styles.operatorActive]}
                                onPress={() => setChargeType('amazing')}
                            >
                                <Text style={[styles.operatorText, chargeType === 'amazing' && styles.operatorTextActive]}>شگفت‌انگیز</Text>
                            </TouchableOpacity>
                        </View>

                        <Text style={styles.label}>مبلغ شارژ (تومان)</Text>
                        <TextInput
                            style={styles.input}
                            placeholder={`مبلغ بین ${currentOperatorMeta.min_amount} تا ${currentOperatorMeta.max_amount}`}
                            placeholderTextColor="#999"
                            keyboardType="numeric"
                            value={amountInput.value}
                            onChangeText={amountInput.onChangeText}
                        />
                        {numericAmount > 0 && <Text style={styles.wordsText}>{moneyInputWords(numericAmount)}</Text>}

                        <TouchableOpacity
                            style={[styles.submitButton, (!normalizeIranMobile(mobile) || numericAmount <= 0) && styles.disabledButton]}
                            disabled={!normalizeIranMobile(mobile) || numericAmount <= 0}
                            onPress={() => setStep('confirm')}
                        >
                            <Text style={styles.submitButtonText}>ادامه و تأیید خرید</Text>
                        </TouchableOpacity>
                    </View>
                </ScrollView>
            )}

            {step === 'result' && lastResult && (
                <ScrollView contentContainerStyle={styles.scrollContent}>
                    <TopupReceiptView receipt={lastResult} />
                    {lastResult.status === 'pending' && (
                        <TouchableOpacity style={styles.submitButton} onPress={checkStatus}>
                            <Text style={styles.submitButtonText}>بررسی وضعیت تراکنش</Text>
                        </TouchableOpacity>
                    )}
                    <TouchableOpacity
                        style={[styles.submitButton, { backgroundColor: '#666', marginTop: 12 }]}
                        onPress={() => {
                            clientRequestId.current = null;
                            setLastResult(null);
                            setMobile('');
                            setStep('form');
                        }}
                    >
                        <Text style={styles.submitButtonText}>خرید شارژ جدید</Text>
                    </TouchableOpacity>
                </ScrollView>
            )}

            <Modal visible={step === 'confirm'} transparent animationType="fade">
                <View style={styles.modalOverlay}>
                    <View style={styles.modalContent}>
                        <Text style={styles.modalTitle}>تأیید اطلاعات خرید</Text>
                        <View style={styles.summaryRow}>
                            <Text style={styles.summaryLabel}>موبایل:</Text>
                            <Text style={styles.summaryValue}>{mobile}</Text>
                        </View>
                        <View style={styles.summaryRow}>
                            <Text style={styles.summaryLabel}>اپراتور:</Text>
                            <Text style={styles.summaryValue}>{currentOperatorMeta.title}</Text>
                        </View>
                        <View style={styles.summaryRow}>
                            <Text style={styles.summaryLabel}>مبلغ:</Text>
                            <Text style={styles.summaryValue}>{numericAmount.toLocaleString()} تومان</Text>
                        </View>
                        {feePreview && feePreview.fee_amount > 0 && (
                            <View style={styles.summaryRow}>
                                <Text style={styles.summaryLabel}>کارمزد:</Text>
                                <Text style={styles.summaryValue}>{feePreview.fee_amount.toLocaleString()} تومان</Text>
                            </View>
                        )}
                        <View style={styles.summaryRow}>
                            <Text style={styles.summaryLabel}>مجموع قابل پرداخت:</Text>
                            <Text style={[styles.summaryValue, { fontWeight: 'bold', color: appColors.primary }]}>{totalCost.toLocaleString()} تومان</Text>
                        </View>
                        <View style={styles.summaryRow}>
                            <Text style={styles.summaryLabel}>موجودی کیف پول:</Text>
                            <Text style={styles.summaryValue}>{walletBalance !== null ? `${walletBalance.toLocaleString()} تومان` : 'نامشخص'}</Text>
                        </View>

                        {!balanceSufficient && (
                            <Text style={styles.errorText}>موجودی کیف پول شما برای این خرید کافی نیست.</Text>
                        )}

                        <View style={styles.modalActions}>
                            <TouchableOpacity style={styles.modalCancel} onPress={() => setStep('form')}>
                                <Text style={styles.modalCancelText}>ویرایش</Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                                style={[styles.modalConfirm, (!balanceSufficient || submitting) && styles.disabledButton]}
                                disabled={!balanceSufficient || submitting}
                                onPress={() => {
                                    setStep('result');
                                    submit();
                                }}
                            >
                                {submitting ? <ActivityIndicator color="#fff" /> : <Text style={styles.modalConfirmText}>تأیید و پرداخت</Text>}
                            </TouchableOpacity>
                        </View>
                    </View>
                </View>
            </Modal>

            <AppBottomNav />
        </SafeAreaView>
    );
}

function toReceipt(payload: any, mobile: string, operator: string, amount: number, fallbackClientRequestId?: string, fallbackChargeType = 'normal', fallbackTransactionId = '', fallbackCreatedAt?: string): TopupReceiptModel {
    const rawStatus = payload?.status;
    const status = rawStatus === 'succeeded' ? 'success' : rawStatus === 'success' || rawStatus === 'failed' || rawStatus === 'pending' ? rawStatus : 'pending';
    const data = payload?.data || {};
    const reference = data.ref_code ?? data.trans_id ?? data.ref_id;
    const commissionAmount = Number(data.commission_amount || 0);
    const receiptAmount = Number(data.amount || amount);
    return {
        transactionId: String(data.transaction_id || fallbackTransactionId),
        clientRequestId: String(data.client_request_id || fallbackClientRequestId || '') || undefined,
        providerTransactionId: data.trans_id ? String(data.trans_id) : undefined,
        mobile: String(data.mobile || data.phone || mobile),
        operator: String(data.operator || operator),
        operatorLabel: topupOperatorLabel(operator),
        amount: receiptAmount,
        commissionAmount,
        totalAmount: Number(data.total_amount ?? receiptAmount + commissionAmount),
        chargeType: data.charge_type === 'amazing' ? 'amazing' : data.charge_type === 'normal' ? 'normal' : fallbackChargeType,
        status,
        refCode: reference ? String(reference) : undefined,
        trackingCode: data.tracking_code ? String(data.tracking_code) : undefined,
        orderId: data.order_id ? String(data.order_id) : undefined,
        message: String(payload?.message || ''),
        createdAt: data.created_at ? String(data.created_at) : fallbackCreatedAt,
    };
}

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: '#f8f9fa' },
    centerContainer: { flex: 1, justifyContent: 'center', alignItems: 'center' },
    header: { flexDirection: 'row-reverse', alignItems: 'center', padding: 16, backgroundColor: '#fff', borderBottomWidth: 1, borderBottomColor: '#eee' },
    backButton: { padding: 4 },
    headerTitle: { flex: 1, textAlign: 'right', fontSize: 18, fontWeight: 'bold', color: '#333', marginRight: 12 },
    scrollContent: { padding: 16, paddingBottom: 100 },
    card: { backgroundColor: '#fff', borderRadius: 12, padding: 16, shadowColor: '#000', shadowOpacity: 0.05, shadowRadius: 5, elevation: 2 },
    label: { textAlign: 'right', fontSize: 14, fontWeight: '600', color: '#444', marginBottom: 8, marginTop: 12 },
    input: { borderWidth: 1, borderColor: '#ddd', borderRadius: 8, padding: 12, textAlign: 'right', fontSize: 16, backgroundColor: '#fafafa', color: '#333' },
    wordsText: { textAlign: 'right', fontSize: 12, color: '#666', marginTop: 4 },
    operatorRow: { flexDirection: 'row-reverse', gap: 8, marginTop: 4 },
    operatorButton: { flex: 1, borderWidth: 1, borderColor: '#ddd', borderRadius: 8, padding: 12, alignItems: 'center', backgroundColor: '#fafafa' },
    operatorActive: { borderColor: appColors.primary, backgroundColor: '#eef2ff' },
    operatorText: { fontSize: 14, color: '#444' },
    operatorTextActive: { color: appColors.primary, fontWeight: 'bold' },
    submitButton: { backgroundColor: appColors.primary, borderRadius: 8, padding: 16, alignItems: 'center', marginTop: 24 },
    disabledButton: { opacity: 0.6 },
    submitButtonText: { color: '#fff', fontSize: 16, fontWeight: 'bold' },
    modalOverlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'center', alignItems: 'center', padding: 16 },
    modalContent: { backgroundColor: '#fff', borderRadius: 12, padding: 20, width: '100%', maxWidth: 400 },
    modalTitle: { textAlign: 'right', fontSize: 18, fontWeight: 'bold', marginBottom: 16, color: '#333' },
    summaryRow: { flexDirection: 'row-reverse', justifyContent: 'space-between', paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: '#f0f0f0' },
    summaryLabel: { fontSize: 14, color: '#666', textAlign: 'right' },
    summaryValue: { fontSize: 14, color: '#333', textAlign: 'left', fontWeight: '500' },
    errorText: { color: '#e53e3e', fontSize: 13, textAlign: 'right', marginTop: 12 },
    modalActions: { flexDirection: 'row-reverse', gap: 12, marginTop: 20 },
    modalCancel: { flex: 1, padding: 12, borderRadius: 8, borderWidth: 1, borderColor: '#ddd', alignItems: 'center' },
    modalCancelText: { color: '#666', fontWeight: 'bold' },
    modalConfirm: { flex: 1, padding: 12, borderRadius: 8, backgroundColor: appColors.primary, alignItems: 'center' },
    modalConfirmText: { color: '#fff', fontWeight: 'bold' },
});