import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../auth/presentation/widgets/blue_sheet.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../data/checkout_models.dart';
import 'checkout_widgets.dart';

const appointmentSelect = Duration(milliseconds: 160);
const appointmentCrossfade = Duration(milliseconds: 220);
const appointmentConflict = Duration(milliseconds: 200);
const appointmentPress = Duration(milliseconds: 140);

const _goldDot = LinearGradient(
  begin: Alignment.topLeft,
  end: Alignment.bottomRight,
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

const _shortDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const _tileDays = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
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

class AppointmentDateView {
  const AppointmentDateView({
    required this.day,
    required this.number,
    required this.tag,
    required this.selected,
    required this.full,
    required this.a11y,
    required this.value,
  });

  final String day;
  final String number;
  final String tag;
  final bool selected;
  final bool full;
  final String a11y;
  final DateTime value;
}

class AppointmentSlotView {
  const AppointmentSlotView({
    required this.slot,
    required this.label,
    required this.selected,
    required this.a11y,
  });

  final CheckoutSlot slot;
  final String label;
  final bool selected;
  final String a11y;
}

class AppointmentGroupView {
  const AppointmentGroupView({
    required this.label,
    required this.a11y,
    required this.slots,
  });

  final String label;
  final String a11y;
  final List<AppointmentSlotView> slots;
}

class AppointmentHoldCard extends StatelessWidget {
  const AppointmentHoldCard({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        18,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: BlueColors.border),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Row(
                children: [
                  DecoratedBox(
                    decoration: BoxDecoration(
                      gradient: _goldDot,
                      borderRadius: BorderRadius.all(Radius.circular(4)),
                    ),
                    child: SizedBox(
                      width: BlueDimens.checkoutHoldDot,
                      height: BlueDimens.checkoutHoldDot,
                    ),
                  ),
                  SizedBox(width: 8),
                  Text(
                    'CURRENTLY RESERVED',
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 11.5,
                      fontWeight: FontWeight.w600,
                      letterSpacing: 11.5 * 0.04,
                      color: BlueColors.placeholder,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Text(
                text,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 14.5,
                  height: 1.35,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 14.5 * -0.008,
                  color: BlueColors.ink,
                ),
              ),
              const SizedBox(height: 5),
              const Text(
                'This time stays yours until a new one is confirmed. Nothing is released before then.',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  height: 1.45,
                  fontWeight: FontWeight.w400,
                  color: BlueColors.muted,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class AppointmentDateHeader extends StatelessWidget {
  const AppointmentDateHeader({super.key, required this.monthLabel});

  final String monthLabel;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        24,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.baseline,
        textBaseline: TextBaseline.alphabetic,
        children: [
          const Expanded(
            child: Text(
              'Choose a date',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 15,
                fontWeight: FontWeight.w700,
                letterSpacing: 15 * -0.01,
                color: BlueColors.ink,
              ),
            ),
          ),
          Text(
            monthLabel,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              fontWeight: FontWeight.w600,
              color: BlueColors.muted,
            ),
          ),
        ],
      ),
    );
  }
}

class AppointmentDateStrip extends StatelessWidget {
  const AppointmentDateStrip({
    super.key,
    required this.dates,
    required this.controller,
    required this.enabled,
    required this.onPick,
    required this.onLater,
  });

  final List<AppointmentDateView> dates;
  final ScrollController controller;
  final bool enabled;
  final ValueChanged<DateTime> onPick;
  final VoidCallback onLater;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: 'Appointment date',
      child: SizedBox(
        height: 92,
        child: ScrollConfiguration(
          behavior: ScrollConfiguration.of(context).copyWith(scrollbars: false),
          child: ListView.separated(
            controller: controller,
            scrollDirection: Axis.horizontal,
            physics: const BouncingScrollPhysics(
              parent: AlwaysScrollableScrollPhysics(),
            ),
            padding: const EdgeInsets.fromLTRB(
              BlueDimens.checkoutGutter,
              14,
              BlueDimens.checkoutGutter,
              2,
            ),
            itemCount: dates.length + 1,
            separatorBuilder: (_, _) => const SizedBox(width: 9),
            itemBuilder: (context, index) {
              if (index == dates.length) {
                return _LaterTile(enabled: enabled, onPressed: onLater);
              }
              final date = dates[index];
              return _DateTile(
                date: date,
                enabled: enabled,
                onPressed: () => onPick(date.value),
              );
            },
          ),
        ),
      ),
    );
  }
}

