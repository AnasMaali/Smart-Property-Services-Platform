import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../bookings/data/booking_models.dart';
import 'home_icons.dart';

class HomeCurrentBookingCard extends StatelessWidget {
  const HomeCurrentBookingCard({
    super.key,
    required this.booking,
    required this.onDetails,
    required this.onReschedule,
    this.now,
  });

  final Booking booking;
  final VoidCallback onDetails;
  final VoidCallback onReschedule;
  final DateTime? now;

  static Booking? pick(List<Booking> bookings, [DateTime? now]) {
    final clock = now ?? DateTime.now();
    final live = bookings.where((row) => row.status == 'IN_PROGRESS').toList();
    if (live.isNotEmpty) {
      live.sort((a, b) => a.slot.startsAt.compareTo(b.slot.startsAt));
      return live.first;
    }
    final upcoming = bookings.where((row) => row.isCurrent).toList();
    upcoming.sort((a, b) => a.slot.startsAt.compareTo(b.slot.startsAt));
    if (upcoming.isEmpty) return null;
    upcoming.sort((a, b) {
      final aSoon = a.slot.startsAt.isBefore(clock) ? 0 : 1;
      final bSoon = b.slot.startsAt.isBefore(clock) ? 0 : 1;
      if (aSoon != bSoon) return aSoon - bSoon;
      return a.slot.startsAt.compareTo(b.slot.startsAt);
    });
    return upcoming.first;
  }

