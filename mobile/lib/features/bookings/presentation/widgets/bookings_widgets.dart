import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../data/booking_models.dart';

const _goldLine = LinearGradient(
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

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

class BookingsTitle extends StatelessWidget {
  const BookingsTitle({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.symmetric(horizontal: BlueDimens.homeGutter),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Text(
            'Bookings',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: BlueDimens.checkoutTitle,
              height: 1.18,
              fontWeight: FontWeight.w700,
              letterSpacing: BlueDimens.checkoutTitle * -0.022,
              color: BlueColors.ink,
            ),
          ),
          SizedBox(width: 10),
          Padding(
            padding: EdgeInsets.only(bottom: 7),
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: _goldLine,
                borderRadius: BorderRadius.all(Radius.circular(1)),
              ),
              child: SizedBox(
                width: BlueDimens.checkoutGoldWidth,
                height: BlueDimens.checkoutGoldHeight,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class BookingsRefreshHint extends StatelessWidget {
  const BookingsRefreshHint({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        0,
        BlueDimens.homeGutter,
        14,
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          _RefreshSpinner(),
          SizedBox(width: 9),
          Text(
            'Checking for updates',
            style: TextStyle(
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

class _RefreshSpinner extends StatefulWidget {
  const _RefreshSpinner();

  @override
  State<_RefreshSpinner> createState() => _RefreshSpinnerState();
}

class _RefreshSpinnerState extends State<_RefreshSpinner>
    with SingleTickerProviderStateMixin {
  late final AnimationController _spin;

  @override
  void initState() {
    super.initState();
    _spin = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 750),
    )..repeat();
  }

  @override
  void dispose() {
    _spin.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return RotationTransition(
      turns: _spin,
      child: const CustomPaint(size: Size(15, 15), painter: _SpinnerPainter()),
    );
  }
}

class _SpinnerPainter extends CustomPainter {
  const _SpinnerPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Rect.fromLTWH(1, 1, size.width - 2, size.height - 2);
    canvas.drawArc(
      rect,
      0,
      6.2832,
      false,
      Paint()
        ..color = BlueColors.badgeBorder
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2,
    );
    canvas.drawArc(
      rect,
      -1.57,
      1.6,
      false,
      Paint()
        ..color = BlueColors.ink
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeCap = StrokeCap.round,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class BookingsTabs extends StatelessWidget {
  const BookingsTabs({
    super.key,
    required this.currentOn,
    required this.currentCount,
    required this.onCurrent,
    required this.onPast,
  });

  final bool currentOn;
  final int currentCount;
  final VoidCallback onCurrent;
  final VoidCallback onPast;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        16,
        BlueDimens.homeGutter,
        0,
      ),
      child: Row(
        children: [
          _Tab(
            label: 'Current',
            on: currentOn,
            count: currentCount > 0 ? '$currentCount' : '',
            a11y:
                'Current bookings, $currentCount${currentOn ? ', selected' : ''}',
            onPressed: onCurrent,
          ),
          const SizedBox(width: 8),
          _Tab(
            label: 'Past',
            on: !currentOn,
            count: '',
            a11y: 'Past bookings${!currentOn ? ', selected' : ''}',
            onPressed: onPast,
          ),
        ],
      ),
    );
  }
}

class _Tab extends StatelessWidget {
  const _Tab({
    required this.label,
    required this.on,
    required this.count,
    required this.a11y,
    required this.onPressed,
  });

  final String label;
  final bool on;
  final String count;
  final String a11y;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: on,
      label: a11y,
      excludeSemantics: true,
      child: GestureDetector(
        onTap: () {
          if (!on) BlueMotion.tap();
          onPressed();
        },
        child: AnimatedContainer(
          duration: BlueMotion.of(context, const Duration(milliseconds: 180)),
          curve: BlueMotion.curve,
          constraints: const BoxConstraints(minHeight: 40),
          padding: const EdgeInsets.symmetric(horizontal: 15),
          decoration: BoxDecoration(
            color: on ? BlueColors.ink : BlueColors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: on ? BlueColors.ink : BlueColors.border),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              AnimatedDefaultTextStyle(
                duration: BlueMotion.of(
                  context,
                  const Duration(milliseconds: 180),
                ),
                curve: BlueMotion.curve,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 13.5,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 13.5 * -0.005,
                  height: 1,
                  color: on ? BlueColors.white : BlueColors.chipInk,
                ),
                child: Text(label),
              ),
              if (count.isNotEmpty) ...[
                const SizedBox(width: 7),
                AnimatedDefaultTextStyle(
                  duration: BlueMotion.of(
                    context,
                    const Duration(milliseconds: 180),
                  ),
                  curve: BlueMotion.curve,
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    height: 1,
                    fontFeatures: const [FontFeature.tabularFigures()],
                    color: on
                        ? BlueColors.selectedMute
                        : BlueColors.ctaDisabledText,
                  ),
                  child: Text(count),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class BookingsSectionLabel extends StatelessWidget {
  const BookingsSectionLabel({super.key, required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        0,
        BlueDimens.homeGutter,
        11,
      ),
      child: Text(
        label.toUpperCase(),
        style: const TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: 12,
          fontWeight: FontWeight.w700,
          letterSpacing: 12 * 0.06,
          height: 1,
          color: BlueColors.placeholder,
        ),
      ),
    );
  }
}

class BookingsRow extends StatefulWidget {
  const BookingsRow({super.key, required this.row, required this.onPressed});

  final BookingRowView row;
  final VoidCallback onPressed;

  @override
  State<BookingsRow> createState() => _BookingsRowState();
}

class _BookingsRowState extends State<BookingsRow> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    final row = widget.row;
    final chip = row.chip;
    return Semantics(
      button: true,
      label: row.a11y,
      excludeSemantics: true,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: (_) => setState(() => _down = true),
        onTapUp: (_) => setState(() => _down = false),
        onTapCancel: () => setState(() => _down = false),
        onTap: () {
          BlueMotion.tap();
          widget.onPressed();
        },
        child: AnimatedContainer(
          duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
          curve: BlueMotion.curve,
          width: double.infinity,
          padding: const EdgeInsets.fromLTRB(
            BlueDimens.homeGutter,
            15,
            BlueDimens.homeGutter,
            15,
          ),
          decoration: BoxDecoration(
            color: _down ? BlueColors.press : Colors.transparent,
            border: const Border(top: BorderSide(color: BlueColors.navLine)),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(
                      spacing: 8,
                      runSpacing: 6,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        if (row.dot)
                          Container(
                            width: BlueDimens.checkoutHoldDot,
                            height: BlueDimens.checkoutHoldDot,
                            decoration: const BoxDecoration(
                              gradient: _goldDot,
                              borderRadius: BorderRadius.all(
                                Radius.circular(4),
                              ),
                            ),
                          ),
                        DecoratedBox(
                          decoration: BoxDecoration(
                            color: chip.background,
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: chip.border),
                          ),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 2,
                            ),
                            child: Text(
                              row.status,
                              style: TextStyle(
                                fontFamily: BlueFonts.jakarta,
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                                letterSpacing: 12 * 0.02,
                                height: 1.25,
                                color: chip.color,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 5),
                    Text(
                      row.name,
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 15,
                        height: 1.3,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 15 * -0.008,
                        color: row.nameColor,
                      ),
                    ),
                    if (row.extra.isNotEmpty) ...[
                      const SizedBox(height: 5),
                      Text(
                        row.extra,
                        style: const TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 12.5,
                          height: 1.4,
                          fontWeight: FontWeight.w500,
                          color: BlueColors.muted,
                        ),
                      ),
                    ],
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Text(
                        row.when,
                        style: TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 13,
                          height: 1.45,
                          fontWeight: row.whenWeight,
                          color: row.whenColor,
                        ),
                      ),
                    ),
                    const SizedBox(height: 5),
                    Wrap(
                      spacing: 7,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        if (row.where.isNotEmpty)
                          Text(
                            row.where,
                            style: const TextStyle(
                              fontFamily: BlueFonts.jakarta,
                              fontSize: 12.5,
                              height: 1.4,
                              fontWeight: FontWeight.w400,
                              color: BlueColors.placeholder,
                            ),
                          ),
                        if (row.ref.isNotEmpty) ...[
                          Container(
                            width: 3,
                            height: 3,
                            decoration: const BoxDecoration(
                              color: BlueColors.locationDot,
                              shape: BoxShape.circle,
                            ),
                          ),
                          Text(
                            row.ref,
                            style: const TextStyle(
                              fontFamily: BlueFonts.mono,
                              fontSize: 11,
                              fontWeight: FontWeight.w400,
                              letterSpacing: 11 * 0.03,
                              height: 1.2,
                              color: BlueColors.ctaDisabledText,
                            ),
                          ),
                        ],
                      ],
                    ),
                    if (row.itemNote.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Text(
                          row.itemNote,
                          style: const TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 12.5,
                            height: 1.4,
                            fontWeight: FontWeight.w500,
                            color: BlueColors.chipInk,
                          ),
                        ),
                      ),
                  ],
                ),
              ),
              const Padding(
                padding: EdgeInsets.only(top: 24, left: 12),
                child: BlueGlyphIcon(
                  BlueGlyph.chevronRight,
                  size: 17,
                  color: BlueColors.rowChevron,
                  strokeWidth: 2.1,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class BookingsSectionFoot extends StatelessWidget {
  const BookingsSectionFoot({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        16,
        BlueDimens.homeGutter,
        0,
      ),
      child: Text(
        text,
        style: const TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: 12.5,
          height: 1.5,
          fontWeight: FontWeight.w400,
          color: BlueColors.placeholder,
        ),
      ),
    );
  }
}

class BookingsInkButton extends StatelessWidget {
  const BookingsInkButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.height = 50,
    this.horizontal = 24,
  });

  final String label;
  final VoidCallback onPressed;
  final double height;
  final double horizontal;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      duration: const Duration(milliseconds: 140),
      child: Container(
        height: height,
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

class BookingsGhostButton extends StatelessWidget {
  const BookingsGhostButton({
    super.key,
    required this.label,
    required this.onPressed,
  });

  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      duration: const Duration(milliseconds: 140),
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

class BookingsEmptyState extends StatelessWidget {
  const BookingsEmptyState({super.key, required this.onBrowse});

  final VoidCallback onBrowse;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        110,
        BlueDimens.homeGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const BlueGlyphIcon(
            BlueGlyph.bookings,
            size: 28,
            color: BlueColors.chevron,
            strokeWidth: 1.7,
          ),
          const SizedBox(height: 16),
          const Text(
            'No bookings yet',
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
            constraints: BoxConstraints(maxWidth: 282),
            child: Text(
              "When you book a service, it'll appear here with its date, time and status.",
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 20),
          BookingsInkButton(label: 'Browse services', onPressed: onBrowse),
        ],
      ),
    );
  }
}

