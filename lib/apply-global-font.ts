import { Text, TextInput } from 'react-native';

let applied = false;

export const PROJECT_FONT_FAMILIES = {
  regular: 'Vazirmatn',
  medium: 'VazirmatnMedium',
  semiBold: 'VazirmatnSemiBold',
  bold: 'VazirmatnBold',
  extraBold: 'VazirmatnExtraBold',
  black: 'VazirmatnBlack',
  light: 'VazirmatnLight',
  extraLight: 'VazirmatnExtraLight',
  thin: 'VazirmatnThin',
} as const;

export function applyGlobalFont() {
  if (applied) return;
  applied = true;

  const TextAny = Text as any;
  const TextInputAny = TextInput as any;

  TextAny.defaultProps = TextAny.defaultProps || {};
  TextInputAny.defaultProps = TextInputAny.defaultProps || {};

  TextAny.defaultProps.style = [{ fontFamily: PROJECT_FONT_FAMILIES.regular }, TextAny.defaultProps.style];
  TextInputAny.defaultProps.style = [{ fontFamily: PROJECT_FONT_FAMILIES.regular }, TextInputAny.defaultProps.style];
}
