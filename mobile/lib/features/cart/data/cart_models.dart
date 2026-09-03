import '../../home/data/catalog_service.dart';
import '../../services/data/service_detail.dart';

enum CartPricingStatus {
  priced,
  quoteRequired,
  missingContext,
  unavailable;

  static CartPricingStatus parse(String? raw) {
    return switch (raw) {
      'QUOTE_REQUIRED' => CartPricingStatus.quoteRequired,
      'MISSING_CONTEXT' => CartPricingStatus.missingContext,
      'UNAVAILABLE' => CartPricingStatus.unavailable,
      _ => CartPricingStatus.priced,
    };
  }
}

class CartCurrency {
  const CartCurrency({
    required this.code,
    this.symbol = '',
    this.decimalPlaces = 2,
  });

  final String code;
  final String symbol;
  final int decimalPlaces;

  factory CartCurrency.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return const CartCurrency(code: 'AED');
    }
    return CartCurrency(
      code: json['code'] as String? ?? 'AED',
      symbol: json['symbol'] as String? ?? '',
      decimalPlaces:
          (json['decimal_places'] as num?)?.toInt() ??
          (json['minor_unit'] as num?)?.toInt() ??
          2,
    );
  }
}

class CartOption {
  const CartOption({
    required this.optionUuid,
    this.textValue,
    this.numericValue,
    this.booleanValue,
    this.choiceUuids = const [],
  });

  final String optionUuid;
  final String? textValue;
  final String? numericValue;
  final bool? booleanValue;
  final List<String> choiceUuids;

  factory CartOption.fromJson(Map<String, dynamic> json) {
    return CartOption(
      optionUuid: json['option_uuid'] as String? ?? '',
      textValue: json['text_value'] as String?,
      numericValue: json['numeric_value']?.toString(),
      booleanValue: json['boolean_value'] as bool?,
      choiceUuids: (json['choice_uuids'] as List<dynamic>? ?? const [])
          .map((item) => '$item')
          .where((item) => item.isNotEmpty)
          .toList(),
    );
  }

  Map<String, dynamic> toPayload() {
    final out = <String, dynamic>{'option_uuid': optionUuid};
    if (choiceUuids.isNotEmpty) {
      out['choice_uuids'] = choiceUuids;
    } else if (numericValue != null) {
      final number = num.tryParse(numericValue!);
      out['numeric_value'] = number ?? numericValue;
    } else if (booleanValue != null) {
      out['boolean_value'] = booleanValue;
    } else if (textValue != null) {
      out['text_value'] = textValue;
    }
    return out;
  }
}

class CartItemPricing {
  const CartItemPricing({
    required this.status,
    this.currencyCode,
    this.unitTotal,
    this.lineTotal,
    this.quantity = 1,
    this.requiredContext = const [],
  });

  final CartPricingStatus status;
  final String? currencyCode;
  final String? unitTotal;
  final String? lineTotal;
  final int quantity;
  final List<String> requiredContext;

  factory CartItemPricing.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return const CartItemPricing(status: CartPricingStatus.unavailable);
    }
    final currency = json['currency'];
    String? code;
    if (currency is String && currency.trim().isNotEmpty) {
      code = currency.trim();
    } else if (currency is Map<String, dynamic>) {
      code = currency['code'] as String?;
    }
    return CartItemPricing(
      status: CartPricingStatus.parse(json['pricing_status'] as String?),
      currencyCode: code,
      unitTotal: json['unit_total'] as String?,
      lineTotal: json['line_total'] as String?,
      quantity: (json['quantity'] as num?)?.toInt() ?? 1,
      requiredContext: (json['required_context'] as List<dynamic>? ?? const [])
          .map((item) => '$item')
          .where((item) => item.isNotEmpty)
          .toList(),
    );
  }

  CartItemPricing copyWith({int? quantity, String? lineTotal}) {
    return CartItemPricing(
      status: status,
      currencyCode: currencyCode,
      unitTotal: unitTotal,
      lineTotal: lineTotal ?? this.lineTotal,
      quantity: quantity ?? this.quantity,
      requiredContext: requiredContext,
    );
  }

  bool get isPriced =>
      status == CartPricingStatus.priced && _amount(lineTotal) != null;

  bool get isUnavailable => status == CartPricingStatus.unavailable;

  bool get isQuote => status == CartPricingStatus.quoteRequired;

  bool get isMissingContext => status == CartPricingStatus.missingContext;
}

