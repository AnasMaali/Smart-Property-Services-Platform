import 'package:flutter/material.dart';

import '../../../app/theme/blue_theme.dart';
import '../../cart/data/cart_models.dart';
import '../../services/data/service_detail.dart';

enum CheckoutAppointmentKind { none, held, expired }

class CheckoutNamedRef {
  const CheckoutNamedRef({
    required this.id,
    required this.name,
    this.code = '',
  });

  final int id;
  final String name;
  final String code;

  factory CheckoutNamedRef.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return const CheckoutNamedRef(id: 0, name: '');
    }
    return CheckoutNamedRef(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name'] as String? ?? '',
      code: json['code'] as String? ?? '',
    );
  }
}

class CheckoutLocation {
  const CheckoutLocation({
    required this.propertyType,
    required this.area,
    required this.city,
    required this.streetName,
    required this.addressLine,
    required this.building,
    required this.visitPhone,
    this.otherPropertyTypeName,
    this.floorNumber,
    this.unitNumber,
    this.nearbyLandmark,
    this.notes,
  });

  final CheckoutNamedRef propertyType;
  final String? otherPropertyTypeName;
  final CheckoutNamedRef area;
  final CheckoutNamedRef city;
  final String streetName;
  final String addressLine;
  final String building;
  final String? floorNumber;
  final String? unitNumber;
  final String? nearbyLandmark;
  final String? notes;
  final String visitPhone;

  factory CheckoutLocation.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return const CheckoutLocation(
        propertyType: CheckoutNamedRef(id: 0, name: ''),
        area: CheckoutNamedRef(id: 0, name: ''),
        city: CheckoutNamedRef(id: 0, name: ''),
        streetName: '',
        addressLine: '',
        building: '',
        visitPhone: '',
      );
    }
    return CheckoutLocation(
      propertyType: CheckoutNamedRef.fromJson(
        json['property_type'] as Map<String, dynamic>?,
      ),
      otherPropertyTypeName: json['other_property_type_name'] as String?,
      area: CheckoutNamedRef.fromJson(json['area'] as Map<String, dynamic>?),
      city: CheckoutNamedRef.fromJson(json['city'] as Map<String, dynamic>?),
      streetName: json['street_name'] as String? ?? '',
      addressLine: json['address_line'] as String? ?? '',
      building: json['building_name_or_number'] as String? ?? '',
      floorNumber: json['floor_number'] as String?,
      unitNumber: json['unit_number'] as String?,
      nearbyLandmark: json['nearby_landmark'] as String?,
      notes: json['additional_location_notes'] as String?,
      visitPhone: json['visit_contact_phone'] as String? ?? '',
    );
  }

  String get title {
    final other = otherPropertyTypeName?.trim() ?? '';
    if (propertyType.code == 'OTHER' && other.isNotEmpty) return other;
    final name = propertyType.name.trim();
    return name.isEmpty ? 'Saved address' : name;
  }

  String get line1 {
    final parts = <String>[];
    if (building.trim().isNotEmpty) parts.add(building.trim());
    final unit = unitNumber?.trim() ?? '';
    if (unit.isNotEmpty) parts.add('Unit $unit');
    if (parts.isEmpty) {
      final line = addressLine.trim();
      if (line.isNotEmpty) return line;
    }
    return parts.join(', ');
  }

  String get line2 {
    final parts = <String>[];
    if (streetName.trim().isNotEmpty) parts.add(streetName.trim());
    if (area.name.trim().isNotEmpty) parts.add(area.name.trim());
    if (city.name.trim().isNotEmpty) parts.add(city.name.trim());
    return parts.join(', ');
  }
}

class CheckoutSlot {
  const CheckoutSlot({
    required this.uuid,
    required this.startsAt,
    required this.endsAt,
    this.windowName = '',
    this.remainingCapacity,
  });

  final String uuid;
  final DateTime startsAt;
  final DateTime endsAt;
  final String windowName;
  final int? remainingCapacity;

