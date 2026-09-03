import 'package:flutter/material.dart';

import '../../../app/theme/blue_theme.dart';

enum BookingTone { today, active, scheduled, attention, done, closed }

class BookingService {
  const BookingService({
    required this.uuid,
    required this.code,
    required this.name,
    this.slug = '',
  });

  final String uuid;
  final String code;
  final String name;
  final String slug;

  factory BookingService.fromJson(Map<String, dynamic>? json) {
    return BookingService(
      uuid: json?['uuid'] as String? ?? '',
      code: json?['code'] as String? ?? '',
      name: (json?['name'] as String? ?? '').trim(),
      slug: (json?['slug'] as String? ?? '').trim(),
    );
  }
}

class BookingItemPricing {
  const BookingItemPricing({
    this.baseAmount = '',
    this.unitTotal = '',
    this.lineTotal = '',
    this.adjustments = const [],
  });

  final String baseAmount;
  final String unitTotal;
  final String lineTotal;
  final List<Map<String, dynamic>> adjustments;

  factory BookingItemPricing.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const BookingItemPricing();
    final raw = json['adjustments'];
    return BookingItemPricing(
      baseAmount: '${json['base_amount'] ?? ''}',
      unitTotal: '${json['unit_total'] ?? ''}',
      lineTotal: '${json['line_total'] ?? ''}',
      adjustments: raw is List
          ? raw.whereType<Map<String, dynamic>>().toList()
          : const [],
    );
  }
}

class BookingItemOptionChoice {
  const BookingItemOptionChoice({
    required this.uuid,
    required this.code,
    required this.name,
    this.description = '',
  });

  final String uuid;
  final String code;
  final String name;
  final String description;

  factory BookingItemOptionChoice.fromJson(Map<String, dynamic> json) {
    return BookingItemOptionChoice(
      uuid: json['uuid'] as String? ?? '',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      description: (json['description'] as String? ?? '').trim(),
    );
  }
}

class BookingItemOption {
  const BookingItemOption({
    required this.optionUuid,
    required this.code,
    required this.name,
    required this.type,
    this.numericValue,
    this.booleanValue,
    this.unitSymbol = '',
    this.choices = const [],
  });

  final String optionUuid;
  final String code;
  final String name;
  final String type;
  final String? numericValue;
  final bool? booleanValue;
  final String unitSymbol;
  final List<BookingItemOptionChoice> choices;

