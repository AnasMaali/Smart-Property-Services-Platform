/// A currency descriptor as returned by every pricing-bearing endpoint
/// (`{code, symbol, decimal_places}` on Cart/Checkout, `{code, symbol,
/// minor_unit}` on the catalog preview - both shapes carry the same three
/// facts, normalized here to one field name each).
class Currency {
  const Currency({
    required this.code,
    required this.symbol,
    required this.decimalPlaces,
  });

  final String code;
  final String symbol;
  final int decimalPlaces;

  static const aed = Currency(code: 'AED', symbol: 'د.إ', decimalPlaces: 2);
}

/// A currency amount, deliberately backed by the exact decimal **string**
/// the API returned rather than a `double` - the blueprint is explicit
/// that money must never round-trip through binary floating point.
/// [display] formats that string directly (pad/truncate + group digits)
/// without ever parsing it into a number, so no rounding can occur even
/// for formatting.
///
/// This type never performs arithmetic - BLUE has exactly one pricing
/// engine and it is server-side (blueprint §6). Every screen only ever
/// displays a [Money] value it received from a response; it never adds,
/// multiplies, or otherwise derives one client-side.
class Money {
  const Money(this.raw, this.currency);

  /// The exact decimal string from the API, e.g. `"120.000000"`.
  final String raw;
  final Currency currency;

  bool get isNegative => raw.trimLeft().startsWith('-');

  /// Formatted for display, e.g. `"د.إ 120.00"` - grouped thousands,
  /// truncated/padded to the currency's display decimal places.
  String get display => '${currency.symbol} $_formattedNumber';

  /// Formatted without the currency symbol, for contexts that render the
  /// symbol separately (e.g. a currency-code suffix instead of a prefix).
  String get formattedAmount => _formattedNumber;

  String get _formattedNumber {
    var value = raw.trim();
    final negative = value.startsWith('-');
    if (negative) value = value.substring(1);

    final parts = value.split('.');
    final wholeDigits = parts.first;
    var fraction = parts.length > 1 ? parts[1] : '';
    fraction = fraction.padRight(currency.decimalPlaces, '0');
    fraction = fraction.substring(0, currency.decimalPlaces);

    final buffer = StringBuffer();
    for (var i = 0; i < wholeDigits.length; i++) {
      final fromEnd = wholeDigits.length - i;
      if (i > 0 && fromEnd % 3 == 0) buffer.write(',');
      buffer.write(wholeDigits[i]);
    }

    final whole = buffer.toString();
    final formatted = currency.decimalPlaces == 0 ? whole : '$whole.$fraction';
    return negative ? '-$formatted' : formatted;
  }
}