  factory CheckoutSlot.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return CheckoutSlot(
        uuid: '',
        startsAt: DateTime.fromMillisecondsSinceEpoch(0),
        endsAt: DateTime.fromMillisecondsSinceEpoch(0),
      );
    }
    return CheckoutSlot(
      uuid: json['uuid'] as String? ?? '',
      startsAt:
          DateTime.tryParse(json['starts_at'] as String? ?? '')?.toLocal() ??
          DateTime.fromMillisecondsSinceEpoch(0),
      endsAt:
          DateTime.tryParse(json['ends_at'] as String? ?? '')?.toLocal() ??
          DateTime.fromMillisecondsSinceEpoch(0),
      windowName: (json['time_window'] is Map<String, dynamic>)
          ? ((json['time_window'] as Map<String, dynamic>)['name'] as String? ??
                '')
          : '',
      remainingCapacity: (json['remaining_capacity'] as num?)?.toInt(),
    );
  }

  String get dateLabel => formatCheckoutDate(startsAt);

  String get windowLabel {
    return '${formatCheckoutTime(startsAt)} – ${formatCheckoutTime(endsAt)} · Technician arrives within the window';
  }

  String get compactWindow {
    return '${formatCheckoutTime(startsAt)}–${formatCheckoutTime(endsAt)}';
  }
}

class CheckoutAppointment {
  const CheckoutAppointment({
    required this.holdUuid,
    required this.slot,
    required this.expiresAt,
  });

  final String holdUuid;
  final CheckoutSlot slot;
  final DateTime expiresAt;

  factory CheckoutAppointment.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return CheckoutAppointment(
        holdUuid: '',
        slot: CheckoutSlot.fromJson(null),
        expiresAt: DateTime.fromMillisecondsSinceEpoch(0),
      );
    }
    return CheckoutAppointment(
      holdUuid: json['hold_uuid'] as String? ?? '',
      slot: CheckoutSlot.fromJson(json['slot'] as Map<String, dynamic>?),
      expiresAt:
          DateTime.tryParse(json['expires_at'] as String? ?? '')?.toLocal() ??
          DateTime.fromMillisecondsSinceEpoch(0),
    );
  }

  int remainingSeconds([DateTime? now]) {
    final seconds = expiresAt.difference(now ?? DateTime.now()).inSeconds;
    return seconds < 0 ? 0 : seconds;
  }
}

class CheckoutSnapshot {
  const CheckoutSnapshot({
    required this.currency,
    required this.status,
    required this.items,
    required this.readyForPayment,
    this.cartUuid,
    this.location,
    this.appointment,
    this.total,
    this.requiresQuote = false,
    this.requiredContext = const [],
  });

  final String? cartUuid;
  final CheckoutLocation? location;
  final CheckoutAppointment? appointment;
  final CartCurrency currency;
  final CartPricingStatus status;
  final List<CartItem> items;
  final String? total;
  final bool requiresQuote;
  final List<String> requiredContext;
  final bool readyForPayment;

  factory CheckoutSnapshot.empty() {
    return const CheckoutSnapshot(
      currency: CartCurrency(code: 'AED'),
      status: CartPricingStatus.priced,
      items: [],
      total: '0.000000',
      readyForPayment: false,
    );
  }