  factory BookingItemOption.fromJson(Map<String, dynamic> json) {
    final unit = json['measurement_unit'];
    String symbol = '';
    if (unit is Map<String, dynamic>) {
      symbol = (unit['symbol'] as String? ?? unit['code'] as String? ?? '')
          .trim();
    }
    return BookingItemOption(
      optionUuid: json['option_uuid'] as String? ?? '',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      type: json['type'] as String? ?? '',
      numericValue: json['numeric_value']?.toString(),
      booleanValue: json['boolean_value'] as bool?,
      unitSymbol: symbol,
      choices: (json['choices'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(BookingItemOptionChoice.fromJson)
          .toList(),
    );
  }
}

class BookingItem {
  const BookingItem({
    required this.uuid,
    required this.service,
    required this.quantity,
    required this.status,
    this.pricing = const BookingItemPricing(),
    this.options = const [],
    this.technicianName = '',
    this.completedAt,
    this.cancelledAt,
    this.canRate = false,
    this.rating,
  });

  final String uuid;
  final BookingService service;
  final int quantity;
  final String status;
  final BookingItemPricing pricing;
  final List<BookingItemOption> options;
  final String technicianName;
  final DateTime? completedAt;
  final DateTime? cancelledAt;
  final bool canRate;
  final BookingRating? rating;

  bool get isCompleted => status == 'COMPLETED';

  bool get isInProgress => status == 'IN_PROGRESS';

  bool get isCancelled => status == 'CANCELLED';

  bool get isAssigned => status == 'ASSIGNED';

  bool get hasRating => rating != null;

  factory BookingItem.fromJson(Map<String, dynamic> json) {
    return BookingItem(
      uuid: json['uuid'] as String? ?? '',
      service: BookingService.fromJson(
        json['service'] as Map<String, dynamic>?,
      ),
      quantity: (json['quantity'] as num?)?.toInt() ?? 1,
      status: json['status'] as String? ?? '',
      pricing: BookingItemPricing.fromJson(
        json['pricing'] as Map<String, dynamic>?,
      ),
      options: (json['options'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(BookingItemOption.fromJson)
          .toList(),
      technicianName: _technicianName(json),
      completedAt: DateTime.tryParse(
        json['completed_at'] as String? ?? '',
      )?.toLocal(),
      cancelledAt: DateTime.tryParse(
        json['cancelled_at'] as String? ?? '',
      )?.toLocal(),
      canRate: json['can_rate'] == true || json['rating_eligible'] == true,
      rating: _rating(json['rating']),
    );
  }
}

class BookingLocation {
  const BookingLocation({
    required this.areaName,
    required this.cityName,
    required this.building,
    this.propertyTypeName = '',
    this.otherPropertyTypeName = '',
    this.streetName = '',
    this.addressLine = '',
    this.floorNumber = '',
    this.unitNumber = '',
    this.notes = '',
    this.visitContactPhone = '',
  });

  final String areaName;
  final String cityName;
  final String building;
  final String propertyTypeName;
  final String otherPropertyTypeName;
  final String streetName;
  final String addressLine;
  final String floorNumber;
  final String unitNumber;
  final String notes;
  final String visitContactPhone;

  factory BookingLocation.fromJson(Map<String, dynamic>? json) {
    return BookingLocation(
      areaName: (json?['area_name'] as String? ?? '').trim(),
      cityName: (json?['city_name'] as String? ?? '').trim(),
      building: (json?['building_name_or_number'] as String? ?? '').trim(),
      propertyTypeName: (json?['property_type_name'] as String? ?? '').trim(),
      otherPropertyTypeName:
          (json?['other_property_type_name'] as String? ?? '').trim(),
      streetName: (json?['street_name'] as String? ?? '').trim(),
      addressLine: (json?['address_line'] as String? ?? '').trim(),
      floorNumber: (json?['floor_number'] as String? ?? '').trim(),
      unitNumber: (json?['unit_number'] as String? ?? '').trim(),
      notes: (json?['additional_location_notes'] as String? ?? '').trim(),
      visitContactPhone: (json?['visit_contact_phone'] as String? ?? '').trim(),
    );
  }

  String get line {
    final place = areaName.isNotEmpty ? areaName : cityName;
    if (place.isEmpty) return building;
    if (building.isEmpty) return place;
    return '$place · $building';
  }
}

class BookingCurrency {
  const BookingCurrency({
    this.code = 'AED',
    this.symbol = '',
    this.decimalPlaces = 2,
  });

  final String code;
  final String symbol;
  final int decimalPlaces;

  factory BookingCurrency.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const BookingCurrency();
    return BookingCurrency(
      code: (json['code'] as String? ?? 'AED').trim().isEmpty
          ? 'AED'
          : (json['code'] as String).trim(),
      symbol: (json['symbol'] as String? ?? '').trim(),
      decimalPlaces: (json['decimal_places'] as num?)?.toInt() ?? 2,
    );
  }
}

class BookingRefundDue {
  const BookingRefundDue({
    required this.percentage,
    required this.amount,
    this.execution = '',
  });

  final int percentage;
  final String amount;
  final String execution;

  factory BookingRefundDue.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const BookingRefundDue(percentage: 0, amount: '');
    return BookingRefundDue(
      percentage: (json['percentage'] as num?)?.toInt() ?? 0,
      amount: '${json['amount'] ?? ''}',
      execution: (json['execution'] as String? ?? '').trim(),
    );
  }
}

class BookingSlot {
  const BookingSlot({required this.startsAt, required this.endsAt});

  final DateTime startsAt;
  final DateTime endsAt;

  factory BookingSlot.fromJson(Map<String, dynamic>? json) {
    final slot = json?['slot'] as Map<String, dynamic>?;
    return BookingSlot(
      startsAt:
          DateTime.tryParse(slot?['starts_at'] as String? ?? '')?.toLocal() ??
          DateTime.fromMillisecondsSinceEpoch(0),
      endsAt:
          DateTime.tryParse(slot?['ends_at'] as String? ?? '')?.toLocal() ??
          DateTime.fromMillisecondsSinceEpoch(0),
    );
  }
}

class BookingRating {
  const BookingRating({
    required this.value,
    this.comment = '',
    this.submittedAt,
    this.label = '',
  });

  final int value;
  final String comment;
  final DateTime? submittedAt;
  final String label;

  String get word {
    final named = label.trim();
    if (named.isNotEmpty) return named;
    return switch (value) {
      1 => 'Very Poor',
      2 => 'Poor',
      3 => 'Average',
      4 => 'Good',
      5 => 'Excellent',
      _ => '',
    };
  }

  factory BookingRating.fromJson(Map<String, dynamic> json) {
    final raw = json['value'] ?? json['rating_value'] ?? json['stars'];
    final parsed = raw is num ? raw.toInt() : int.tryParse('$raw') ?? 0;
    final value = parsed < 1 ? 1 : (parsed > 5 ? 5 : parsed);
    return BookingRating(
      value: value,
      comment: (json['comment'] as String? ?? '').trim(),
      submittedAt: DateTime.tryParse(
        json['submitted_at'] as String? ?? json['created_at'] as String? ?? '',
      )?.toLocal(),
      label: (json['label'] as String? ?? json['word'] as String? ?? '').trim(),
    );
  }
}

class Booking {
  const Booking({
    required this.uuid,
    required this.bookingNumber,
    required this.status,
    required this.location,
    required this.slot,
    required this.items,
    required this.createdAt,
    required this.statusChangedAt,
    this.currency = const BookingCurrency(),
    this.total = '',
    this.source = '',
    this.completedAt,
    this.cancelledAt,
    this.refundDue,
    this.canRate = false,
    this.rating,
    this.history = const [],
  });

  final String uuid;
  final String bookingNumber;
  final String status;
  final BookingLocation location;
  final BookingSlot slot;
  final List<BookingItem> items;
  final DateTime createdAt;
  final DateTime? statusChangedAt;
  final BookingCurrency currency;
  final String total;
  final String source;
  final DateTime? completedAt;
  final DateTime? cancelledAt;
  final BookingRefundDue? refundDue;
  final bool canRate;
  final BookingRating? rating;
  final List<BookingHistoryEvent> history;

  bool get hasRating => rating != null;

  bool get isPast => status == 'COMPLETED' || status == 'CANCELLED';

  bool get isCurrent => !isPast;

  factory Booking.fromJson(Map<String, dynamic> json) {
    final appointment = json['appointment'] as Map<String, dynamic>?;
    final refund = json['refund_due'];
    return Booking(
      uuid: json['uuid'] as String? ?? '',
      bookingNumber: (json['booking_number'] as String? ?? '').trim(),
      status: json['status'] as String? ?? '',
      location: BookingLocation.fromJson(
        json['location'] as Map<String, dynamic>?,
      ),
      slot: BookingSlot.fromJson(appointment),
      items: (json['items'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(BookingItem.fromJson)
          .toList(),
      createdAt:
          DateTime.tryParse(json['created_at'] as String? ?? '')?.toLocal() ??
          DateTime.fromMillisecondsSinceEpoch(0),
      statusChangedAt: DateTime.tryParse(
        json['status_changed_at'] as String? ?? '',
      )?.toLocal(),
      currency: BookingCurrency.fromJson(
        json['currency'] as Map<String, dynamic>?,
      ),
      total: '${json['total'] ?? ''}',
      source: (json['source'] as String? ?? '').trim(),
      completedAt: DateTime.tryParse(
        json['completed_at'] as String? ?? '',
      )?.toLocal(),
      cancelledAt: DateTime.tryParse(
        json['cancelled_at'] as String? ?? '',
      )?.toLocal(),
      refundDue: refund is Map<String, dynamic>
          ? BookingRefundDue.fromJson(refund)
          : null,
      canRate: json['can_rate'] == true || json['rating_eligible'] == true,
      rating: _rating(json['rating']),
      history: _history(json),
    );
  }

  BookingRowView present([DateTime? now]) {
    return BookingRowView.fromBooking(this, now ?? DateTime.now());
  }

  BookingDetailView detail([DateTime? now]) {
    return BookingDetailView.fromBooking(this, now ?? DateTime.now());
  }
}

class BookingHistoryEvent {
  const BookingHistoryEvent({required this.label, required this.at});

  final String label;
  final DateTime at;
}

class BookingChipStyle {
  const BookingChipStyle({
    required this.color,
    required this.background,
    required this.border,
  });

  final Color color;
  final Color background;
  final Color border;

  static const today = BookingChipStyle(
    color: BlueColors.white,
    background: BlueColors.ink,
    border: BlueColors.ink,
  );
  static const active = today;
  static const scheduled = BookingChipStyle(
    color: BlueColors.chipInk,
    background: BlueColors.chipSurface,
    border: BlueColors.border,
  );
  static const attention = BookingChipStyle(
    color: BlueColors.unavailableInk,
    background: BlueColors.unavailableSurface,
    border: BlueColors.unavailableLine,
  );
  static const done = scheduled;
  static const closed = BookingChipStyle(
    color: BlueColors.placeholder,
    background: BlueColors.areaLocked,
    border: BlueColors.dateFullLine,
  );

  static BookingChipStyle of(BookingTone tone) {
    return switch (tone) {
      BookingTone.today => today,
      BookingTone.active => active,
      BookingTone.scheduled => scheduled,
      BookingTone.attention => attention,
      BookingTone.done => done,
      BookingTone.closed => closed,
    };
  }
}

class BookingRowView {
  const BookingRowView({
    required this.uuid,
    required this.status,
    required this.tone,
    required this.dot,
    required this.name,
    required this.extra,
    required this.when,
    required this.where,
    required this.ref,
    required this.itemNote,
    required this.past,
  });

  final String uuid;
  final String status;
  final BookingTone tone;
  final bool dot;
  final String name;
  final String extra;
  final String when;
  final String where;
  final String ref;
  final String itemNote;
  final bool past;

  BookingChipStyle get chip => BookingChipStyle.of(tone);

  Color get nameColor =>
      tone == BookingTone.closed ? BlueColors.chipInk : BlueColors.ink;

  FontWeight get whenWeight => past ? FontWeight.w500 : FontWeight.w600;

  Color get whenColor => past ? BlueColors.muted : BlueColors.ink;

  String get a11y {
    final extras = extra.isEmpty ? '' : ', $extra';
    final reference = ref.isEmpty ? 'not available' : ref;
    final note = itemNote.isEmpty ? '' : '. $itemNote';
    return '$name$extras. $status. $when. $where. Reference $reference$note. Opens booking details.';
  }

  factory BookingRowView.fromBooking(Booking booking, DateTime now) {
    final today = DateTime(now.year, now.month, now.day);
    final startDay = DateTime(
      booking.slot.startsAt.year,
      booking.slot.startsAt.month,
      booking.slot.startsAt.day,
    );
    final isToday = startDay == today;
    final tone = _tone(booking.status, isToday);
    final past = tone == BookingTone.done || tone == BookingTone.closed;
    final items = booking.items;
    final primary = items.isEmpty ? 'Booking' : items.first.service.name;
    final extraCount = items.length > 1 ? items.length - 1 : 0;
    final finished = items.where((item) => item.isCompleted).length;

    String extra = '';
    if (extraCount > 0) {
      extra = extraCount == 1
          ? '+ 1 more service'
          : '+ $extraCount more services';
    }

    String itemNote = '';
    if (tone == BookingTone.attention) {
      itemNote = 'Complete payment to keep this appointment';
    } else if (tone == BookingTone.active && items.length > 1) {
      itemNote = '$finished of ${items.length} services finished';
    } else if (items.length > 1) {
      itemNote = '${items.length} services in this booking';
    }

    return BookingRowView(
      uuid: booking.uuid,
      status: _statusLabel(tone),
      tone: tone,
      dot: (tone == BookingTone.today || tone == BookingTone.active) && isToday,
      name: primary,
      extra: extra,
      when: _whenLine(booking, tone, isToday, startDay, today),
      where: booking.location.line,
      ref: _visibleRef(booking.bookingNumber),
      itemNote: itemNote,
      past: past,
    );
  }
}

const _uuid = r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';
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

BookingTone _tone(String status, bool isToday) {
  return switch (status) {
    'CANCELLED' => BookingTone.closed,
    'COMPLETED' => BookingTone.done,
    'IN_PROGRESS' => BookingTone.active,
    'AWAITING_PAYMENT' || 'PENDING_PAYMENT' => BookingTone.attention,
    _ => isToday ? BookingTone.today : BookingTone.scheduled,
  };
}

String _statusLabel(BookingTone tone) {
  return switch (tone) {
    BookingTone.today => 'Today',
    BookingTone.active => 'In progress',
    BookingTone.scheduled => 'Scheduled',
    BookingTone.attention => 'Awaiting payment',
    BookingTone.done => 'Completed',
    BookingTone.closed => 'Cancelled',
  };
}

String _visibleRef(String value) {
  if (value.isEmpty) return '';
  if (RegExp(_uuid, caseSensitive: false).hasMatch(value)) return '';
  return value;
}

String _whenLine(
  Booking booking,
  BookingTone tone,
  bool isToday,
  DateTime startDay,
  DateTime today,
) {
  final date = _dateLabel(startDay, today, isToday);
  if (tone == BookingTone.active) {
    final started = booking.statusChangedAt ?? booking.slot.startsAt;
    return '$date · started ${_clock(started)}';
  }
  return '$date · ${_clock(booking.slot.startsAt)} – ${_clock(booking.slot.endsAt)}';
}

String _dateLabel(DateTime startDay, DateTime today, bool isToday) {
  if (isToday) return 'Today';
  final tomorrow = today.add(const Duration(days: 1));
  if (startDay.year == tomorrow.year &&
      startDay.month == tomorrow.month &&
      startDay.day == tomorrow.day) {
    return 'Tomorrow';
  }
  return '${_shortDays[startDay.weekday - 1]}, ${startDay.day} ${_shortMonths[startDay.month - 1]}';
}

String _clock(DateTime value) {
  final hour24 = value.hour;
  final minute = value.minute.toString().padLeft(2, '0');
  final hour12 = hour24 % 12 == 0 ? 12 : hour24 % 12;
  final period = hour24 >= 12 ? 'PM' : 'AM';
  return '$hour12:$minute $period';
}

String _technicianName(Map<String, dynamic> json) {
  for (final key in ['technician_name', 'assigned_to', 'completed_by']) {
    final value = (json[key] as String? ?? '').trim();
    if (value.isNotEmpty) return value;
  }
  final tech = json['technician'];
  if (tech is String && tech.trim().isNotEmpty) return tech.trim();
  if (tech is Map<String, dynamic>) {
    final name = (tech['name'] as String? ?? tech['full_name'] as String? ?? '')
        .trim();
    if (name.isNotEmpty) return name;
  }
  final assignment = json['assignment'];
  if (assignment is Map<String, dynamic>) return _technicianName(assignment);
  return '';
}

BookingRating? _rating(Object? raw) {
  if (raw is num) {
    final value = raw.toInt();
    if (value < 1 || value > 5) return null;
    return BookingRating(value: value);
  }
  if (raw is! Map) return null;
  final json = Map<String, dynamic>.from(raw);
  final n = json['value'] ?? json['rating_value'] ?? json['stars'];
  final value = n is num ? n.toInt() : int.tryParse('$n') ?? 0;
  if (value < 1 || value > 5) return null;
  return BookingRating.fromJson(json);
}

List<BookingHistoryEvent> _history(Map<String, dynamic> json) {
  final raw =
      json['status_history'] ??
      json['history'] ??
      json['timeline'] ??
      json['progress'];
  if (raw is! List) return const [];
  final out = <BookingHistoryEvent>[];
  for (final row in raw) {
    if (row is! Map) continue;
    final map = Map<String, dynamic>.from(row);
    final at = DateTime.tryParse(
      '${map['at'] ?? map['created_at'] ?? map['occurred_at'] ?? ''}',
    )?.toLocal();
    if (at == null) continue;
    final label = (map['label'] as String? ?? map['name'] as String? ?? '')
        .trim();
    final mapped = label.isNotEmpty
        ? label
        : _historyLabel('${map['to_status'] ?? map['status'] ?? ''}');
    if (mapped.isEmpty) continue;
    out.add(BookingHistoryEvent(label: mapped, at: at));
  }
  out.sort((a, b) => b.at.compareTo(a.at));
  return out;
}

String _historyLabel(String status) {
  return switch (status) {
    'PAID' => 'Payment received',
    'ASSIGNED' => 'Technician assigned',
    'IN_PROGRESS' => 'Service started',
    'COMPLETED' => 'Service completed',
    'CANCELLED' => 'Booking cancelled',
    'PENDING_ASSIGNMENT' => 'Service confirmed',
    _ => '',
  };
}

enum BookingDetailChipKind { live, neutral, warm, flat }

class BookingDetailChip {
  const BookingDetailChip({
    required this.label,
    required this.kind,
    required this.dot,
  });

  final String label;
  final BookingDetailChipKind kind;
  final bool dot;

  Color get color => switch (kind) {
    BookingDetailChipKind.live => BlueColors.white,
    BookingDetailChipKind.neutral => BlueColors.chipInk,
    BookingDetailChipKind.warm => BlueColors.unavailableInk,
    BookingDetailChipKind.flat => BlueColors.placeholder,
  };

  Color get background => switch (kind) {
    BookingDetailChipKind.live => BlueColors.ink,
    BookingDetailChipKind.neutral => BlueColors.chipSurface,
    BookingDetailChipKind.warm => BlueColors.unavailableSurface,
    BookingDetailChipKind.flat => BlueColors.areaLocked,
  };

  Color get border => switch (kind) {
    BookingDetailChipKind.live => BlueColors.ink,
    BookingDetailChipKind.neutral => BlueColors.border,
    BookingDetailChipKind.warm => BlueColors.unavailableLine,
    BookingDetailChipKind.flat => BlueColors.dateFullLine,
  };
}

class BookingDetailItemView {
  const BookingDetailItemView({
    required this.name,
    required this.config,
    required this.amount,
    required this.qty,
    required this.chip,
    required this.tech,
    required this.a11y,
    required this.serviceUuid,
    required this.serviceCode,
    required this.serviceSlug,
  });

  final String name;
  final String config;
  final String amount;
  final String qty;
  final BookingDetailChip? chip;
  final String tech;
  final String a11y;
  final String serviceUuid;
  final String serviceCode;
  final String serviceSlug;
}

class BookingDetailEventView {
  const BookingDetailEventView({
    required this.label,
    required this.at,
    required this.current,
    required this.rail,
  });

  final String label;
  final String at;
  final bool current;
  final bool rail;

  FontWeight get weight => current ? FontWeight.w700 : FontWeight.w500;

  Color get color => current ? BlueColors.ink : BlueColors.muted;

  Color get dotBg => current ? BlueColors.ink : BlueColors.white;

  Color get dotBorder => current ? BlueColors.ink : BlueColors.checkLine;

  double get pad => rail ? 16 : 0;
}

class BookingDetailActionView {
  const BookingDetailActionView({
    required this.id,
    required this.label,
    required this.primary,
  });

  final String id;
  final String label;
  final bool primary;
}

class BookingDetailView {
  const BookingDetailView({
    required this.reference,
    required this.chip,
    required this.headline,
    required this.nextUp,
    required this.alertText,
    required this.alertCta,
    required this.apptDate,
    required this.apptWindow,
    required this.apptNote,
    required this.apptMuted,
    required this.itemsTitle,
    required this.items,
    required this.locTitle,
    required this.locLine1,
    required this.locLine2,
    required this.locExtra,
    required this.contact,
    required this.timeline,
    required this.payState,
    required this.payUnpaid,
    required this.payAmount,
    required this.payNote,
    required this.actions,
    required this.bookAgainSlug,
    required this.bookAgainName,
  });

  final String reference;
  final BookingDetailChip chip;
  final String headline;
  final String nextUp;
  final String alertText;
  final String alertCta;
  final String apptDate;
  final String apptWindow;
  final String apptNote;
  final bool apptMuted;
  final String itemsTitle;
  final List<BookingDetailItemView> items;
  final String locTitle;
  final String locLine1;
  final String locLine2;
  final String locExtra;
  final String contact;
  final List<BookingDetailEventView> timeline;
  final String payState;
  final bool payUnpaid;
  final String payAmount;
  final String payNote;
  final List<BookingDetailActionView> actions;
  final String bookAgainSlug;
  final String bookAgainName;

  bool get hasAlert => alertText.isNotEmpty;

  bool get hasContact => contact.isNotEmpty;

  bool get hasTimeline => timeline.isNotEmpty;

  bool get hasPayment => payState.isNotEmpty || payAmount.isNotEmpty;

  factory BookingDetailView.fromBooking(Booking booking, DateTime now) {
    final items = booking.items;
    final slots = items.map(_itemSlot).toSet();
    final mixed = items.length > 1 && slots.length > 1;
    final assigned =
        booking.status == 'ASSIGNED' ||
        items.any((item) => item.isAssigned || item.technicianName.isNotEmpty);
    final awaiting =
        booking.status == 'AWAITING_PAYMENT' ||
        booking.status == 'PENDING_PAYMENT';
    final inProgress = booking.status == 'IN_PROGRESS';
    final completed = booking.status == 'COMPLETED';
    final cancelled = booking.status == 'CANCELLED';
    final weekday = _weekdays[booking.slot.startsAt.weekday - 1];
    final startDay = DateTime(
      booking.slot.startsAt.year,
      booking.slot.startsAt.month,
      booking.slot.startsAt.day,
    );
    final today = DateTime(now.year, now.month, now.day);
    final isToday = startDay == today;
    final finished = items.where((item) => item.isCompleted).length;
    final remaining = items.length - finished;
    final money = _money(booking.total, booking.currency);
    final refundMoney = booking.refundDue == null
        ? ''
        : _money(booking.refundDue!.amount, booking.currency);

    final chip = mixed
        ? const BookingDetailChip(
            label: 'Partly complete',
            kind: BookingDetailChipKind.live,
            dot: true,
          )
        : cancelled
        ? const BookingDetailChip(
            label: 'Cancelled',
            kind: BookingDetailChipKind.flat,
            dot: false,
          )
        : completed
        ? const BookingDetailChip(
            label: 'Completed',
            kind: BookingDetailChipKind.neutral,
            dot: false,
          )
        : inProgress
        ? const BookingDetailChip(
            label: 'In progress',
            kind: BookingDetailChipKind.live,
            dot: true,
          )
        : awaiting
        ? const BookingDetailChip(
            label: 'Awaiting payment',
            kind: BookingDetailChipKind.warm,
            dot: false,
          )
        : const BookingDetailChip(
            label: 'Scheduled',
            kind: BookingDetailChipKind.neutral,
            dot: false,
          );

    late final String headline;
    late final String nextUp;
    if (cancelled) {
      headline = 'This booking was cancelled';
      nextUp =
          'It was cancelled before the service took place. Nothing further will happen with it.';
    } else if (awaiting) {
      headline = 'Payment is needed to hold this service';
      nextUp = 'Your appointment is provisional until payment is complete.';
    } else if (completed) {
      headline = items.length > 1
          ? 'These services are complete'
          : 'This service is complete';
      final finishedAt = booking.completedAt ?? booking.statusChangedAt;
      nextUp = finishedAt == null
          ? 'Thanks for booking with BLUE.'
          : 'The technician finished at ${_clock(finishedAt)}. Thanks for booking with BLUE.';
    } else if (inProgress && mixed) {
      headline = _partlyHeadline(finished, remaining);
      nextUp =
          'Your technicians are working through this booking. Each service updates on its own.';
    } else if (inProgress) {
      headline = 'Your service is underway';
      final started = booking.statusChangedAt ?? booking.slot.startsAt;
      nextUp =
          'The technician arrived at ${_clock(started)}. We\'ll update this page when the work is finished.';
    } else if (assigned) {
      headline = items.length > 1
          ? 'Technicians are assigned to your services'
          : 'A technician is assigned to your service';
      nextUp = 'They\'ll arrive within your two-hour window on $weekday.';
    } else {
      headline = items.length > 1
          ? 'Your services are scheduled for $weekday'
          : 'Your service is scheduled for $weekday';
      nextUp =
          'We\'ll confirm the technician closer to the day. Nothing else is needed from you.';
    }

    final apptDate = inProgress && isToday
        ? 'Today · $weekday, ${booking.slot.startsAt.day} ${_months[booking.slot.startsAt.month - 1]}'
        : '$weekday, ${booking.slot.startsAt.day} ${_months[booking.slot.startsAt.month - 1]}';
    final apptWindow =
        '${_clock(booking.slot.startsAt)} – ${_clock(booking.slot.endsAt)}';
    final startedAt = booking.statusChangedAt ?? booking.slot.startsAt;
    final finishedAt = booking.completedAt ?? booking.statusChangedAt;
    final apptNote = cancelled
        ? 'Cancelled before this window'
        : awaiting
        ? 'Provisional until payment completes'
        : inProgress
        ? 'Started at ${_clock(startedAt)}'
        : completed && finishedAt != null
        ? 'Finished at ${_clock(finishedAt)}'
        : mixed
        ? 'Combined window for all ${_word(items.length)} services'
        : 'Technician arrives within the window';

    final itemViews = [
      for (final item in items)
        _itemView(item, booking.currency, showChip: mixed),
    ];

    String payState;
    String payNote;
    var payUnpaid = false;
    var payAmount = money;
    if (awaiting) {
      payState = 'Not paid';
      payNote = 'Nothing has been charged.';
      payUnpaid = true;
    } else if (cancelled && booking.refundDue != null) {
      payState = 'Refunded';
      payAmount = refundMoney.isNotEmpty ? refundMoney : money;
      payNote = '';
    } else {
      payState = 'Paid';
      payNote = '';
    }

    final cancellable = !completed && !cancelled;
    final actions = <BookingDetailActionView>[
      if (completed && booking.canRate)
        const BookingDetailActionView(
          id: 'rate',
          label: 'Rate your service',
          primary: true,
        ),
      if (completed && booking.hasRating)
        BookingDetailActionView(
          id: 'rated',
          label: 'See your rating',
          primary: !booking.canRate,
        ),
      if (completed)
        const BookingDetailActionView(
          id: 'again',
          label: 'Book this service again',
          primary: false,
        ),
      if (cancellable)
        const BookingDetailActionView(
          id: 'cancel',
          label: 'Cancel this booking',
          primary: false,
        ),
      const BookingDetailActionView(
        id: 'help',
        label: 'Get help with this booking',
        primary: false,
      ),
    ];

    final first = items.isEmpty ? null : items.first;
    return BookingDetailView(
      reference: _visibleRef(booking.bookingNumber),
      chip: chip,
      headline: headline,
      nextUp: nextUp,
      alertText: awaiting
          ? 'Payment for this booking hasn\'t completed yet. Until it does we can\'t guarantee the $weekday window.'
          : '',
      alertCta: awaiting ? 'Complete payment' : '',
      apptDate: apptDate,
      apptWindow: apptWindow,
      apptNote: apptNote,
      apptMuted: cancelled,
      itemsTitle: items.length > 1
          ? '${items.length} services in this booking'
          : 'Service',
      items: itemViews,
      locTitle: _locTitle(booking.location),
      locLine1: _locLine1(booking.location),
      locLine2: _locLine2(booking.location),
      locExtra: booking.location.notes,
      contact: booking.location.visitContactPhone,
      timeline: _timeline(booking, now),
      payState: payState,
      payUnpaid: payUnpaid,
      payAmount: payAmount,
      payNote: payNote,
      actions: actions,
      bookAgainSlug: first?.service.slug ?? '',
      bookAgainName: first?.service.name ?? '',
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

const _words = [
  'zero',
  'one',
  'two',
  'three',
  'four',
  'five',
  'six',
  'seven',
  'eight',
  'nine',
  'ten',
];

String _word(int count) {
  if (count >= 0 && count < _words.length) return _words[count];
  return '$count';
}

String _cap(String value) {
  if (value.isEmpty) return value;
  return '${value[0].toUpperCase()}${value.substring(1)}';
}

String _partlyHeadline(int finished, int remaining) {
  if (finished <= 0) {
    return remaining == 1
        ? 'This service is still to come'
        : 'These services are still underway';
  }
  final left = remaining == 1
      ? 'one is still to come'
      : '${_word(remaining)} are still to come';
  if (finished == 1) return 'One service is finished, $left';
  return '${_cap(_word(finished))} services are finished, $left';
}

String _itemSlot(BookingItem item) {
  return switch (item.status) {
    'COMPLETED' => 'Completed',
    'IN_PROGRESS' => 'In progress',
    'CANCELLED' => 'Cancelled',
    _ => 'Scheduled',
  };
}

BookingDetailChip _itemChip(BookingItem item) {
  return switch (item.status) {
    'COMPLETED' => const BookingDetailChip(
      label: 'Completed',
      kind: BookingDetailChipKind.neutral,
      dot: false,
    ),
    'IN_PROGRESS' => const BookingDetailChip(
      label: 'In progress',
      kind: BookingDetailChipKind.live,
      dot: true,
    ),
    'CANCELLED' => const BookingDetailChip(
      label: 'Cancelled',
      kind: BookingDetailChipKind.flat,
      dot: false,
    ),
    _ => const BookingDetailChip(
      label: 'Scheduled',
      kind: BookingDetailChipKind.neutral,
      dot: false,
    ),
  };
}

BookingDetailItemView _itemView(
  BookingItem item,
  BookingCurrency currency, {
  required bool showChip,
}) {
  final amount = _money(item.pricing.lineTotal, currency);
  final unit = _money(item.pricing.unitTotal, currency);
  final qty = item.quantity > 1
      ? (unit.isEmpty ? '×${item.quantity}' : '×${item.quantity} · $unit each')
      : '';
  final config = _config(item.pricing.adjustments);
  final chip = showChip ? _itemChip(item) : null;
  final tech = item.technicianName.isEmpty
      ? ''
      : (item.isCompleted
            ? 'Completed by ${item.technicianName}'
            : 'Assigned to ${item.technicianName}');
  final a11y = [
    item.service.name,
    if (chip != null) chip.label,
    if (config.isNotEmpty) config,
    if (amount.isNotEmpty) amount,
    if (tech.isNotEmpty) tech,
  ].join('. ');
  return BookingDetailItemView(
    name: item.service.name,
    config: config,
    amount: amount,
    qty: qty,
    chip: chip,
    tech: tech,
    a11y: a11y,
    serviceUuid: item.service.uuid,
    serviceCode: item.service.code,
    serviceSlug: item.service.slug,
  );
}

String _config(List<Map<String, dynamic>> adjustments) {
  final parts = <String>[];
  for (final row in adjustments) {
    final code = '${row['rule_code'] ?? ''}'.toUpperCase();
    final label = '${row['label'] ?? ''}'.trim();
    if (label.isEmpty) continue;
    if (code == 'BASE' || label.toLowerCase() == 'base price') continue;
    parts.add(label);
  }
  return parts.join(' · ');
}

String _money(String raw, BookingCurrency currency) {
  final amount = double.tryParse(raw.trim());
  if (amount == null) return '';
  final sign = amount < 0 ? '-' : '';
  final abs = amount.abs();
  final whole = abs.truncate();
  var digits = '$whole';
  final grouped = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    final remaining = digits.length - i;
    if (i != 0 && remaining % 3 == 0) grouped.write(',');
    grouped.write(digits[i]);
  }
  var fraction = abs.toStringAsFixed(currency.decimalPlaces);
  fraction = fraction.contains('.') ? fraction.split('.').last : '';
  fraction = fraction.replaceFirst(RegExp(r'0+$'), '');
  final number = fraction.isEmpty ? grouped.toString() : '$grouped.$fraction';
  final code = currency.code.trim().isEmpty ? 'AED' : currency.code.trim();
  return '$code $sign$number';
}

String _locTitle(BookingLocation location) {
  final type = location.propertyTypeName;
  final other = location.otherPropertyTypeName;
  if (type.toUpperCase() == 'OTHER' && other.isNotEmpty) return other;
  if (type.isNotEmpty && other.isNotEmpty && type.toUpperCase() != 'OTHER') {
    return '$type · $other';
  }
  return type;
}

String _locLine1(BookingLocation location) {
  final parts = <String>[];
  if (location.building.isNotEmpty) parts.add(location.building);
  if (location.unitNumber.isNotEmpty) {
    final unit = location.unitNumber.toLowerCase().startsWith('unit ')
        ? location.unitNumber
        : 'Unit ${location.unitNumber}';
    parts.add(unit);
  }
  if (parts.isEmpty && location.addressLine.isNotEmpty) {
    return location.addressLine;
  }
  return parts.join(', ');
}

String _locLine2(BookingLocation location) {
  final parts = <String>[];
  if (location.streetName.isNotEmpty) parts.add(location.streetName);
  if (location.areaName.isNotEmpty) parts.add(location.areaName);
  if (location.cityName.isNotEmpty) parts.add(location.cityName);
  return parts.join(', ');
}

List<BookingDetailEventView> _timeline(Booking booking, DateTime now) {
  final events = <({String label, DateTime at})>[];
  if (booking.history.isNotEmpty) {
    for (final event in booking.history) {
      events.add((label: event.label, at: event.at));
    }
  } else {
    final created = booking.createdAt;
    final validCreated = created.millisecondsSinceEpoch > 0;
    if (booking.cancelledAt != null) {
      events.add((label: 'Booking cancelled', at: booking.cancelledAt!));
    }
    if (booking.completedAt != null) {
      events.add((label: 'Service completed', at: booking.completedAt!));
    }
    if (booking.items.length > 1) {
      for (final item in booking.items) {
        if (item.completedAt != null) {
          events.add((
            label: '${item.service.name} completed',
            at: item.completedAt!,
          ));
        } else if (item.isInProgress && booking.statusChangedAt != null) {
          events.add((
            label: '${item.service.name} started',
            at: booking.statusChangedAt!,
          ));
        }
      }
    } else if (booking.status == 'IN_PROGRESS' &&
        booking.statusChangedAt != null) {
      events.add((label: 'Service started', at: booking.statusChangedAt!));
    }
    if (booking.status == 'ASSIGNED' && booking.statusChangedAt != null) {
      events.add((
        label: booking.items.length > 1
            ? 'Technicians assigned'
            : 'Technician assigned',
        at: booking.statusChangedAt!,
      ));
    }
    if (validCreated) {
      if (booking.status == 'AWAITING_PAYMENT' ||
          booking.status == 'PENDING_PAYMENT') {
        events.add((label: 'Booking created', at: created));
      } else {
        events.add((label: 'Service confirmed', at: created));
        events.add((label: 'Payment received', at: created));
      }
    }
  }

  events.sort((a, b) => b.at.compareTo(a.at));
  final seen = <String>{};
  final unique = <({String label, DateTime at})>[];
  for (final event in events) {
    final key = '${event.label}|${event.at.millisecondsSinceEpoch}';
    if (!seen.add(key)) continue;
    unique.add(event);
  }
  return [
    for (var i = 0; i < unique.length; i++)
      BookingDetailEventView(
        label: unique[i].label,
        at: _eventWhen(unique[i].at, now),
        current: i == 0,
        rail: i < unique.length - 1,
      ),
  ];
}

class CancellationPreview {
  const CancellationPreview({
    required this.cancellable,
    required this.reasonCode,
    this.paidAmount,
    this.refundPercentage,
    this.refundAmount,
    this.currencyCode,
    this.currencySymbol,
    this.decimalPlaces = 2,
  });

  final bool cancellable;
  final String reasonCode;
  final String? paidAmount;
  final int? refundPercentage;
  final String? refundAmount;
  final String? currencyCode;
  final String? currencySymbol;
  final int decimalPlaces;

  factory CancellationPreview.fromJson(Map<String, dynamic> json) {
    final currency = json['currency'] as Map<String, dynamic>?;
    final refund = json['refund'] as Map<String, dynamic>?;

    return CancellationPreview(
      cancellable: json['cancellable'] == true,
      reasonCode: json['reason_code'] as String? ?? '',
      paidAmount: json['paid_amount'] as String?,
      refundPercentage: (refund?['percentage'] as num?)?.toInt(),
      refundAmount: refund?['amount'] as String?,
      currencyCode: currency?['code'] as String?,
      currencySymbol: currency?['symbol'] as String?,
      decimalPlaces: (currency?['decimal_places'] as num?)?.toInt() ?? 2,
    );
  }

  String get summary {
    if (!cancellable) {
      return switch (reasonCode) {
        'APPOINTMENT_STARTED' => 'This booking can no longer be cancelled because the appointment has started.',
        'APPOINTMENT_PASSED' => 'This booking can no longer be cancelled because the appointment time has passed.',
        _ => 'This booking cannot be cancelled right now.',
      };
    }
    if (refundAmount != null &&
        refundAmount!.isNotEmpty &&
        refundPercentage != null) {
      final code = currencyCode ?? '';
      final amount = refundAmount!;
      if (code.isEmpty) {
        return 'You will receive a $refundPercentage% refund of $amount.';
      }
      return 'You will receive a $refundPercentage% refund of $code $amount.';
    }
    if (reasonCode == 'CONTRACT_ENTITLEMENT') {
      return 'This contract booking will be cancelled. No payment refund applies.';
    }
    return 'You can cancel this booking. No refund applies under the current policy.';
  }
}

String _eventWhen(DateTime value, DateTime now) {
  final day = DateTime(value.year, value.month, value.day);
  final today = DateTime(now.year, now.month, now.day);
  if (day == today) return 'Today, ${_clock(value)}';
  return '${value.day} ${_shortMonths[value.month - 1]}, ${_clock(value)}';
}
