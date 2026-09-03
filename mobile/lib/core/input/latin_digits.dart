import 'package:flutter/services.dart';

/// Converts Eastern Arabic / Persian digits to Latin (0-9).
abstract final class LatinDigits {
  static const _map = <int, int>{
    // Eastern Arabic (Arabic-Indic)
    0x0660: 0x30, // ٠ → 0
    0x0661: 0x31, // ١ → 1
    0x0662: 0x32, // ٢ → 2
    0x0663: 0x33, // ٣ → 3
    0x0664: 0x34, // ٤ → 4
    0x0665: 0x35, // ٥ → 5
    0x0666: 0x36, // ٦ → 6
    0x0667: 0x37, // ٧ → 7
    0x0668: 0x38, // ٨ → 8
    0x0669: 0x39, // ٩ → 9
    // Persian / Urdu (Extended Arabic-Indic)
    0x06F0: 0x30, // ۰ → 0
    0x06F1: 0x31, // ۱ → 1
    0x06F2: 0x32, // ۲ → 2
    0x06F3: 0x33, // ۳ → 3
    0x06F4: 0x34, // ۴ → 4
    0x06F5: 0x35, // ۵ → 5
    0x06F6: 0x36, // ۶ → 6
    0x06F7: 0x37, // ۷ → 7
    0x06F8: 0x38, // ۸ → 8
    0x06F9: 0x39, // ۹ → 9
  };

  static const formatter = _LatinDigitsFormatter();

  static String convert(String input) {
    if (input.isEmpty) return input;
    final buffer = StringBuffer();
    var changed = false;
    for (final unit in input.runes) {
      final mapped = _map[unit];
      if (mapped != null) {
        buffer.writeCharCode(mapped);
        changed = true;
      } else {
        buffer.writeCharCode(unit);
      }
    }
    return changed ? buffer.toString() : input;
  }

  /// Keeps only Latin digits after converting Arabic/Persian numerals.
  static String only(String input) {
    return convert(input).replaceAll(RegExp(r'[^0-9]'), '');
  }

  static List<TextInputFormatter> merge([List<TextInputFormatter>? others]) {
    if (others == null || others.isEmpty) return const [formatter];
    return [formatter, ...others];
  }
}

class _LatinDigitsFormatter extends TextInputFormatter {
  const _LatinDigitsFormatter();

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final converted = LatinDigits.convert(newValue.text);
    if (identical(converted, newValue.text) || converted == newValue.text) {
      return newValue;
    }
    return TextEditingValue(
      text: converted,
      selection: newValue.selection,
      composing: TextRange.empty,
    );
  }
}
