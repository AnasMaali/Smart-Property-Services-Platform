import '../../cart/data/cart_models.dart';
import '../../checkout/data/checkout_models.dart';
import '../../services/data/service_detail.dart';

const paymentProviderName = 'the payment provider';

enum PaymentPhase {
  loading,
  form,
  processing,
  confirming,
  success,
  failed,
  unknown,
  holdExpired,
  initError,
  alreadyPaid,
  priceChanged,
}

enum PaymentExit { checkout, appointment, home, bookings }

class PaymentMethod {
  const PaymentMethod({
    required this.id,
    required this.brand,
    required this.title,
    required this.sub,
  });

  final String id;
  final String brand;
  final String title;
  final String sub;

  String get a11y => '$title, $sub';
}

const paymentDefaultMethod = PaymentMethod(
  id: 'card',
  brand: 'CARD',
  title: 'Card payment',
  sub: 'Pay securely via Stripe',
);

const paymentSavedMethods = [paymentDefaultMethod];

PaymentMethod? paymentMethodById(String? id) {
  if (id == null) return null;
  for (final method in paymentSavedMethods) {
    if (method.id == id) return method;
  }
  return null;
}

class PaymentAttempt {
  const PaymentAttempt({
    required this.uuid,
    required this.status,
    required this.requestedAmount,
    required this.currency,
    this.checkoutReference,
    this.expiresAt,
    this.provider,
    this.clientSecret,
    this.publishableKey,
  });

  final String uuid;
  final String status;
  final String requestedAmount;
  final CartCurrency currency;
  final String? checkoutReference;
  final DateTime? expiresAt;
  final String? provider;
  final String? clientSecret;
  final String? publishableKey;

  bool get successful => status == 'SUCCESSFUL';
  bool get failed => status == 'FAILED';
  bool get cancelled => status == 'CANCELLED';
  bool get pending => status == 'PENDING';

  factory PaymentAttempt.fromJson(Map<String, dynamic>? json) {
    final payment = json?['payment'] is Map<String, dynamic>
        ? json!['payment'] as Map<String, dynamic>
        : json;
    if (payment is! Map<String, dynamic>) {
      return const PaymentAttempt(
        uuid: '',
        status: '',
        requestedAmount: '',
        currency: CartCurrency(code: 'AED'),
      );
    }
    return PaymentAttempt(
      uuid: payment['uuid'] as String? ?? '',
      status: payment['status'] as String? ?? '',
      requestedAmount: payment['requested_amount'] as String? ?? '',
      currency: CartCurrency.fromJson(
        payment['currency'] as Map<String, dynamic>?,
      ),
      checkoutReference: payment['checkout_reference'] as String?,
      expiresAt: DateTime.tryParse(
        payment['expires_at'] as String? ?? '',
      )?.toLocal(),
      provider: payment['provider'] as String?,
      clientSecret: payment['client_secret'] as String?,
      publishableKey: payment['publishable_key'] as String?,
    );
  }
}

class PaymentReceiptRow {
  const PaymentReceiptRow({required this.label, required this.value});

  final String label;
  final String value;
}

class PaymentSummaryLine {
  const PaymentSummaryLine({
    required this.kind,
    required this.text,
    required this.weight,
    required this.ink,
  });

  final PaymentLineKind kind;
  final String text;
  final FontWeightToken weight;
  final PaymentInk ink;
}

enum PaymentLineKind { bag, calendar, pin }

enum FontWeightToken { semibold, medium }

enum PaymentInk { ink, body }

class PaymentSnapshot {
  const PaymentSnapshot({
    required this.checkout,
    required this.details,
    this.reviewedTotal,
  });

  final CheckoutSnapshot checkout;
  final Map<String, ServiceDetail> details;
  final String? reviewedTotal;

  String get money {
    return cartMoneyLabel(
      checkout.pricedNow ?? checkout.total,
      code: checkout.currency.code,
      decimalPlaces: checkout.currency.decimalPlaces,
    );
  }