  factory CheckoutSnapshot.fromJson(Map<String, dynamic>? data) {
    final checkout = data?['checkout'] is Map<String, dynamic>
        ? data!['checkout'] as Map<String, dynamic>
        : data;
    if (checkout is! Map<String, dynamic>) return CheckoutSnapshot.empty();
    final items = (checkout['items'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(CartItem.fromJson)
        .toList();
    return CheckoutSnapshot(
      cartUuid: checkout['cart_uuid'] as String?,
      location: checkout['location'] is Map<String, dynamic>
          ? CheckoutLocation.fromJson(
              checkout['location'] as Map<String, dynamic>,
            )
          : null,
      appointment: checkout['appointment'] is Map<String, dynamic>
          ? CheckoutAppointment.fromJson(
              checkout['appointment'] as Map<String, dynamic>,
            )
          : null,
      currency: CartCurrency.fromJson(
        checkout['currency'] as Map<String, dynamic>?,
      ),
      status: CartPricingStatus.parse(checkout['pricing_status'] as String?),
      items: items,
      total: checkout['total'] as String?,
      requiresQuote: checkout['requires_quote'] == true,
      requiredContext:
          (checkout['required_context'] as List<dynamic>? ?? const [])
              .map((item) => '$item')
              .where((item) => item.isNotEmpty)
              .toList(),
      readyForPayment: checkout['ready_for_payment'] == true,
    );
  }

  bool get isEmpty => items.isEmpty;

  int get serviceCount => items.length;

  String? get pricedNow {
    var sum = 0.0;
    var any = false;
    for (final item in items) {
      if (!item.pricing.isPriced) continue;
      final amount = double.tryParse(item.pricing.lineTotal?.trim() ?? '');
      if (amount == null) continue;
      sum += amount;
      any = true;
    }
    if (!any) return null;
    return sum.toStringAsFixed(6);
  }
}

class CheckoutLineView {
  const CheckoutLineView({
    required this.name,
    required this.nameColor,
    required this.config,
    required this.priced,
    required this.amount,
    required this.showQty,
    required this.qtyText,
    required this.chip,
    required this.chipColor,
    required this.chipBg,
    required this.chipBorder,
    required this.note,
    required this.noteColor,
  });

  final String name;
  final Color nameColor;
  final String config;
  final bool priced;
  final String amount;
  final bool showQty;
  final String qtyText;
  final String chip;
  final Color chipColor;
  final Color chipBg;
  final Color chipBorder;
  final String note;
  final Color noteColor;
}

class CheckoutReviewView {
  const CheckoutReviewView({
    required this.subtitle,
    required this.showReview,
    required this.locDone,
    required this.locTodo,
    required this.locFlag,
    required this.locFlagColor,
    required this.locTitle,
    required this.locLine1,
    required this.locLine2,
    required this.apptDone,
    required this.apptExpired,
    required this.apptTodo,
    required this.apptFlag,
    required this.apptFlagColor,
    required this.apptDate,
    required this.apptWindow,
    required this.holdText,
    required this.holdColor,
    required this.expiredCopy,
    required this.lines,
    required this.showFix,
    required this.hasTotal,
    required this.noTotal,
    required this.totalLabel,
    required this.totalValue,
    required this.totalKey,
    required this.totalChip,
    required this.totalNote,
    required this.showEmpty,
    required this.showSkeleton,
    required this.showError,
    required this.showBar,
    required this.barTotal,
    required this.barCaption,
    required this.ctaOff,
  });

  final String subtitle;
  final bool showReview;
  final bool locDone;
  final bool locTodo;
  final String locFlag;
  final Color locFlagColor;
  final String locTitle;
  final String locLine1;
  final String locLine2;
  final bool apptDone;
  final bool apptExpired;
  final bool apptTodo;
  final String apptFlag;
  final Color apptFlagColor;
  final String apptDate;
  final String apptWindow;
  final String holdText;
  final Color holdColor;
  final String expiredCopy;
  final List<CheckoutLineView> lines;
  final bool showFix;
  final bool hasTotal;
  final bool noTotal;
  final String totalLabel;
  final String totalValue;
  final String totalKey;
  final String totalChip;
  final String totalNote;
  final bool showEmpty;
  final bool showSkeleton;
  final bool showError;
  final bool showBar;
  final String barTotal;
  final String barCaption;
  final bool ctaOff;

  factory CheckoutReviewView.build({
    required bool loading,
    required bool error,
    required CheckoutSnapshot checkout,
    required CheckoutAppointmentKind appointment,
    required int holdSeconds,
    required Map<String, ServiceDetail> details,
    CheckoutAppointment? expiredSlot,
  }) {
    final empty = !loading && !error && checkout.isEmpty;
    final dead = loading || error || empty;
    final locDone = checkout.location != null;
    final apptDone = appointment == CheckoutAppointmentKind.held;
    final apptExpired = appointment == CheckoutAppointmentKind.expired;
    final list = dead ? const <CartItem>[] : checkout.items;
    final priced = list.where((item) => item.pricing.isPriced).toList();
    final pending = list
        .where((item) => item.pricing.isQuote || item.pricing.isMissingContext)
        .toList();
    final blocked = list.where((item) => item.pricing.isUnavailable).toList();
    final subtotal = checkout.pricedNow;
    final money = cartMoneyLabel(
      subtotal,
      code: checkout.currency.code,
      decimalPlaces: checkout.currency.decimalPlaces,
    );
    final location = checkout.location;
    final slot = apptDone ? checkout.appointment?.slot : expiredSlot?.slot;
    final mm = holdSeconds ~/ 60;
    final ss = holdSeconds % 60;
    final holdLow = holdSeconds < 120;
    final ssLabel = ss < 10 ? '0$ss' : '$ss';

    final lines = list.map((item) {
      final config = cartConfigLines(item, details[item.service.slug]);
      var configText = config.visible.join(' · ');
      if (config.extra > 0) {
        configText = '$configText · +${config.extra} more';
      }
      final unit = cartMoneyLabel(
        item.pricing.unitTotal,
        code: checkout.currency.code,
        decimalPlaces: checkout.currency.decimalPlaces,
      );
      final chip = item.pricing.isQuote
          ? 'Quote required'
          : item.pricing.isMissingContext
          ? 'Price after details'
          : item.pricing.isUnavailable
          ? 'Unavailable'
          : '';
      final unavailable = item.pricing.isUnavailable;
      final building = location?.building.trim() ?? '';
      final place = building.isEmpty ? 'this address' : building;
      return CheckoutLineView(
        name: item.service.name,
        nameColor: unavailable ? BlueColors.unavailableInk : BlueColors.ink,
        config: configText,
        priced: item.pricing.isPriced,
        amount: item.pricing.isPriced
            ? cartMoneyLabel(
                item.pricing.lineTotal,
                code: checkout.currency.code,
                decimalPlaces: checkout.currency.decimalPlaces,
              )
            : '',
        showQty: item.quantity > 1,
        qtyText: item.quantity > 1
            ? (item.pricing.isPriced && unit.isNotEmpty
                  ? '×${item.quantity} · $unit each'
                  : '×${item.quantity}')
            : '',
        chip: chip,
        chipColor: unavailable ? BlueColors.unavailableInk : BlueColors.chipInk,
        chipBg: unavailable
            ? BlueColors.unavailableSurface
            : BlueColors.chipSurface,
        chipBorder: unavailable
            ? BlueColors.unavailableLine
            : BlueColors.border,
        note: unavailable
            ? 'Not serviced at $place. Change the location or remove it from your cart.'
            : (item.pricing.isMissingContext
                  ? 'Priced once we have your location and appointment.'
                  : ''),
        noteColor: unavailable ? BlueColors.unavailableInk : BlueColors.muted,
      );
    }).toList();

    final needsCtx =
        pending.any((item) => item.pricing.isMissingContext) && !locDone;
    final missing = <String>[];
    if (!locDone) missing.add('location');
    if (!apptDone) missing.add(apptExpired ? 'a new time' : 'an appointment');

    final ready = !dead && locDone && apptDone && blocked.isEmpty;
    final noTotal = !dead && (blocked.isNotEmpty || needsCtx || priced.isEmpty);
    final totalLabel = blocked.isNotEmpty
        ? 'Total'
        : (pending.isNotEmpty ? 'Priced now' : 'Total');
    final totalChip = blocked.isNotEmpty
        ? 'On hold'
        : (needsCtx ? 'Pending details' : 'Quote required');
    String? quotedName;
    for (final item in pending) {
      if (item.pricing.isQuote) {
        quotedName = item.service.name;
        break;
      }
    }
    final totalNote = blocked.isNotEmpty
        ? "One service isn't available at this address. Change the location or edit your cart to continue."
        : (needsCtx
              ? 'Add your service location to confirm pricing for all services.'
              : (pending.isNotEmpty
                    ? (quotedName == null
                          ? "You'll pay $money now. Quoted services are confirmed after the visit — nothing is charged for them today."
                          : "You'll pay $money now. $quotedName is quoted after the visit — nothing is charged for it today.")
                    : (ready
                          ? 'Confirmed by BLUE. Your card is charged when you complete payment.'
                          : 'Confirmed by BLUE once your location and appointment are set.')));

    final barCaption = blocked.isNotEmpty
        ? '1 service needs attention'
        : (!locDone && !apptDone
              ? 'Location and time needed'
              : (!locDone
                    ? 'Location needed'
                    : (apptExpired
                          ? 'Reserved time expired'
                          : (!apptDone
                                ? 'Appointment needed'
                                : (pending.isNotEmpty
                                      ? 'Priced items only'
                                      : '${list.length}${list.length == 1 ? ' service' : ' services'}')))));

    final expiredWhen = slot == null
        ? ''
        : '${slot.dateLabel}, ${slot.compactWindow} is no longer held. Everything else in this checkout is saved.';

    return CheckoutReviewView(
      subtitle: loading
          ? 'Loading your checkout…'
          : (error || empty
                ? ''
                : (blocked.isNotEmpty
                      ? 'One service needs attention before payment.'
                      : (ready
                            ? 'Review everything, then pay.'
                            : (missing.length == 1
                                  ? 'One thing left: add ${missing.first}.'
                                  : 'Two things to confirm: location and appointment.')))),
      showReview: !dead,
      locDone: locDone,
      locTodo: !locDone,
      locFlag: locDone ? 'Saved' : 'Needed',
      locFlagColor: locDone ? BlueColors.body : BlueColors.unavailableInk,
      locTitle: location?.title ?? '',
      locLine1: location?.line1 ?? '',
      locLine2: location?.line2 ?? '',
      apptDone: apptDone,
      apptExpired: apptExpired,
      apptTodo: appointment == CheckoutAppointmentKind.none,
      apptFlag: apptDone ? 'Reserved' : (apptExpired ? 'Expired' : 'Needed'),
      apptFlagColor: apptDone ? BlueColors.body : BlueColors.unavailableInk,
      apptDate: slot?.dateLabel ?? '',
      apptWindow: slot?.windowLabel ?? '',
      holdText: 'Reserved for $mm:$ssLabel',
      holdColor: holdLow ? BlueColors.unavailableInk : BlueColors.body,
      expiredCopy: expiredWhen,
      lines: lines,
      showFix: blocked.isNotEmpty,
      hasTotal: !noTotal,
      noTotal: noTotal,
      totalLabel: totalLabel,
      totalValue: money,
      totalKey: '$subtotal-${list.length}-${locDone ? 1 : 0}',
      totalChip: totalChip,
      totalNote: totalNote,
      showEmpty: empty,
      showSkeleton: loading,
      showError: error,
      showBar: !dead,
      barTotal: noTotal ? totalChip : money,
      barCaption: barCaption,
      ctaOff: !ready,
    );
  }
}

const _weekdays = [
  'Monday',
  'Tuesday',
  'Wednesday',
  'Thursday',
  'Friday',
  'Saturday',
  'Sunday',
];

const _months = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];

String formatCheckoutDate(DateTime value) {
  return '${_weekdays[value.weekday - 1]} ${value.day} ${_months[value.month - 1]}';
}

String formatCheckoutTime(DateTime value) {
  final hour = value.hour.toString().padLeft(2, '0');
  final minute = value.minute.toString().padLeft(2, '0');
  return '$hour:$minute';
}
