import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../data/checkout_models.dart';
import 'widgets/checkout_appointment_widgets.dart';
import 'widgets/checkout_widgets.dart';

class CheckoutAppointmentPage extends StatefulWidget {
  const CheckoutAppointmentPage({
    super.key,
    this.checkout,
    this.slotsLoader,
    this.confirmMode = AppointmentConfirmMode.hold,
  });

  final CheckoutSnapshot? checkout;

  /// When set, loads slots from this callback instead of checkout.slots().
  final Future<List<CheckoutSlot>> Function()? slotsLoader;

  /// [AppointmentConfirmMode.hold] reserves via checkout hold (default).
  /// [AppointmentConfirmMode.pick] pops with the selected [CheckoutSlot].
  final AppointmentConfirmMode confirmMode;

  @override
  State<CheckoutAppointmentPage> createState() =>
      _CheckoutAppointmentPageState();
}

enum AppointmentConfirmMode { hold, pick }

class _CheckoutAppointmentPageState extends State<CheckoutAppointmentPage> {
  final _dateScroll = ScrollController();

  var _loading = true;
  var _error = false;
  var _holding = false;
  var _conflict = false;
  var _visibleDays = 7;
  List<CheckoutSlot> _slots = const [];
  DateTime _selectedDay = appointmentDay(DateTime.now());
  CheckoutSlot? _selectedSlot;
  String? _conflictDayLabel;

  CheckoutAppointment? get _hold => widget.checkout?.appointment;

  bool get _changing => _hold != null;

  @override
  void initState() {
    super.initState();
    final hold = _hold;
    if (hold != null) {
      _selectedDay = appointmentDay(hold.slot.startsAt);
    }
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _dateScroll.dispose();
    super.dispose();
  }

  List<DateTime> _horizon() {
    final now = DateTime.now();
    final today = appointmentDay(now);
    var last = today.add(const Duration(days: 13));
    for (final slot in _slots) {
      final day = appointmentDay(slot.startsAt);
      if (day.isAfter(last)) last = day;
    }
    final days = <DateTime>[];
    for (
      var day = today;
      !day.isAfter(last);
      day = day.add(const Duration(days: 1))
    ) {
      days.add(day);
    }
    return days;
  }

  List<CheckoutSlot> _slotsOn(DateTime day) {
    return _slots
        .where((slot) => sameAppointmentDay(slot.startsAt, day))
        .toList()
      ..sort((a, b) => a.startsAt.compareTo(b.startsAt));
  }

  List<DateTime> _visibleHorizon() {
    final days = _horizon();
    final count = _visibleDays.clamp(1, days.length);
    return days.take(count).toList();
  }

  DateTime? _nextOpenAfter(DateTime day) {
    final days = _horizon();
    for (final item in days) {
      if (item.isAfter(day) && _slotsOn(item).isNotEmpty) return item;
    }
    return null;
  }

  void _ensureVisible(DateTime day) {
    final days = _horizon();
    final index = days.indexWhere((item) => sameAppointmentDay(item, day));
    if (index < 0) return;
    if (index >= _visibleDays) {
      _visibleDays = math.min(((index ~/ 7) + 1) * 7, days.length);
    }
  }

  void _scrollTo(DateTime day) {
    final days = _visibleHorizon();
    final index = days.indexWhere((item) => sameAppointmentDay(item, day));
    if (index < 0 || !_dateScroll.hasClients) return;
    final offset = index * (BlueDimens.appointmentDateWidth + 9);
    _dateScroll.animateTo(
      offset,
      duration: BlueMotion.of(context, appointmentCrossfade),
      curve: Curves.easeOut,
    );
  }

