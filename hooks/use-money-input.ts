import { useRef, useState } from 'react';
import type { NativeSyntheticEvent, TextInputSelectionChangeEventData } from 'react-native';
import { formatMoneyInput, formatMoneyInputAtCursor } from '@/lib/money-input';

export function useMoneyInput(initialValue = '') {
  const initial = formatMoneyInput(initialValue);
  const [value, setValue] = useState(initial);
  const [selection, setSelection] = useState({ start: initial.length, end: initial.length });
  const selectionRef = useRef(selection);

  const onChangeText = (nextValue: string) => {
    const result = formatMoneyInputAtCursor(nextValue, selectionRef.current.end);
    setValue(result.value);
    const nextSelection = { start: result.cursor, end: result.cursor };
    selectionRef.current = nextSelection;
    setSelection(nextSelection);
  };

  const onSelectionChange = (event: NativeSyntheticEvent<TextInputSelectionChangeEventData>) => {
    selectionRef.current = event.nativeEvent.selection;
    setSelection(event.nativeEvent.selection);
  };

  const setFormattedValue = (nextValue: string | number) => {
    const formatted = formatMoneyInput(nextValue);
    setValue(formatted);
    const nextSelection = { start: formatted.length, end: formatted.length };
    selectionRef.current = nextSelection;
    setSelection(nextSelection);
  };

  return { value, selection, onChangeText, onSelectionChange, setFormattedValue };
}