class _DateTile extends StatelessWidget {
  const _DateTile({
    required this.date,
    required this.enabled,
    required this.onPressed,
  });

  final AppointmentDateView date;
  final bool enabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final selected = date.selected;
    final full = date.full;
    final bg = selected
        ? BlueColors.ink
        : (full ? BlueColors.areaLocked : BlueColors.white);
    final border = selected
        ? BlueColors.ink
        : (full ? BlueColors.dateFullLine : BlueColors.border);
    final dayColor = selected
        ? BlueColors.selectedMute
        : (full ? BlueColors.dateFull : BlueColors.placeholder);
    final numColor = selected
        ? BlueColors.white
        : (full ? BlueColors.dateFull : BlueColors.ink);
    final tagColor = selected
        ? BlueColors.selectedMute
        : (full ? BlueColors.dateFull : BlueColors.placeholder);

    return Semantics(
      button: true,
      selected: selected,
      label: date.a11y,
      child: BluePressable(
        enabled: enabled,
        onPressed: enabled ? onPressed : null,
        scale: 1,
        haptic: enabled,
        duration: appointmentSelect,
        child: AnimatedContainer(
          duration: BlueMotion.of(context, appointmentSelect),
          curve: Curves.easeOut,
          width: BlueDimens.appointmentDateWidth,
          constraints: const BoxConstraints(
            minHeight: BlueDimens.appointmentDateHeight,
          ),
          padding: const EdgeInsets.symmetric(vertical: 8),
          decoration: BoxDecoration(
            color: bg,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: border, width: 1.5),
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                date.day,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 11 * 0.06,
                  height: 1.1,
                  color: dayColor,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                date.number,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 19,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 19 * -0.02,
                  height: 1.05,
                  color: numColor,
                ),
              ),
              if (date.tag.isNotEmpty) ...[
                const SizedBox(height: 3),
                Text(
                  date.tag,
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 10,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 10 * 0.01,
                    height: 1.1,
                    color: tagColor,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _LaterTile extends StatelessWidget {
  const _LaterTile({required this.enabled, required this.onPressed});

  final bool enabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: 'Show later dates',
      child: BluePressable(
        enabled: enabled,
        onPressed: enabled ? onPressed : null,
        scale: 1,
        duration: appointmentSelect,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: BlueColors.white,
            borderRadius: BorderRadius.circular(16),
          ),
          child: CustomPaint(
            painter: const _DashedRRectPainter(),
            child: const SizedBox(
              width: BlueDimens.appointmentDateWidth,
              height: BlueDimens.appointmentDateHeight,
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  BlueGlyphIcon(
                    BlueGlyph.chevronRight,
                    size: 16,
                    strokeWidth: 2.2,
                  ),
                  SizedBox(height: 5),
                  Text(
                    'Later',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 10.5,
                      height: 1.2,
                      fontWeight: FontWeight.w700,
                      color: BlueColors.ink,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _DashedRRectPainter extends CustomPainter {
  const _DashedRRectPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final rrect = RRect.fromLTRBR(
      0.75,
      0.75,
      size.width - 0.75,
      size.height - 0.75,
      const Radius.circular(16),
    );
    final path = Path()..addRRect(rrect);
    final paint = Paint()
      ..color = BlueColors.ghostLine
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;
    const dash = 4.0;
    const gap = 3.5;
    for (final metric in path.computeMetrics()) {
      var distance = 0.0;
      var draw = true;
      while (distance < metric.length) {
        final next = (distance + (draw ? dash : gap)).clamp(0.0, metric.length);
        if (draw) {
          canvas.drawPath(metric.extractPath(distance, next), paint);
        }
        distance = next;
        draw = !draw;
      }
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class AppointmentTimesHeader extends StatelessWidget {
  const AppointmentTimesHeader({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        24,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.baseline,
        textBaseline: TextBaseline.alphabetic,
        children: [
          Expanded(
            child: Text(
              'Available times',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 15,
                fontWeight: FontWeight.w700,
                letterSpacing: 15 * -0.01,
                color: BlueColors.ink,
              ),
            ),
          ),
          Text(
            'Gulf Standard Time',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 11.5,
              fontWeight: FontWeight.w500,
              color: BlueColors.placeholder,
            ),
          ),
        ],
      ),
    );
  }
}

class AppointmentTimesNote extends StatelessWidget {
  const AppointmentTimesNote({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    if (text.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        6,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Semantics(
        liveRegion: true,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 300),
          child: Text(
            text,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              height: 1.45,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
        ),
      ),
    );
  }
}

class AppointmentSlotGroups extends StatelessWidget {
  const AppointmentSlotGroups({
    super.key,
    required this.groups,
    required this.enabled,
    required this.onPick,
  });

  final List<AppointmentGroupView> groups;
  final bool enabled;
  final ValueChanged<CheckoutSlot> onPick;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final group in groups)
          Padding(
            padding: const EdgeInsets.fromLTRB(
              BlueDimens.checkoutGutter,
              18,
              BlueDimens.checkoutGutter,
              0,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  group.label.toUpperCase(),
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 12 * 0.06,
                    color: BlueColors.placeholder,
                  ),
                ),
                const SizedBox(height: 10),
                Semantics(
                  label: group.a11y,
                  child: Column(
                    children: [
                      for (var i = 0; i < group.slots.length; i++) ...[
                        if (i > 0) const SizedBox(height: 9),
                        _SlotRow(
                          view: group.slots[i],
                          enabled: enabled,
                          onPressed: () => onPick(group.slots[i].slot),
                        ),
                      ],
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

class _SlotRow extends StatelessWidget {
  const _SlotRow({
    required this.view,
    required this.enabled,
    required this.onPressed,
  });

  final AppointmentSlotView view;
  final bool enabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final on = view.selected;
    return Semantics(
      button: true,
      selected: on,
      label: view.a11y,
      child: BluePressable(
        enabled: enabled,
        onPressed: enabled ? onPressed : null,
        scale: 1,
        duration: appointmentSelect,
        child: AnimatedContainer(
          duration: BlueMotion.of(context, appointmentSelect),
          curve: Curves.easeOut,
          constraints: const BoxConstraints(
            minHeight: BlueDimens.appointmentSlotHeight,
          ),
          padding: const EdgeInsets.symmetric(horizontal: 16),
          decoration: BoxDecoration(
            color: on ? BlueColors.ink : BlueColors.white,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: on ? BlueColors.ink : BlueColors.border,
              width: 1.5,
            ),
          ),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  view.label,
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 14.5,
                    fontWeight: on ? FontWeight.w700 : FontWeight.w600,
                    letterSpacing: 14.5 * -0.005,
                    color: on ? BlueColors.white : BlueColors.ink,
                  ),
                ),
              ),
              if (on)
                const Padding(
                  padding: EdgeInsets.only(left: 12),
                  child: BlueGlyphIcon(
                    BlueGlyph.check,
                    size: 17,
                    color: BlueColors.white,
                    strokeWidth: 2.5,
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class AppointmentNoTimes extends StatelessWidget {
  const AppointmentNoTimes({
    super.key,
    required this.title,
    required this.body,
    required this.jumpLabel,
    required this.onJump,
  });

  final String title;
  final String body;
  final String? jumpLabel;
  final VoidCallback? onJump;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        26,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 16,
              height: 1.35,
              fontWeight: FontWeight.w700,
              letterSpacing: 16 * -0.014,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 7),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 290),
            child: Text(
              body,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          if (jumpLabel != null && onJump != null) ...[
            const SizedBox(height: 16),
            CheckoutGhostButton(label: jumpLabel!, onPressed: onJump!),
          ],
        ],
      ),
    );
  }
}

class AppointmentTimesSkeleton extends StatefulWidget {
  const AppointmentTimesSkeleton({super.key});

  @override
  State<AppointmentTimesSkeleton> createState() =>
      _AppointmentTimesSkeletonState();
}

class _AppointmentTimesSkeletonState extends State<AppointmentTimesSkeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(vsync: this, duration: BlueMotion.shimmer)
      ..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _pulse,
      builder: (context, _) {
        final t = 0.55 + (_pulse.value * 0.45);
        return Opacity(
          opacity: t,
          child: const Padding(
            padding: EdgeInsets.fromLTRB(
              BlueDimens.checkoutGutter,
              20,
              BlueDimens.checkoutGutter,
              0,
            ),
            child: _SkeletonTimes(),
          ),
        );
      },
    );
  }
}

class _SkeletonTimes extends StatelessWidget {
  const _SkeletonTimes();

  @override
  Widget build(BuildContext context) {
    return const Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _Bone(width: 78, height: 11, radius: 5),
        SizedBox(height: 12),
        _Bone(width: double.infinity, height: 56, radius: 14),
        SizedBox(height: 9),
        _Bone(
          width: double.infinity,
          height: 56,
          radius: 14,
          color: BlueColors.skeletonAlt,
        ),
        SizedBox(height: 22),
        _Bone(width: 92, height: 11, radius: 5),
        SizedBox(height: 12),
        _Bone(
          width: double.infinity,
          height: 56,
          radius: 14,
          color: BlueColors.skeletonAlt,
        ),
        SizedBox(height: 9),
        _Bone(
          width: double.infinity,
          height: 56,
          radius: 14,
          color: BlueColors.skeletonAlt,
        ),
      ],
    );
  }
}

class _Bone extends StatelessWidget {
  const _Bone({
    required this.width,
    required this.height,
    required this.radius,
    this.color = BlueColors.skeleton,
  });

  final double width;
  final double height;
  final double radius;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width.isInfinite ? double.infinity : width,
      height: height,
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(radius),
      ),
    );
  }
}