  String get serviceLine {
    final items = checkout.items;
    if (items.isEmpty) return '';
    if (items.length == 1) {
      final item = items.first;
      final config = cartConfigLines(item, details[item.service.slug]);
      final bits = <String>[item.service.name];
      if (config.visible.isNotEmpty) {
        bits.add(config.visible.join(', '));
      }
      return bits.join(' · ');
    }
    return items.map((item) => item.service.name).join(' · ');
  }

  String get serviceName {
    if (checkout.items.isEmpty) return '';
    if (checkout.items.length == 1) return checkout.items.first.service.name;
    return '${checkout.items.length} services';
  }

  String get whenLine {
    final slot = checkout.appointment?.slot;
    if (slot == null) return '';
    return formatPaymentWhen(slot);
  }

  String get whenShort {
    final slot = checkout.appointment?.slot;
    if (slot == null) return '';
    return formatPaymentWhenShort(slot);
  }

  String get locationLine {
    final location = checkout.location;
    if (location == null) return '';
    return formatPaymentLocation(location);
  }

  List<PaymentSummaryLine> get summary {
    return [
      PaymentSummaryLine(
        kind: PaymentLineKind.bag,
        text: serviceLine,
        weight: FontWeightToken.semibold,
        ink: PaymentInk.ink,
      ),
      PaymentSummaryLine(
        kind: PaymentLineKind.calendar,
        text: whenLine,
        weight: FontWeightToken.medium,
        ink: PaymentInk.body,
      ),
      PaymentSummaryLine(
        kind: PaymentLineKind.pin,
        text: locationLine,
        weight: FontWeightToken.medium,
        ink: PaymentInk.body,
      ),
    ];
  }
}

const _shortDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const _shortMonths = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

String formatPaymentClock(DateTime value) {
  final hour = value.hour % 12 == 0 ? 12 : value.hour % 12;
  final minute = value.minute.toString().padLeft(2, '0');
  final suffix = value.hour < 12 ? 'AM' : 'PM';
  return '$hour:$minute $suffix';
}

String formatPaymentWhen(CheckoutSlot slot) {
  final start = slot.startsAt;
  return '${_shortDays[start.weekday - 1]}, ${start.day} ${_shortMonths[start.month - 1]} · ${formatPaymentClock(start)} – ${formatPaymentClock(slot.endsAt)}';
}

String formatPaymentWindow(CheckoutSlot slot) {
  final start = formatPaymentClock(slot.startsAt);
  final end = formatPaymentClock(slot.endsAt);
  final startBits = start.split(' ');
  final endBits = end.split(' ');
  if (startBits.length == 2 &&
      endBits.length == 2 &&
      startBits.last == endBits.last) {
    return '${startBits.first}–${endBits.first} ${endBits.last}';
  }
  return '$start – $end';
}

String formatPaymentWhenShort(CheckoutSlot slot) {
  final start = slot.startsAt;
  return '${_shortDays[start.weekday - 1]}, ${start.day} ${_shortMonths[start.month - 1]} · ${formatPaymentWindow(slot)}';
}

String formatPaymentPaidOn(DateTime value) {
  return '${value.day} ${_shortMonths[value.month - 1]}, ${formatPaymentClock(value)}';
}

String formatPaymentLocation(CheckoutLocation location) {
  final head = <String>[];
  if (location.building.trim().isNotEmpty) {
    head.add(location.building.trim());
  }
  final unit = location.unitNumber?.trim() ?? '';
  if (unit.isNotEmpty) {
    if (head.isEmpty) {
      head.add('Unit $unit');
    } else {
      head[0] = '${head[0]}, Unit $unit';
    }
  }
  if (head.isEmpty && location.addressLine.trim().isNotEmpty) {
    head.add(location.addressLine.trim());
  }
  final area = location.area.name.trim();
  if (area.isEmpty) return head.join(', ');
  if (head.isEmpty) return area;
  return '${head.join(', ')} · $area';
}

String formatHoldClock(int seconds) {
  final safe = seconds < 0 ? 0 : seconds;
  final mm = safe ~/ 60;
  final ss = safe % 60;
  final ssLabel = ss < 10 ? '0$ss' : '$ss';
  return '$mm:$ssLabel';
}