  @override
  Widget build(BuildContext context) {
    final clock = now ?? DateTime.now();
    final start = booking.slot.startsAt;
    final end = booking.slot.endsAt;
    final remaining = start.difference(clock);
    final inProgress = booking.status == 'IN_PROGRESS';
    final assigned =
        booking.status == 'ASSIGNED' ||
        booking.items.any(
          (item) => item.isAssigned || item.technicianName.isNotEmpty,
        );
    final awaiting =
        booking.status == 'AWAITING_PAYMENT' ||
        booking.status == 'PENDING_PAYMENT';
    final startsSoon = remaining.inHours < 24 && remaining.inSeconds > 0;
    final canReschedule = !inProgress && !awaiting;

    final statusLabel = awaiting
        ? 'Awaiting payment'
        : inProgress
        ? 'In progress'
        : assigned
        ? 'Assigned to Technician'
        : 'Scheduled';

    final bannerTitle = inProgress ? 'ON SITE' : 'STARTS IN';
    final bannerClock = inProgress
        ? _clock(start)
        : remaining.isNegative
        ? 'Due now'
        : _countdown(remaining);
    final whenLabel = _dayLabel(start, clock);
    final note = awaiting
        ? 'Payment is needed to hold this visit'
        : inProgress
        ? 'Your technician is on site'
        : 'Your technician visit is coming soon';

    final created = booking.createdAt.millisecondsSinceEpoch > 0
        ? booking.createdAt
        : start.subtract(const Duration(hours: 8));
    final span = start.difference(created).inSeconds;
    final elapsed = clock.difference(created).inSeconds;
    final progress = inProgress
        ? () {
            final window = end.difference(start).inSeconds;
            if (window <= 0) return 0.7;
            return (clock.difference(start).inSeconds / window).clamp(
              0.08,
              0.95,
            );
          }()
        : span <= 0
        ? 0.35
        : (elapsed / span).clamp(0.08, 0.92);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const Expanded(
              child: Text(
                'Current Booking',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 18,
                  height: 1.2,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 18 * -0.02,
                  color: BlueColors.ink,
                ),
              ),
            ),
            if (startsSoon || inProgress)
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 6,
                ),
                decoration: BoxDecoration(
                  color: BlueColors.reminderFill,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: const Row(
                  children: [
                    BlueGlyphIcon(
                      BlueGlyph.bell,
                      size: 13,
                      color: BlueColors.reminderInk,
                      strokeWidth: 1.9,
                    ),
                    SizedBox(width: 5),
                    Text(
                      'Reminder',
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: BlueColors.reminderInk,
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
        const SizedBox(height: 12),
        DecoratedBox(
          decoration: BoxDecoration(
            color: BlueColors.white,
            borderRadius: BorderRadius.circular(24),
            boxShadow: const [
              BoxShadow(
                color: BlueColors.cardShadow,
                blurRadius: 22,
                offset: Offset(0, 10),
              ),
            ],
          ),
          child: Column(
            children: [
              Container(
                width: double.infinity,
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 14),
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      BlueColors.bookingBanner,
                      BlueColors.bookingBannerEnd,
                    ],
                  ),
                  borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
                ),
                child: Column(
                  children: [
                    Row(
                      children: [
                        Container(
                          width: 42,
                          height: 42,
                          decoration: BoxDecoration(
                            color: BlueColors.white.withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          alignment: Alignment.center,
                          child: const BlueGlyphIcon(
                            BlueGlyph.clock,
                            size: 20,
                            color: BlueColors.white,
                            strokeWidth: 1.8,
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                bannerTitle,
                                style: TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 11,
                                  fontWeight: FontWeight.w700,
                                  letterSpacing: 11 * 0.08,
                                  color: BlueColors.white.withValues(
                                    alpha: 0.72,
                                  ),
                                ),
                              ),
                              const SizedBox(height: 3),
                              Text(
                                bannerClock,
                                style: const TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 22,
                                  height: 1.05,
                                  fontWeight: FontWeight.w800,
                                  color: BlueColors.white,
                                  fontFeatures: [FontFeature.tabularFigures()],
                                ),
                              ),
                            ],
                          ),
                        ),
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          children: [
                            Text(
                              whenLabel,
                              style: TextStyle(
                                fontFamily: BlueFonts.jakarta,
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                                color: BlueColors.white.withValues(alpha: 0.72),
                              ),
                            ),
                            const SizedBox(height: 3),
                            Text(
                              _clock(start),
                              style: const TextStyle(
                                fontFamily: BlueFonts.jakarta,
                                fontSize: 16,
                                fontWeight: FontWeight.w800,
                                color: BlueColors.white,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    ClipRRect(
                      borderRadius: BorderRadius.circular(4),
                      child: SizedBox(
                        height: 5,
                        child: Stack(
                          children: [
                            ColoredBox(
                              color: BlueColors.white.withValues(alpha: 0.18),
                              child: const SizedBox.expand(),
                            ),
                            FractionallySizedBox(
                              widthFactor: progress,
                              child: const ColoredBox(
                                color: BlueColors.gold,
                                child: SizedBox.expand(),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        BlueGlyphIcon(
                          BlueGlyph.info,
                          size: 13,
                          color: BlueColors.white.withValues(alpha: 0.8),
                          strokeWidth: 1.8,
                        ),
                        const SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            note,
                            style: TextStyle(
                              fontFamily: BlueFonts.jakarta,
                              fontSize: 12,
                              fontWeight: FontWeight.w500,
                              color: BlueColors.white.withValues(alpha: 0.86),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: _Meta(
                            label: 'Booking ID',
                            value: _ref(booking.bookingNumber, booking.uuid),
                          ),
                        ),
                        Container(
                          width: 1,
                          height: 38,
                          margin: const EdgeInsets.symmetric(horizontal: 12),
                          color: BlueColors.sheetHairline,
                        ),
                        Expanded(
                          flex: 2,
                          child: _Meta(
                            label: 'Date & Time',
                            value:
                                '${_longDate(start)} | ${_clock(start)} – ${_clock(end)}',
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    const Text(
                      'Services in this booking',
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 12.5,
                        fontWeight: FontWeight.w500,
                        color: BlueColors.muted,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        for (final item in booking.items)
                          Container(
                            padding: const EdgeInsets.fromLTRB(8, 6, 12, 6),
                            decoration: BoxDecoration(
                              color: BlueColors.serviceTagFill,
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                BlueGlyphIcon(
                                  BlueGlyphIcon.forCategory(item.service.code),
                                  size: 13,
                                  strokeWidth: 1.8,
                                ),
                                const SizedBox(width: 6),
                                Text(
                                  item.service.name,
                                  style: const TextStyle(
                                    fontFamily: BlueFonts.jakarta,
                                    fontSize: 12.5,
                                    fontWeight: FontWeight.w700,
                                    color: BlueColors.ink,
                                  ),
                                ),
                              ],
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    Wrap(
                      spacing: 8,
                      runSpacing: 10,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      alignment: WrapAlignment.spaceBetween,
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 10,
                            vertical: 7,
                          ),
                          decoration: BoxDecoration(
                            color: BlueColors.statusTagFill,
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Container(
                                width: 7,
                                height: 7,
                                decoration: const BoxDecoration(
                                  color: BlueColors.ink,
                                  shape: BoxShape.circle,
                                ),
                              ),
                              const SizedBox(width: 6),
                              Text(
                                statusLabel,
                                style: const TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 11.5,
                                  fontWeight: FontWeight.w700,
                                  color: BlueColors.ink,
                                ),
                              ),
                            ],
                          ),
                        ),
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            if (canReschedule) ...[
                              BluePressable(
                                onPressed: onReschedule,
                                scale: 0.97,
                                child: Container(
                                  height: 36,
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 12,
                                  ),
                                  alignment: Alignment.center,
                                  decoration: BoxDecoration(
                                    color: BlueColors.white,
                                    borderRadius: BorderRadius.circular(12),
                                    border: Border.all(
                                      color: BlueColors.border,
                                    ),
                                  ),
                                  child: const Text(
                                    'Reschedule',
                                    style: TextStyle(
                                      fontFamily: BlueFonts.jakarta,
                                      fontSize: 12.5,
                                      fontWeight: FontWeight.w700,
                                      color: BlueColors.muted,
                                    ),
                                  ),
                                ),
                              ),
                              const SizedBox(width: 8),
                            ],
                            BluePressable(
                              onPressed: onDetails,
                              scale: 0.97,
                              child: Container(
                                height: 36,
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 12,
                                ),
                                alignment: Alignment.center,
                                decoration: BoxDecoration(
                                  color: BlueColors.ink,
                                  borderRadius: BorderRadius.circular(12),
                                ),
                                child: const Row(
                                  children: [
                                    Text(
                                      'Details',
                                      style: TextStyle(
                                        fontFamily: BlueFonts.jakarta,
                                        fontSize: 12.5,
                                        fontWeight: FontWeight.w700,
                                        color: BlueColors.white,
                                      ),
                                    ),
                                    SizedBox(width: 2),
                                    BlueGlyphIcon(
                                      BlueGlyph.chevronRight,
                                      size: 12,
                                      color: BlueColors.gold,
                                      strokeWidth: 2.4,
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 11.5,
            fontWeight: FontWeight.w500,
            color: BlueColors.muted,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          value,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13,
            height: 1.3,
            fontWeight: FontWeight.w800,
            color: BlueColors.ink,
          ),
        ),
      ],
    );
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

String _countdown(Duration value) {
  final hours = value.inHours;
  final minutes = value.inMinutes.remainder(60);
  final seconds = value.inSeconds.remainder(60);
  return '${hours}h ${minutes}m ${seconds.toString().padLeft(2, '0')}s';
}

String _clock(DateTime value) {
  final hour24 = value.hour;
  final minute = value.minute.toString().padLeft(2, '0');
  final hour12 = hour24 % 12 == 0 ? 12 : hour24 % 12;
  final period = hour24 >= 12 ? 'PM' : 'AM';
  return '$hour12:$minute $period';
}

String _dayLabel(DateTime start, DateTime now) {
  final startDay = DateTime(start.year, start.month, start.day);
  final today = DateTime(now.year, now.month, now.day);
  if (startDay == today) return 'Today';
  final tomorrow = today.add(const Duration(days: 1));
  if (startDay == tomorrow) return 'Tomorrow';
  return _shortDays[start.weekday - 1];
}

String _longDate(DateTime value) {
  return '${_shortDays[value.weekday - 1]}, ${value.day} ${_shortMonths[value.month - 1]} ${value.year}';
}

String _ref(String number, String uuid) {
  if (number.trim().isNotEmpty) return number.trim();
  if (uuid.length >= 8) return 'BLU-${uuid.substring(0, 5).toUpperCase()}';
  return 'Booking';
}