  void _selectFirstAvailable() {
    final hold = _hold;
    if (hold != null) {
      _selectedDay = appointmentDay(hold.slot.startsAt);
      CheckoutSlot? match;
      for (final slot in _slots) {
        if (slot.uuid == hold.slot.uuid) {
          match = slot;
          break;
        }
      }
      _selectedSlot = match;
      _ensureVisible(_selectedDay);
      return;
    }
    for (final day in _horizon()) {
      if (_slotsOn(day).isNotEmpty) {
        _selectedDay = day;
        _ensureVisible(day);
        return;
      }
    }
    _selectedDay = _horizon().first;
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) {
      setState(() {
        _loading = true;
        _error = false;
      });
    }
    try {
      final loader = widget.slotsLoader;
      final slots = loader != null
          ? await loader()
          : await AppScope.of(context).checkout.slots();
      if (!mounted) return;
      setState(() {
        _slots = slots;
        _loading = false;
        _error = false;
        if (!silent) _selectFirstAvailable();
        final still = _selectedSlot;
        if (still != null && slots.every((slot) => slot.uuid != still.uuid)) {
          _selectedSlot = null;
        }
      });
    } on ApiException {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = true;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = true;
      });
    }
  }

  void _pickDate(DateTime day) {
    if (_holding) return;
    setState(() {
      _selectedDay = day;
      _selectedSlot = null;
      _conflict = false;
    });
  }

  void _pickSlot(CheckoutSlot slot) {
    if (_holding) return;
    setState(() {
      _selectedSlot = slot;
      _conflict = false;
    });
  }

  void _jumpTo(DateTime day) {
    if (_holding) return;
    setState(() {
      _selectedDay = day;
      _selectedSlot = null;
      _conflict = false;
      _ensureVisible(day);
    });
    WidgetsBinding.instance.addPostFrameCallback((_) => _scrollTo(day));
  }

  void _later() {
    if (_holding) return;
    final days = _horizon();
    setState(() {
      _visibleDays = math.min(_visibleDays + 7, days.length);
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!_dateScroll.hasClients) return;
      _dateScroll.animateTo(
        _dateScroll.position.maxScrollExtent,
        duration: BlueMotion.of(context, appointmentCrossfade),
        curve: Curves.easeOut,
      );
    });
  }

  Future<void> _reserve() async {
    final slot = _selectedSlot;
    if (slot == null || _holding) return;

    if (widget.confirmMode == AppointmentConfirmMode.pick) {
      Navigator.of(context).pop(slot);
      return;
    }

    setState(() => _holding = true);
    try {
      await AppScope.of(context).checkout.holdSlot(slot.uuid);
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (error) {
      if (!mounted) return;
      final race = error.statusCode == 422 || error.statusCode == 404;
      setState(() {
        _holding = false;
        if (race) {
          _conflict = true;
          _conflictDayLabel =
              '${appointmentShortDay(_selectedDay)} ${_selectedDay.day}';
          _selectedSlot = null;
        }
      });
      await _load(silent: true);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _holding = false;
        _conflict = true;
        _conflictDayLabel =
            '${appointmentShortDay(_selectedDay)} ${_selectedDay.day}';
        _selectedSlot = null;
      });
      await _load(silent: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final today = appointmentDay(DateTime.now());
    final days = _visibleHorizon();
    final daySlots = _slotsOn(_selectedDay);
    final emptyHorizon = !_loading && !_error && _slots.isEmpty;
    final noTimes = !_loading && !_error && !emptyHorizon && daySlots.isEmpty;
    final showGroups =
        !_loading && !_error && !emptyHorizon && daySlots.isNotEmpty;
    final showSticky = !emptyHorizon;
    final chosen = _selectedSlot;
    final nextOpen = noTimes ? _nextOpenAfter(_selectedDay) : null;

    final dateViews = [for (final day in days) _dateView(day, today)];

    final groups = <AppointmentGroupView>[];
    if (showGroups) {
      const order = ['Morning', 'Afternoon', 'Evening'];
      for (final period in order) {
        final rows = <AppointmentSlotView>[];
        for (final slot in daySlots) {
          if (appointmentPeriod(slot.startsAt) != period) continue;
          final on = chosen?.uuid == slot.uuid;
          rows.add(
            AppointmentSlotView(
              slot: slot,
              label: appointmentWindowLabel(slot.startsAt, slot.endsAt),
              selected: on,
              a11y:
                  '${appointmentSpokenWindow(slot.startsAt, slot.endsAt)}, ${on ? 'selected' : 'available'}',
            ),
          );
        }
        if (rows.isEmpty) continue;
        groups.add(
          AppointmentGroupView(
            label: period,
            a11y:
                '$period times on ${_weekdays[_selectedDay.weekday - 1]} ${_selectedDay.day} ${_months[_selectedDay.month - 1]}',
            slots: rows,
          ),
        );
      }
    }

    final hold = _hold;
    final ctaEnabled = chosen != null;
    final summaryTop = chosen != null
        ? appointmentSummaryDate(chosen.startsAt)
        : (_changing ? 'Keeping current time' : 'No time selected');
    final summaryBottom = chosen != null
        ? appointmentWindowLabel(chosen.startsAt, chosen.endsAt)
        : (_changing
              ? 'Pick a new window to replace it'
              : 'Select a time to continue');

    final timesNote = (emptyHorizon || _loading || _error || noTimes)
        ? ''
        : 'Two-hour windows. The technician arrives inside the window you pick.';

    final bottom = MediaQuery.paddingOf(context).bottom;
    final barBottom = bottom < 30 ? 30.0 : bottom;
    final barHeight = showSticky
        ? (12 + BlueDimens.checkoutCtaHeight + barBottom)
        : 0.0;
    final conflictLift = _conflict ? 72.0 : 20.0;
    final scrollBottom = (showSticky ? barHeight : 28) + conflictLift;

    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        bottom: false,
        child: BlueEnter(
          duration: BlueMotion.rise,
          offset: const Offset(0, 0.018),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(
                height: 52,
                child: Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: BlueDimens.checkoutGutter,
                  ),
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: Semantics(
                      button: true,
                      label: 'Back to checkout',
                      child: CheckoutBackButton(
                        onPressed: () => Navigator.pop(context),
                      ),
                    ),
                  ),
                ),
              ),
              Expanded(
                child: Stack(
                  children: [
                    ListView(
                      physics: const BouncingScrollPhysics(
                        parent: AlwaysScrollableScrollPhysics(),
                      ),
                      padding: EdgeInsets.fromLTRB(0, 6, 0, scrollBottom),
                      children: [
                        Padding(
                          padding: const EdgeInsets.symmetric(
                            horizontal: BlueDimens.checkoutGutter,
                          ),
                          child: CheckoutTitle(
                            title: 'Choose appointment',
                            subtitle: appointmentContextLine(widget.checkout),
                          ),
                        ),
                        if (_changing && hold != null)
                          AppointmentHoldCard(
                            text: appointmentHoldLine(
                              hold.slot.startsAt,
                              hold.slot.endsAt,
                            ),
                          ),
                        AppointmentDateHeader(
                          monthLabel: appointmentMonthLabel(_selectedDay),
                        ),
                        AppointmentDateStrip(
                          dates: dateViews,
                          controller: _dateScroll,
                          enabled: !_holding,
                          onPick: _pickDate,
                          onLater: _later,
                        ),
                        const AppointmentTimesHeader(),
                        AppointmentTimesNote(text: timesNote),
                        AnimatedSwitcher(
                          duration: BlueMotion.of(
                            context,
                            appointmentCrossfade,
                          ),
                          switchInCurve: Curves.easeOut,
                          switchOutCurve: Curves.easeOut,
                          child: _timesBody(
                            emptyHorizon: emptyHorizon,
                            noTimes: noTimes,
                            showGroups: showGroups,
                            groups: groups,
                            nextOpen: nextOpen,
                          ),
                        ),
                      ],
                    ),
                    if (_conflict)
                      Positioned(
                        left: 16,
                        right: 16,
                        bottom: barHeight + 14,
                        child: _ConflictRise(
                          child: AppointmentConflictNotice(
                            text:
                                'That time was taken a moment ago. We\'ve refreshed ${_conflictDayLabel ?? '${appointmentShortDay(_selectedDay)} ${_selectedDay.day}'} — pick another window.',
                          ),
                        ),
                      ),
                  ],
                ),
              ),
              if (showSticky)
                AppointmentReserveBar(
                  summaryTop: summaryTop,
                  summaryBottom: summaryBottom,
                  summaryColor: chosen != null
                      ? BlueColors.ink
                      : BlueColors.placeholder,
                  ctaText: _holding
                      ? 'Reserving…'
                      : widget.confirmMode == AppointmentConfirmMode.pick
                      ? 'Confirm this time'
                      : (_changing ? 'Reserve new time' : 'Reserve this time'),
                  enabled: ctaEnabled,
                  reserving: _holding,
                  onReserve: _reserve,
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _timesBody({
    required bool emptyHorizon,
    required bool noTimes,
    required bool showGroups,
    required List<AppointmentGroupView> groups,
    required DateTime? nextOpen,
  }) {
    if (_loading) {
      return const AppointmentTimesSkeleton(key: ValueKey('skeleton'));
    }
    if (_error) {
      return AppointmentTimesError(
        key: const ValueKey('error'),
        onRetry: () => _load(),
      );
    }
    if (emptyHorizon) {
      return AppointmentNoAvailability(
        key: const ValueKey('empty'),
        areaName: widget.checkout?.location?.area.name ?? '',
        onRetry: () => _load(),
        onContact: () => showAppointmentContactSheet(context),
      );
    }
    if (noTimes) {
      final title =
          'No times on ${appointmentShortDay(_selectedDay)} ${_selectedDay.day} ${_months[_selectedDay.month - 1]}';
      final body = nextOpen == null
          ? 'That day is fully booked. Try one of the later dates.'
          : 'That day is fully booked. The next opening is ${_weekdays[nextOpen.weekday - 1]} ${nextOpen.day} ${_months[nextOpen.month - 1]}.';
      final jump = nextOpen == null
          ? null
          : 'Jump to ${appointmentShortDay(nextOpen)} ${nextOpen.day}';
      return AppointmentNoTimes(
        key: ValueKey('none-${_selectedDay.millisecondsSinceEpoch}'),
        title: title,
        body: body,
        jumpLabel: jump,
        onJump: nextOpen == null ? null : () => _jumpTo(nextOpen),
      );
    }
    if (showGroups) {
      return AppointmentSlotGroups(
        key: ValueKey('groups-${_selectedDay.millisecondsSinceEpoch}'),
        groups: groups,
        enabled: !_holding,
        onPick: _pickSlot,
      );
    }
    return const SizedBox.shrink(key: ValueKey('blank'));
  }

  AppointmentDateView _dateView(DateTime day, DateTime today) {
    final slots = _slotsOn(day);
    final full = !_loading && !_error && slots.isEmpty;
    final selected =
        !_loading &&
        !_error &&
        _slots.isNotEmpty &&
        sameAppointmentDay(day, _selectedDay);
    final tag = appointmentDayTag(day, today, full: full);
    final count = slots.length;
    final a11y =
        '${_weekdays[day.weekday - 1]} ${day.day} ${_months[day.month - 1]}, ${count == 0 ? 'no times available' : '$count times available'}${selected ? ', selected' : ''}';
    return AppointmentDateView(
      day: appointmentTileDay(day),
      number: '${day.day}',
      tag: tag,
      selected: selected,
      full: full,
      a11y: a11y,
      value: day,
    );
  }
}

class _ConflictRise extends StatelessWidget {
  const _ConflictRise({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final duration = BlueMotion.of(context, appointmentConflict);
    if (duration == Duration.zero) return child;
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: duration,
      curve: Curves.easeOut,
      builder: (context, t, child) {
        return Opacity(
          opacity: t,
          child: Transform.translate(
            offset: Offset(0, -4 * (1 - t)),
            child: child,
          ),
        );
      },
      child: child,
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