class CartService {
  const CartService({
    required this.uuid,
    required this.slug,
    required this.name,
    this.image,
  });

  final String uuid;
  final String slug;
  final String name;
  final CatalogServiceImage? image;

  factory CartService.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return const CartService(uuid: '', slug: '', name: 'Service');
    }
    return CartService(
      uuid: json['uuid'] as String? ?? '',
      slug: json['slug'] as String? ?? '',
      name: json['name'] as String? ?? 'Service',
      image: json['primary_image'] is Map<String, dynamic>
          ? CatalogServiceImage.fromJson(
              json['primary_image'] as Map<String, dynamic>,
            )
          : null,
    );
  }
}

class CartItem {
  const CartItem({
    required this.uuid,
    required this.quantity,
    required this.service,
    required this.pricing,
    this.options = const [],
    this.quantityLocked = false,
  });

  final String uuid;
  final int quantity;
  final CartService service;
  final CartItemPricing pricing;
  final List<CartOption> options;
  final bool quantityLocked;

  factory CartItem.fromJson(Map<String, dynamic> json) {
    return CartItem(
      uuid: json['uuid'] as String? ?? json['cart_item_uuid'] as String? ?? '',
      quantity: (json['quantity'] as num?)?.toInt() ?? 1,
      service: CartService.fromJson(json['service'] as Map<String, dynamic>?),
      pricing: CartItemPricing.fromJson(
        json['pricing'] as Map<String, dynamic>?,
      ),
      options: (json['options'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(CartOption.fromJson)
          .toList(),
      quantityLocked: json['quantity_locked'] == true,
    );
  }

  CartItem copyWith({int? quantity, CartItemPricing? pricing}) {
    return CartItem(
      uuid: uuid,
      quantity: quantity ?? this.quantity,
      service: service,
      pricing: pricing ?? this.pricing,
      options: options,
      quantityLocked: quantityLocked,
    );
  }

  CartItem withQuantity(int next) {
    final qty = next < 1 ? 1 : next;
    String? line = pricing.lineTotal;
    if (pricing.status == CartPricingStatus.priced) {
      final unit = _amount(pricing.unitTotal);
      if (unit != null) {
        line = (unit * qty).toStringAsFixed(6);
      }
    }
    return copyWith(
      quantity: qty,
      pricing: pricing.copyWith(quantity: qty, lineTotal: line),
    );
  }

  List<Map<String, dynamic>> optionPayload() {
    return options.map((option) => option.toPayload()).toList();
  }
}

class CartSnapshot {
  const CartSnapshot({
    required this.currency,
    required this.status,
    required this.items,
    this.uuid,
    this.total,
    this.requiresQuote = false,
    this.requiredContext = const [],
  });

  final String? uuid;
  final CartCurrency currency;
  final CartPricingStatus status;
  final List<CartItem> items;
  final String? total;
  final bool requiresQuote;
  final List<String> requiredContext;

  factory CartSnapshot.empty() {
    return const CartSnapshot(
      currency: CartCurrency(code: 'AED'),
      status: CartPricingStatus.priced,
      items: [],
      total: '0.000000',
    );
  }

  factory CartSnapshot.fromJson(Map<String, dynamic>? data) {
    final cart = data?['cart'];
    if (cart is! Map<String, dynamic>) return CartSnapshot.empty();
    final items = (cart['items'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(CartItem.fromJson)
        .toList();
    return CartSnapshot(
      uuid: cart['uuid'] as String?,
      currency: CartCurrency.fromJson(
        cart['currency'] as Map<String, dynamic>?,
      ),
      status: CartPricingStatus.parse(cart['pricing_status'] as String?),
      items: items,
      total: cart['total'] as String?,
      requiresQuote: cart['requires_quote'] == true,
      requiredContext: (cart['required_context'] as List<dynamic>? ?? const [])
          .map((item) => '$item')
          .where((item) => item.isNotEmpty)
          .toList(),
    );
  }

  CartSnapshot copyWith({List<CartItem>? items}) {
    final next = items ?? this.items;
    return CartSnapshot(
      uuid: uuid,
      currency: currency,
      status: _rollUp(next),
      items: next,
      total: _pricedTotal(next),
      requiresQuote: next.any((item) => item.pricing.isQuote),
      requiredContext: requiredContext,
    );
  }

  bool get isEmpty => items.isEmpty;

  int get serviceCount => items.length;

  int get unitCount {
    var sum = 0;
    for (final item in items) {
      sum += item.quantity;
    }
    return sum;
  }

  int get blockedCount =>
      items.where((item) => item.pricing.isUnavailable).length;

  bool get checkoutBlocked => blockedCount > 0;

  bool get fullyPriced {
    if (items.isEmpty) return true;
    return items.every((item) => item.pricing.isPriced);
  }

  bool get hasUnpriced => items.any(
    (item) => item.pricing.isQuote || item.pricing.isMissingContext,
  );

  String? get pricedNow {
    if (fullyPriced) return total ?? _pricedTotal(items);
    return _pricedTotal(items);
  }
}

class CartConfigLine {
  const CartConfigLine({required this.visible, this.hidden = const []});

  final List<String> visible;
  final List<String> hidden;

  int get extra => hidden.length;

  List<String> get all => [...visible, ...hidden];
}

CartConfigLine cartConfigLines(CartItem item, ServiceDetail? detail) {
  if (detail == null) return const CartConfigLine(visible: []);
  final selected = {
    for (final option in item.options) option.optionUuid: option,
  };
  final fragments = <String>[];

  ServiceOption? numberOption;
  ServiceOption? typeOption;
  CartOption? numberValue;
  CartOption? typeValue;

  for (final option in detail.options) {
    final value = selected[option.uuid];
    if (value == null) continue;
    if (option.kind == ServiceOptionKind.number && numberOption == null) {
      numberOption = option;
      numberValue = value;
    } else if (option.kind == ServiceOptionKind.singleSelect &&
        typeOption == null) {
      typeOption = option;
      typeValue = value;
    }
  }

  final composed = _composedUnits(
    numberOption,
    numberValue,
    typeOption,
    typeValue,
  );
  if (composed != null) {
    fragments.add(composed);
  }

  for (final option in detail.options) {
    final value = selected[option.uuid];
    if (value == null) continue;
    if (composed != null &&
        (option.uuid == numberOption?.uuid ||
            option.uuid == typeOption?.uuid)) {
      continue;
    }
    switch (option.kind) {
      case ServiceOptionKind.number:
        final fragment = _numberFragment(option, value);
        if (fragment != null) fragments.add(fragment);
      case ServiceOptionKind.singleSelect:
        for (final choice in option.choices) {
          if (value.choiceUuids.contains(choice.uuid)) {
            fragments.add(choice.name);
          }
        }
      case ServiceOptionKind.multiSelect:
        for (final choice in option.choices) {
          if (value.choiceUuids.contains(choice.uuid)) {
            fragments.add(choice.name);
          }
        }
      case ServiceOptionKind.boolean:
        if (value.booleanValue == null) continue;
        fragments.add(_boolFragment(option, value.booleanValue!));
      case ServiceOptionKind.text:
        continue;
    }
  }

  if (fragments.isEmpty) return const CartConfigLine(visible: []);
  if (fragments.length <= 2) {
    return CartConfigLine(visible: fragments);
  }
  return CartConfigLine(
    visible: fragments.take(2).toList(),
    hidden: fragments.skip(2).toList(),
  );
}

String formatCartMoney(String? raw, {int decimalPlaces = 2}) {
  final amount = _amount(raw);
  if (amount == null) return '';
  final sign = amount < 0 ? '-' : '';
  final abs = amount.abs();
  final whole = abs.truncate();
  final wholeLabel = _withCommas('$whole');
  if (decimalPlaces <= 0) return '$sign$wholeLabel';
  final fixed = abs.toStringAsFixed(decimalPlaces);
  var fraction = fixed.contains('.') ? fixed.split('.').last : '';
  fraction = fraction.replaceFirst(RegExp(r'0+$'), '');
  if (fraction.isEmpty) return '$sign$wholeLabel';
  return '$sign$wholeLabel.$fraction';
}

String cartMoneyLabel(
  String? raw, {
  String code = 'AED',
  int decimalPlaces = 2,
}) {
  final money = formatCartMoney(raw, decimalPlaces: decimalPlaces);
  if (money.isEmpty) return '';
  final prefix = code.trim().isEmpty ? 'AED' : code.trim();
  return '$prefix $money';
}

CartPricingStatus _rollUp(List<CartItem> items) {
  if (items.any((item) => item.pricing.isQuote)) {
    return CartPricingStatus.quoteRequired;
  }
  if (items.any((item) => item.pricing.isMissingContext)) {
    return CartPricingStatus.missingContext;
  }
  if (items.any((item) => item.pricing.isUnavailable)) {
    return CartPricingStatus.unavailable;
  }
  return CartPricingStatus.priced;
}

String? _pricedTotal(List<CartItem> items) {
  var sum = 0.0;
  var any = false;
  for (final item in items) {
    if (!item.pricing.isPriced) continue;
    final amount = _amount(item.pricing.lineTotal);
    if (amount == null) continue;
    sum += amount;
    any = true;
  }
  if (!any) return null;
  return sum.toStringAsFixed(6);
}

double? _amount(String? raw) {
  if (raw == null) return null;
  return double.tryParse(raw.trim());
}

String? _composedUnits(
  ServiceOption? numberOption,
  CartOption? numberValue,
  ServiceOption? typeOption,
  CartOption? typeValue,
) {
  if (numberOption == null || numberValue == null) return null;
  if (typeOption == null || typeValue == null) return null;
  final unit = (numberOption.numeric?.displayUnit ?? '').toLowerCase();
  if (unit.isNotEmpty && unit != 'unit' && unit != 'units') return null;
  final count = _intAmount(numberValue.numericValue);
  if (count == null) return null;
  String? typeName;
  for (final choice in typeOption.choices) {
    if (typeValue.choiceUuids.contains(choice.uuid)) {
      typeName = choice.name;
      break;
    }
  }
  if (typeName == null || typeName.isEmpty) return null;
  final noun = count == 1 ? 'unit' : 'units';
  return '$count ${typeName.toLowerCase()} $noun';
}

String? _numberFragment(ServiceOption option, CartOption value) {
  final count = _intAmount(value.numericValue);
  if (count == null) return null;
  var unit = option.numeric?.displayUnit ?? '';
  if (unit.isEmpty) unit = 'unit';
  if (count == 1) {
    if (unit.endsWith('s') && unit.length > 1) {
      unit = unit.substring(0, unit.length - 1);
    }
  } else if (!unit.endsWith('s')) {
    unit = '${unit}s';
  }
  return '$count $unit';
}

String _boolFragment(ServiceOption option, bool value) {
  if (option.code == 'ABOVE_3M') {
    return value ? 'High mounts' : 'No high mounts';
  }
  final name = option.name.replaceAll('?', '').trim();
  if (value) return name;
  return 'No $name';
}

int? _intAmount(String? raw) {
  final amount = _amount(raw);
  if (amount == null) return null;
  return amount.round();
}

String _withCommas(String digits) {
  final buffer = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    final left = digits.length - i;
    if (i > 0 && left % 3 == 0) buffer.write(',');
    buffer.write(digits[i]);
  }
  return buffer.toString();
}
