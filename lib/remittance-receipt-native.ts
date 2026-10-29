import { Asset } from 'expo-asset';
import * as Print from 'expo-print';

import { buildRemittanceReceiptHtml, RemittanceReceiptModel } from '@/lib/remittance-receipt';

export const createRemittanceReceiptPdf = async (receipt: RemittanceReceiptModel) => {
  const [regularFont, boldFont, logo] = await Promise.all([
    Asset.fromModule(require('@/assets/fonts/Vazirmatn-Regular.ttf')).downloadAsync(),
    Asset.fromModule(require('@/assets/fonts/Vazirmatn-Bold.ttf')).downloadAsync(),
    Asset.fromModule(require('@/assets/images/logo.png')).downloadAsync(),
  ]);

  const html = buildRemittanceReceiptHtml({
    ...receipt,
    fontRegularUri: regularFont.localUri || regularFont.uri,
    fontBoldUri: boldFont.localUri || boldFont.uri,
    logoUri: logo.localUri || logo.uri,
  });

  return Print.printToFileAsync({ html, width: 595, height: 842 });
};
