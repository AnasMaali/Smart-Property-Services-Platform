import '../../bookings/data/booking_models.dart';

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

const rateServiceWords = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'];

String rateServiceWord(int stars) {
  if (stars < 1 || stars > 5) return '';
  return rateServiceWords[stars];
}

class AlreadyRatedView {
  const AlreadyRatedView({
    required this.title,
    required this.reference,
    required this.dateLabel,
    required this.unitsLabel,
    required this.stars,
    required this.word,
    required this.submittedLabel,
    required this.comment,
    this.statusLabel = 'Completed',
  });

  final String statusLabel;
  final String title;
  final String reference;
  final String dateLabel;
  final String unitsLabel;
  final int stars;
  final String word;
  final String submittedLabel;
  final String comment;

  String get metaRest {
    final parts = [
      if (dateLabel.isNotEmpty) dateLabel,
      if (unitsLabel.isNotEmpty) unitsLabel,
    ];
    if (parts.isEmpty) return '';
    return parts.join(' · ');
  }

  factory AlreadyRatedView.fromBooking(Booking booking) {
    final rating = booking.rating;
    final item = booking.items.isEmpty ? null : booking.items.first;
    final when = booking.completedAt ?? booking.slot.startsAt;
    final submitted = rating?.submittedAt ?? when;
    final quantity = item?.quantity ?? 1;
    return AlreadyRatedView(
      title: item?.service.name ?? '',
      reference: _ref(booking.bookingNumber),
      dateLabel: _day(when),
      unitsLabel: quantity <= 0
          ? ''
          : (quantity == 1 ? '1 unit' : '$quantity units'),
      stars: rating?.value ?? 0,
      word: rating?.word ?? '',
      submittedLabel: 'Submitted ${_day(submitted)}',
      comment: rating?.comment ?? '',
    );
  }
}

String _ref(String value) {
  final trimmed = value.trim();
  if (trimmed.isEmpty) return '';
  if (RegExp(
    r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$',
    caseSensitive: false,
  ).hasMatch(trimmed)) {
    return '';
  }
  return trimmed;
}

String _day(DateTime value) {
  return '${value.day} ${_shortMonths[value.month - 1]} ${value.year}';
}

enum ServiceRatingState { rated, rateable, locked }

class BookingServiceRatingView {
  const BookingServiceRatingView({
    required this.itemUuid,
    required this.name,
    required this.meta,
    required this.state,
    this.stars = 0,
    this.word = '',
    this.comment = '',
  });

  final String itemUuid;
  final String name;
  final String meta;
  final ServiceRatingState state;
  final int stars;
  final String word;
  final String comment;

  bool get isRated => state == ServiceRatingState.rated;

  bool get isRateable => state == ServiceRatingState.rateable;

  bool get isLocked => state == ServiceRatingState.locked;

  BookingServiceRatingView copyWith({
    ServiceRatingState? state,
    int? stars,
    String? word,
    String? comment,
  }) {
    return BookingServiceRatingView(
      itemUuid: itemUuid,
      name: name,
      meta: meta,
      state: state ?? this.state,
      stars: stars ?? this.stars,
      word: word ?? this.word,
      comment: comment ?? this.comment,
    );
  }
}

class BookingDetailRatingsView {
  const BookingDetailRatingsView({
    required this.statusLabel,
    required this.statusLive,
    required this.title,
    required this.reference,
    required this.metaRest,
    required this.dateLabel,
    required this.services,
    this.helper = "Each service is rated on its own, whenever you're ready.",
  });

  final String statusLabel;
  final bool statusLive;
  final String title;
  final String reference;
  final String metaRest;
  final String dateLabel;
  final List<BookingServiceRatingView> services;
  final String helper;

  factory BookingDetailRatingsView.fromBooking(Booking booking) {
    final items = booking.items;
    final anyItemRated = items.any((item) => item.hasRating);
    var usedBookingRating = false;
    final services = <BookingServiceRatingView>[
      for (final item in items)
        _serviceView(
          item,
          bookingRating: anyItemRated ? null : booking.rating,
          claimBookingRating: () {
            if (usedBookingRating) return false;
            usedBookingRating = true;
            return true;
          },
        ),
    ];
    return BookingDetailRatingsView(
      statusLabel: _bookingStatus(booking.status),
      statusLive: booking.status == 'IN_PROGRESS',
      title: _placeTitle(booking.location),
      reference: _ref(booking.bookingNumber),
      metaRest: _slotRest(booking),
      dateLabel: _day(booking.slot.startsAt),
      services: services,
    );
  }
}

BookingServiceRatingView _serviceView(
  BookingItem item, {
  required BookingRating? bookingRating,
  required bool Function() claimBookingRating,
}) {
  final own = item.rating;
  final inherited =
      own == null &&
          item.isCompleted &&
          bookingRating != null &&
          claimBookingRating()
      ? bookingRating
      : null;
  final rating = own ?? inherited;
  final status = _itemStatus(item);
  if (rating != null) {
    final units = _units(item.quantity);
    return BookingServiceRatingView(
      itemUuid: item.uuid,
      name: item.service.name,
      meta: units.isEmpty ? status : '$status · $units',
      state: ServiceRatingState.rated,
      stars: rating.value,
      word: rating.word,
      comment: rating.comment,
    );
  }
  if (item.isCompleted) {
    final units = _units(item.quantity);
    return BookingServiceRatingView(
      itemUuid: item.uuid,
      name: item.service.name,
      meta: units.isEmpty ? status : '$status · $units',
      state: ServiceRatingState.rateable,
    );
  }
  if (item.isCancelled) {
    return BookingServiceRatingView(
      itemUuid: item.uuid,
      name: item.service.name,
      meta: status,
      state: ServiceRatingState.locked,
    );
  }
  return BookingServiceRatingView(
    itemUuid: item.uuid,
    name: item.service.name,
    meta: "$status · you can rate it once it's finished",
    state: ServiceRatingState.locked,
  );
}

String _placeTitle(BookingLocation location) {
  final type = location.propertyTypeName.trim();
  final other = location.otherPropertyTypeName.trim();
  final area = location.areaName.trim();
  final place = type.toUpperCase() == 'OTHER' && other.isNotEmpty
      ? other
      : type;
  if (place.isNotEmpty && area.isNotEmpty) return '$place · $area';
  if (place.isNotEmpty) return place;
  return area;
}

String _slotRest(Booking booking) {
  final date = _day(booking.slot.startsAt);
  final window =
      '${_clock24(booking.slot.startsAt)}–${_clock24(booking.slot.endsAt)}';
  return '$date · $window';
}

String _clock24(DateTime value) {
  final hour = value.hour.toString().padLeft(2, '0');
  final minute = value.minute.toString().padLeft(2, '0');
  return '$hour:$minute';
}

String _units(int quantity) {
  if (quantity <= 1) return '';
  return '$quantity units';
}

String _bookingStatus(String status) {
  return switch (status) {
    'IN_PROGRESS' => 'In progress',
    'COMPLETED' => 'Completed',
    'CANCELLED' => 'Cancelled',
    'AWAITING_PAYMENT' || 'PENDING_PAYMENT' => 'Awaiting payment',
    _ => 'Scheduled',
  };
}

String _itemStatus(BookingItem item) {
  return switch (item.status) {
    'COMPLETED' => 'Completed',
    'IN_PROGRESS' => 'In progress',
    'CANCELLED' => 'Cancelled',
    _ => 'Scheduled',
  };
}