class AppointmentTimesError extends StatelessWidget {
  const AppointmentTimesError({super.key, required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        26,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            "We couldn't load available times",
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 16,
              height: 1.35,
              fontWeight: FontWeight.w700,
              letterSpacing: 16 * -0.014,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 7),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 290),
            child: const Text(
              'Your date is still selected and nothing else in this checkout is affected.',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 16),
          _InkAction(label: 'Try again', horizontal: 24, onPressed: onRetry),
        ],
      ),
    );
  }
}

class AppointmentNoAvailability extends StatelessWidget {
  const AppointmentNoAvailability({
    super.key,
    required this.areaName,
    required this.onRetry,
    required this.onContact,
  });

  final String areaName;
  final VoidCallback onRetry;
  final VoidCallback onContact;

  @override
  Widget build(BuildContext context) {
    final where = areaName.trim().isEmpty ? 'your area' : areaName.trim();
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        70,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const CustomPaint(
            size: Size(27, 27),
            painter: _CalendarMarkPainter(),
          ),
          const SizedBox(height: 16),
          const Text(
            'No appointments available yet',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 20,
              height: 1.3,
              fontWeight: FontWeight.w700,
              letterSpacing: 20 * -0.02,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 8),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 290),
            child: Text(
              'Nothing is open in the next two weeks for $where. Schedules usually open a few days ahead — check back shortly, or reach us and we\'ll arrange a time.',
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 20),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              _InkAction(
                label: 'Check again',
                horizontal: 22,
                onPressed: onRetry,
              ),
              _GhostAction(label: 'Contact BLUE', onPressed: onContact),
            ],
          ),
        ],
      ),
    );
  }
}