class BookingsNoCurrentState extends StatelessWidget {
  const BookingsNoCurrentState({
    super.key,
    required this.onBrowse,
    required this.onSeePast,
  });

  final VoidCallback onBrowse;
  final VoidCallback onSeePast;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        44,
        BlueDimens.homeGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Nothing scheduled right now',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 17,
              height: 1.3,
              fontWeight: FontWeight.w700,
              letterSpacing: 17 * -0.016,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 7),
          ConstrainedBox(
            constraints: BoxConstraints(maxWidth: 290),
            child: Text(
              'You have no upcoming services. Your past bookings are still here whenever you need them.',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 18),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              BookingsInkButton(
                label: 'Browse services',
                height: 48,
                horizontal: 22,
                onPressed: onBrowse,
              ),
              BookingsGhostButton(
                label: 'See past bookings',
                onPressed: onSeePast,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class BookingsErrorState extends StatelessWidget {
  const BookingsErrorState({super.key, required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        92,
        BlueDimens.homeGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const BlueGlyphIcon(
            BlueGlyph.warning,
            size: 26,
            color: BlueColors.error,
            strokeWidth: 1.9,
          ),
          const SizedBox(height: 14),
          const Text(
            "We couldn't load your bookings",
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 19,
              height: 1.3,
              fontWeight: FontWeight.w700,
              letterSpacing: 19 * -0.018,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 7),
          ConstrainedBox(
            constraints: BoxConstraints(maxWidth: 290),
            child: Text(
              "Your bookings are safe on your account and any scheduled service still stands. Check your connection and try again.",
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 18),
          BookingsInkButton(
            label: 'Try again',
            height: 48,
            horizontal: 26,
            onPressed: onRetry,
          ),
        ],
      ),
    );
  }
}

class BookingsSkeleton extends StatefulWidget {
  const BookingsSkeleton({super.key});

  @override
  State<BookingsSkeleton> createState() => _BookingsSkeletonState();
}

class _BookingsSkeletonState extends State<BookingsSkeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  static const _rows = [
    [74.0, 178.0, 196.0, 148.0],
    [96.0, 142.0, 214.0, 132.0],
    [80.0, 196.0, 168.0, 156.0],
    [88.0, 160.0, 202.0, 140.0],
  ];

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
        return Opacity(opacity: t, child: const _SkeletonBody());
      },
    );
  }
}

