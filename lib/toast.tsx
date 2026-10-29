import { Dimensions } from 'react-native';
import Toast, { BaseToast, ErrorToast, ToastConfig } from 'react-native-toast-message';

const toastTopOffset = Math.max(120, Math.round(Dimensions.get('window').height * 0.5 - 70));

export const toastConfig: ToastConfig = {
  success: (props) => (
    <BaseToast
      {...props}
      style={{
        borderLeftColor: '#21b08c',
        borderLeftWidth: 6,
        borderRadius: 12,
        alignSelf: 'center',
        width: '92%',
      }}
      contentContainerStyle={{ paddingHorizontal: 14, alignItems: 'center' }}
      text1Style={{ fontSize: 14, fontWeight: '700', textAlign: 'center', writingDirection: 'rtl' }}
      text2Style={{ fontSize: 13, textAlign: 'center', writingDirection: 'rtl' }}
    />
  ),
  error: (props) => (
    <ErrorToast
      {...props}
      style={{
        borderLeftColor: '#e74c3c',
        borderLeftWidth: 6,
        borderRadius: 12,
        alignSelf: 'center',
        width: '92%',
      }}
      contentContainerStyle={{ paddingHorizontal: 14, alignItems: 'center' }}
      text1Style={{ fontSize: 14, fontWeight: '700', textAlign: 'center', writingDirection: 'rtl' }}
      text2Style={{ fontSize: 13, textAlign: 'center', writingDirection: 'rtl' }}
    />
  ),
};

export const showSuccess = (title: string, message?: string) => {
  Toast.show({
    type: 'success',
    text1: title,
    text2: message,
    position: 'top',
    topOffset: toastTopOffset,
    visibilityTime: 2500,
  });
};

export const showError = (title: string, message?: string) => {
  Toast.show({
    type: 'error',
    text1: title,
    text2: message,
    position: 'top',
    topOffset: toastTopOffset,
    visibilityTime: 3000,
  });
};