class _GhostAction extends StatelessWidget {
  const _GhostAction({required this.label, required this.onPressed});

  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      duration: appointmentPress,
      child: Container(
        height: 48,
        padding: const EdgeInsets.symmetric(horizontal: 20),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: BlueColors.border),
        ),
        child: Text(
          label,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 15,
            fontWeight: FontWeight.w700,
            color: BlueColors.ink,
          ),
        ),
      ),
    );
  }
}

class _InkAction extends StatelessWidget {
  const _InkAction({
    required this.label,
    required this.horizontal,
    required this.onPressed,
  });

  final String label;
  final double horizontal;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      duration: appointmentPress,
      child: Container(
        height: 48,
        padding: EdgeInsets.symmetric(horizontal: horizontal),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: BlueColors.ink,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Text(
          label,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 15,
            fontWeight: FontWeight.w700,
            color: BlueColors.white,
          ),
        ),
      ),
    );
  }
}

class _CalendarMarkPainter extends CustomPainter {
  const _CalendarMarkPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final sx = size.width / 24;
    final sy = size.height / 24;
    final paint = Paint()
      ..color = BlueColors.chevron
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.7
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final body = Path()
      ..moveTo(5 * sx, 6.5 * sy)
      ..lineTo(19 * sx, 6.5 * sy)
      ..arcToPoint(Offset(20 * sx, 7.5 * sy), radius: Radius.circular(1 * sx))
      ..lineTo(20 * sx, 20 * sy)
      ..arcToPoint(Offset(19 * sx, 21 * sy), radius: Radius.circular(1 * sx))
      ..lineTo(5 * sx, 21 * sy)
      ..arcToPoint(Offset(4 * sx, 20 * sy), radius: Radius.circular(1 * sx))
      ..lineTo(4 * sx, 7.5 * sy)
      ..arcToPoint(Offset(5 * sx, 6.5 * sy), radius: Radius.circular(1 * sx));
    canvas.drawPath(body, paint);
    canvas.drawLine(
      Offset(8.5 * sx, 4 * sy),
      Offset(8.5 * sx, 8.4 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(15.5 * sx, 4 * sy),
      Offset(15.5 * sx, 8.4 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(4.2 * sx, 12 * sy),
      Offset(19.8 * sx, 12 * sy),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class AppointmentConflictNotice extends StatelessWidget {
  const AppointmentConflictNotice({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      liveRegion: true,
      container: true,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: BlueColors.unavailableSurface,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: BlueColors.unavailableLine),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(15, 13, 15, 13),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Padding(
                padding: EdgeInsets.only(top: 1),
                child: BlueGlyphIcon(
                  BlueGlyph.info,
                  size: 15,
                  color: BlueColors.unavailableInk,
                  strokeWidth: 2.1,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  text,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12.5,
                    height: 1.45,
                    fontWeight: FontWeight.w500,
                    color: BlueColors.unavailableInk,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class AppointmentReserveBar extends StatelessWidget {
  const AppointmentReserveBar({
    super.key,
    required this.summaryTop,
    required this.summaryBottom,
    required this.summaryColor,
    required this.ctaText,
    required this.enabled,
    required this.reserving,
    required this.onReserve,
  });

  final String summaryTop;
  final String summaryBottom;
  final Color summaryColor;
  final String ctaText;
  final bool enabled;
  final bool reserving;
  final VoidCallback onReserve;

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    final canTap = enabled && !reserving;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: BlueColors.barFill,
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          BlueDimens.checkoutGutter,
          12,
          BlueDimens.checkoutGutter,
          bottom < 30 ? 30 : bottom,
        ),
        child: Row(
          children: [
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 140),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  AnimatedSwitcher(
                    duration: BlueMotion.of(context, appointmentConflict),
                    switchInCurve: Curves.easeOut,
                    switchOutCurve: Curves.easeOut,
                    child: Text(
                      summaryTop,
                      key: ValueKey(summaryTop),
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 13.5 * -0.008,
                        color: summaryColor,
                      ),
                    ),
                  ),
                  const SizedBox(height: 1),
                  Text(
                    summaryBottom,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 11.5,
                      height: 1.35,
                      fontWeight: FontWeight.w500,
                      color: BlueColors.placeholder,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: BluePressable(
                enabled: canTap,
                onPressed: canTap ? onReserve : null,
                scale: 0.99,
                duration: appointmentPress,
                child: AnimatedContainer(
                  duration: BlueMotion.of(context, appointmentSelect),
                  curve: Curves.easeOut,
                  constraints: const BoxConstraints(
                    minHeight: BlueDimens.checkoutCtaHeight,
                  ),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: !enabled
                        ? BlueColors.ctaDisabled
                        : (reserving ? BlueColors.ctaBusy : BlueColors.ink),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      if (reserving) ...[
                        const _ReserveSpinner(),
                        const SizedBox(width: 10),
                      ],
                      Flexible(
                        child: Text(
                          ctaText,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 15.5,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 15.5 * -0.005,
                            color: enabled
                                ? BlueColors.white
                                : BlueColors.ctaDisabledText,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ReserveSpinner extends StatefulWidget {
  const _ReserveSpinner();

  @override
  State<_ReserveSpinner> createState() => _ReserveSpinnerState();
}

class _ReserveSpinnerState extends State<_ReserveSpinner>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 700),
    )..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return RotationTransition(
      turns: _controller,
      child: Container(
        width: 16,
        height: 16,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          border: Border.all(color: const Color(0x59FFFFFF), width: 2),
        ),
        foregroundDecoration: const BoxDecoration(shape: BoxShape.circle),
        child: CustomPaint(painter: _ArcPainter()),
      ),
    );
  }
}

class _ArcPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round;
    canvas.drawArc(
      Rect.fromLTWH(1, 1, size.width - 2, size.height - 2),
      -1.2,
      1.6,
      false,
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

Future<void> showAppointmentContactSheet(BuildContext context) {
  return showBlueSheet<void>(
    context: context,
    builder: (context) {
      return BlueSheetPanel(
        title: 'Contact BLUE',
        onClose: () => Navigator.pop(context),
        child: const Padding(
          padding: EdgeInsets.fromLTRB(20, 8, 20, 28),
          child: Text(
            'Schedules usually open a few days ahead. Reach us and we\'ll arrange a time — your cart and location stay saved.',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14,
              height: 1.5,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
        ),
      );
    },
  );
}

DateTime appointmentDay(DateTime value) {
  return DateTime(value.year, value.month, value.day);
}

bool sameAppointmentDay(DateTime a, DateTime b) {
  return a.year == b.year && a.month == b.month && a.day == b.day;
}

String appointmentMonthLabel(DateTime value) {
  return '${_months[value.month - 1]} ${value.year}';
}

String appointmentTileDay(DateTime value) => _tileDays[value.weekday % 7];

String appointmentShortDay(DateTime value) => _shortDays[value.weekday - 1];

String appointmentSummaryDate(DateTime value) {
  return '${appointmentShortDay(value)}, ${value.day} ${_shortMonths[value.month - 1]}';
}

String appointmentHoldLine(DateTime start, DateTime end) {
  return '${appointmentSummaryDate(start)} · ${appointmentWindowLabel(start, end)}';
}

String appointmentWindowLabel(DateTime start, DateTime end) {
  return '${formatAppointmentTime(start)} – ${formatAppointmentTime(end)}';
}

String formatAppointmentTime(DateTime value) {
  final hour24 = value.hour;
  final hour12 = hour24 % 12 == 0 ? 12 : hour24 % 12;
  final minute = value.minute.toString().padLeft(2, '0');
  final period = hour24 >= 12 ? 'PM' : 'AM';
  return '$hour12:$minute $period';
}

String appointmentSpokenWindow(DateTime start, DateTime end) {
  return '${_spokenClock(start)} to ${_spokenClock(end)}';
}

String appointmentPeriod(DateTime start) {
  if (start.hour < 12) return 'Morning';
  if (start.hour < 18) return 'Afternoon';
  return 'Evening';
}

String appointmentDayTag(DateTime day, DateTime today, {required bool full}) {
  if (sameAppointmentDay(day, today)) return 'Today';
  if (sameAppointmentDay(day, today.add(const Duration(days: 1)))) {
    return 'Tmrw';
  }
  return full ? 'Full' : '';
}

String appointmentContextLine(CheckoutSnapshot? checkout) {
  if (checkout == null) return '';
  final items = checkout.items;
  var service = '';
  if (items.length == 1) {
    service = items.first.service.name.trim();
  } else if (items.length > 1) {
    service = '${items.length} services';
  }
  final location = checkout.location;
  final parts = <String>[];
  if (location != null) {
    if (location.area.name.trim().isNotEmpty) {
      parts.add(location.area.name.trim());
    }
    if (location.city.name.trim().isNotEmpty) {
      parts.add(location.city.name.trim());
    }
  }
  final place = parts.join(', ');
  if (service.isEmpty) return place;
  if (place.isEmpty) return service;
  return '$service · $place';
}

String _spokenClock(DateTime value) {
  final hour24 = value.hour;
  final hour12 = hour24 % 12 == 0 ? 12 : hour24 % 12;
  final period = hour24 >= 12 ? 'PM' : 'AM';
  if (value.minute == 0) return '$hour12 $period';
  final minute = value.minute.toString().padLeft(2, '0');
  return '$hour12:$minute $period';
}

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