class _SkeletonBody extends StatelessWidget {
  const _SkeletonBody();

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(
            BlueDimens.homeGutter,
            16,
            BlueDimens.homeGutter,
            0,
          ),
          child: Row(
            children: [
              _Bone(
                width: 96,
                height: 40,
                radius: 12,
                color: BlueColors.skeleton,
              ),
              SizedBox(width: 8),
              _Bone(
                width: 74,
                height: 40,
                radius: 12,
                color: BlueColors.skeletonAlt,
              ),
            ],
          ),
        ),
        const SizedBox(height: 24),
        for (final row in _BookingsSkeletonState._rows)
          _SkeletonRow(widths: row),
        const DecoratedBox(
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: BlueColors.navLine)),
          ),
          child: SizedBox(width: double.infinity, height: 0),
        ),
      ],
    );
  }
}

class _SkeletonRow extends StatelessWidget {
  const _SkeletonRow({required this.widths});

  final List<double> widths;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
          BlueDimens.homeGutter,
          15,
          BlueDimens.homeGutter,
          15,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _Bone(
              width: widths[0],
              height: 16,
              radius: 8,
              color: BlueColors.skeletonAlt,
            ),
            const SizedBox(height: 8),
            _Bone(
              width: widths[1],
              height: 14,
              radius: 6,
              color: BlueColors.skeleton,
            ),
            const SizedBox(height: 8),
            _Bone(
              width: widths[2],
              height: 11,
              radius: 5,
              color: BlueColors.skeletonAlt,
            ),
            const SizedBox(height: 8),
            _Bone(
              width: widths[3],
              height: 11,
              radius: 5,
              color: BlueColors.skeletonAlt,
            ),
          ],
        ),
      ),
    );
  }
}

class _Bone extends StatelessWidget {
  const _Bone({
    required this.width,
    required this.height,
    required this.radius,
    required this.color,
  });

  final double width;
  final double height;
  final double radius;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(radius),
      ),
    );
  }
}
