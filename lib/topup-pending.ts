import AsyncStorage from '@react-native-async-storage/async-storage';
import { topupOperatorLabel, TopupReceiptModel } from '@/lib/topup-receipt';

const STORAGE_PREFIX = 'afariex_pending_topup_';

const storageKey = (userId: string) => `${STORAGE_PREFIX}${encodeURIComponent(userId)}`;

const maskMobile = (mobile: string) => mobile.replace(/^(\d{4})\d+(\d{4})$/, '$1****$2');

export const savePendingTopup = async (userId: string, receipt: TopupReceiptModel) => {
  if (!receipt.clientRequestId) return;
  const pending = {
    clientRequestId: receipt.clientRequestId,
    transactionId: receipt.transactionId || '',
    orderId: receipt.orderId || '',
    createdAt: receipt.createdAt || new Date().toISOString(),
    status: 'pending',
    mobile: maskMobile(receipt.mobile),
    operator: receipt.operator,
    operatorLabel: receipt.operatorLabel,
    chargeType: receipt.chargeType,
    amount: receipt.amount,
    commissionAmount: receipt.commissionAmount,
    totalAmount: receipt.totalAmount,
  };
  await AsyncStorage.setItem(storageKey(userId), JSON.stringify(pending));
};

export const loadPendingTopup = async (userId: string): Promise<TopupReceiptModel | null> => {
  const stored = await AsyncStorage.getItem(storageKey(userId));
  if (!stored) return null;
  try {
    const data = JSON.parse(stored) as Record<string, unknown>;
    if (data.status !== 'pending' || typeof data.clientRequestId !== 'string' || typeof data.amount !== 'number') return null;
    const operator = String(data.operator || '');
    return {
      transactionId: String(data.transactionId || ''),
      clientRequestId: data.clientRequestId,
      orderId: typeof data.orderId === 'string' ? data.orderId : undefined,
      createdAt: typeof data.createdAt === 'string' ? data.createdAt : undefined,
      mobile: String(data.mobile || ''),
      operator,
      operatorLabel: String(data.operatorLabel || topupOperatorLabel(operator)),
      chargeType: data.chargeType === 'amazing' ? 'amazing' : 'normal',
      amount: data.amount,
      commissionAmount: typeof data.commissionAmount === 'number' ? data.commissionAmount : 0,
      totalAmount: typeof data.totalAmount === 'number' ? data.totalAmount : data.amount,
      status: 'pending',
      message: 'وضعیت تراکنش در حال پیگیری است.',
    };
  } catch {
    return null;
  }
};

export const clearPendingTopup = (userId: string) => AsyncStorage.removeItem(storageKey(userId));
