import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../../core/input/latin_digits.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../auth/presentation/widgets/blue_primary_button.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../../properties/presentation/widgets/properties_widgets.dart';
import '../../../services/presentation/widgets/services_widgets.dart';

const profileReveal = Duration(milliseconds: 180);
const profileSaveWake = Duration(milliseconds: 180);
const profileChipTick = Duration(milliseconds: 160);
const profileToastHold = Duration(milliseconds: 2600);

class ProfileBackButton extends StatelessWidget {
  const ProfileBackButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(-11, 0),
      child: ServicesBackButton(onPressed: onPressed),
    );
  }
}

class ProfileTitle extends StatelessWidget {
  const ProfileTitle({super.key});

  @override
  Widget build(BuildContext context) {
    return const PropertiesTitle(
      title: 'Profile',
      subtitle: 'The details BLUE has about you.',
    );
  }
}

class ProfileHairline extends StatelessWidget {
  const ProfileHairline({super.key});

  @override
  Widget build(BuildContext context) {
    return const ColoredBox(
      color: BlueColors.navLine,
      child: SizedBox(height: 1, width: double.infinity),
    );
  }
}

class ProfileHelper extends StatelessWidget {
  const ProfileHelper(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: const TextStyle(
        fontFamily: BlueFonts.jakarta,
        fontSize: 12.5,
        height: 1.4,
        fontWeight: FontWeight.w400,
        color: BlueColors.muted,
      ),
    );
  }
}

class ProfilePhoneRow extends StatelessWidget {
  const ProfilePhoneRow({
    super.key,
    required this.phone,
    required this.onChange,
  });

  final String phone;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Expanded(
          child: Text(
            phone,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 16.5,
              height: 1.3,
              fontWeight: FontWeight.w700,
              letterSpacing: 16.5 * 0.005,
              color: BlueColors.ink,
            ),
          ),
        ),
        const SizedBox(width: 12),
        ProfileChangeButton(onPressed: onChange),
      ],
    );
  }
}

class ProfileChangeButton extends StatelessWidget {
  const ProfileChangeButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.97,
      duration: const Duration(milliseconds: 140),
      child: Container(
        height: 44,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(999),
          border: Border.all(color: BlueColors.border),
        ),
        child: const Text(
          'Change',
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 14,
            height: 1,
            fontWeight: FontWeight.w700,
            color: BlueColors.ink,
          ),
        ),
      ),
    );
  }
}

class ProfileOptionalBadge extends StatelessWidget {
  const ProfileOptionalBadge({super.key});

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: BlueColors.white,
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: BlueColors.badgeBorder),
      ),
      child: const Padding(
        padding: EdgeInsets.fromLTRB(7, 3, 7, 3),
        child: Text(
          'OPTIONAL',
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 9.5,
            height: 1.2,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.7,
            color: BlueColors.placeholder,
          ),
        ),
      ),
    );
  }
}

class ProfileInterestChip extends StatelessWidget {
  const ProfileInterestChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onPressed,
  });

  final String label;
  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.96,
      child: AnimatedContainer(
        duration: BlueMotion.of(context, profileChipTick),
        curve: BlueMotion.curve,
        constraints: const BoxConstraints(minHeight: 38),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? BlueColors.ink : BlueColors.white,
          borderRadius: BorderRadius.circular(999),
          border: Border.all(
            color: selected ? BlueColors.ink : BlueColors.border,
          ),
        ),
        child: AnimatedDefaultTextStyle(
          duration: BlueMotion.of(context, profileChipTick),
          curve: BlueMotion.curve,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13.5,
            height: 1.2,
            fontWeight: FontWeight.w600,
            letterSpacing: 13.5 * 0.005,
            color: selected ? BlueColors.white : BlueColors.ink,
          ),
          child: Text(label, textAlign: TextAlign.center),
        ),
      ),
    );
  }
}

class ProfileMoreChip extends StatefulWidget {
  const ProfileMoreChip({
    super.key,
    required this.label,
    required this.onPressed,
  });

  final String label;
  final VoidCallback onPressed;

  @override
  State<ProfileMoreChip> createState() => _ProfileMoreChipState();
}

class _ProfileMoreChipState extends State<ProfileMoreChip> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTapDown: (_) => setState(() => _down = true),
      onTapUp: (_) => setState(() => _down = false),
      onTapCancel: () => setState(() => _down = false),
      onTap: () {
        BlueMotion.tap();
        widget.onPressed();
      },
      child: AnimatedScale(
        scale: _down ? 0.96 : 1,
        duration: BlueMotion.press,
        curve: Curves.easeOut,
        child: CustomPaint(
          painter: _DashedRRectPainter(
            color: BlueColors.dash,
            radius: 999,
            fill: _down ? BlueColors.press : Colors.transparent,
          ),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 38),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              child: Center(
                child: Text(
                  widget.label,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 13.5,
                    height: 1.2,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 13.5 * 0.005,
                    color: BlueColors.ink,
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class ProfileSaveButton extends StatelessWidget {
  const ProfileSaveButton({
    super.key,
    required this.enabled,
    required this.busy,
    required this.onPressed,
  });

  final bool enabled;
  final bool busy;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final awake = enabled || busy;
    return AnimatedOpacity(
      duration: BlueMotion.of(context, profileSaveWake),
      curve: BlueMotion.curve,
      opacity: awake ? 1 : 0.4,
      child: BluePressable(
        enabled: enabled && !busy,
        onPressed: enabled && !busy ? onPressed : null,
        scale: 0.975,
        child: AnimatedContainer(
          duration: BlueMotion.of(context, profileSaveWake),
          curve: BlueMotion.curve,
          height: BlueDimens.fieldHeight,
          decoration: BoxDecoration(
            color: busy ? BlueColors.ctaBusy : BlueColors.ink,
            borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
            boxShadow: awake
                ? const [
                    BoxShadow(
                      color: BlueColors.buttonShadow,
                      offset: Offset(0, 10),
                      blurRadius: 24,
                      spreadRadius: -16,
                    ),
                  ]
                : const [],
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              AnimatedSwitcher(
                duration: BlueMotion.tick,
                switchInCurve: BlueMotion.curve,
                child: busy
                    ? const Padding(
                        key: ValueKey('busy'),
                        padding: EdgeInsets.only(right: 11),
                        child: _SaveSpinner(),
                      )
                    : const SizedBox.shrink(key: ValueKey('idle')),
              ),
              AnimatedSwitcher(
                duration: BlueMotion.tick,
                child: Text(
                  busy ? 'Saving...' : 'Save changes',
                  key: ValueKey(busy ? 'saving' : 'save'),
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 16.5,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 16.5 * 0.005,
                    color: BlueColors.white,
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

class ProfileFailStrip extends StatelessWidget {
  const ProfileFailStrip({super.key});

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: BlueColors.unavailableSurface,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(18, 11, 18, 11),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Padding(
              padding: EdgeInsets.only(top: 1),
              child: BlueGlyphIcon(
                BlueGlyph.warning,
                size: 15,
                color: BlueColors.unavailableInk,
                strokeWidth: 1.8,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: const Text(
                "We couldn't save your profile. Your changes are still here — try again.",
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  height: 1.4,
                  fontWeight: FontWeight.w500,
                  color: BlueColors.unavailableInk,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class ProfileToast extends StatelessWidget {
  const ProfileToast({
    super.key,
    required this.visible,
    this.label = 'Profile updated',
  });

  final bool visible;
  final String label;

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return IgnorePointer(
      child: AnimatedSlide(
        duration: BlueMotion.of(context, const Duration(milliseconds: 220)),
        curve: visible ? BlueMotion.curve : BlueMotion.exitCurve,
        offset: visible ? Offset.zero : const Offset(0, 0.35),
        child: AnimatedOpacity(
          duration: BlueMotion.of(context, const Duration(milliseconds: 220)),
          curve: BlueMotion.curve,
          opacity: visible ? 1 : 0,
          child: Padding(
            padding: EdgeInsets.fromLTRB(24, 0, 24, bottom < 18 ? 18 : bottom),
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: BlueColors.ink,
                borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
                boxShadow: const [
                  BoxShadow(
                    color: BlueColors.buttonShadow,
                    offset: Offset(0, 10),
                    blurRadius: 24,
                    spreadRadius: -16,
                  ),
                ],
              ),
              child: SizedBox(
                height: BlueDimens.fieldHeight,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const CustomPaint(
                        size: Size(19, 19),
                        painter: _ToastCheckPainter(),
                      ),
                      const SizedBox(width: 11),
                      Flexible(
                        child: FittedBox(
                          fit: BoxFit.scaleDown,
                          child: Text(
                            label,
                            maxLines: 1,
                            style: const TextStyle(
                              fontFamily: BlueFonts.jakarta,
                              fontSize: 16.5,
                              fontWeight: FontWeight.w700,
                              letterSpacing: 16.5 * 0.005,
                              color: BlueColors.white,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class ProfileLoadError extends StatelessWidget {
  const ProfileLoadError({super.key, required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(24, 72, 24, 0),
      child: Column(
        children: [
          const BlueGlyphIcon(
            BlueGlyph.warning,
            size: 36,
            color: BlueColors.error,
            strokeWidth: 1.7,
          ),
          const SizedBox(height: 16),
          const Text(
            "We couldn't load your profile",
            textAlign: TextAlign.center,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 19,
              height: 1.3,
              fontWeight: FontWeight.w700,
              letterSpacing: 19 * -0.018,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 8),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 300),
            child: const Text(
              'Your details are safe — this is only a problem loading the page. Check your connection and try again.',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 22),
          BluePrimaryButton(label: 'Try again', onPressed: onRetry),
        ],
      ),
    );
  }
}

class ProfileSkeleton extends StatefulWidget {
  const ProfileSkeleton({super.key});

  @override
  State<ProfileSkeleton> createState() => _ProfileSkeletonState();
}

class _ProfileSkeletonState extends State<ProfileSkeleton>
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
            padding: EdgeInsets.fromLTRB(24, 8, 24, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _Bone(width: 72, height: 11),
                SizedBox(height: 8),
                _Bone(width: double.infinity, height: 58, radius: 20),
                SizedBox(height: 18),
                _Bone(width: 96, height: 11),
                SizedBox(height: 8),
                _Bone(width: double.infinity, height: 58, radius: 20),
                SizedBox(height: 8),
                _Bone(width: 220, height: 10),
                SizedBox(height: 22),
                _Bone(width: double.infinity, height: 1),
                SizedBox(height: 18),
                _Bone(width: 88, height: 11),
                SizedBox(height: 10),
                _Bone(width: 168, height: 16),
                SizedBox(height: 8),
                _Bone(width: 260, height: 10),
                SizedBox(height: 22),
                _Bone(width: double.infinity, height: 1),
                SizedBox(height: 18),
                _Bone(width: 170, height: 13),
                SizedBox(height: 12),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _Bone(width: 88, height: 38, radius: 999),
                    _Bone(width: 108, height: 38, radius: 999),
                    _Bone(width: 118, height: 38, radius: 999),
                    _Bone(width: 102, height: 38, radius: 999),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _Bone extends StatelessWidget {
  const _Bone({required this.width, required this.height, this.radius = 6});

  final double width;
  final double height;
  final double radius;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width.isInfinite ? double.infinity : width,
      height: height,
      decoration: BoxDecoration(
        color: BlueColors.skeleton,
        borderRadius: BorderRadius.circular(radius),
      ),
    );
  }
}

class _SaveSpinner extends StatefulWidget {
  const _SaveSpinner();

  @override
  State<_SaveSpinner> createState() => _SaveSpinnerState();
}

class _SaveSpinnerState extends State<_SaveSpinner>
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
      child: CustomPaint(size: const Size(17, 17), painter: _ArcPainter()),
    );
  }
}

class _ArcPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final rect = Rect.fromLTWH(1, 1, size.width - 2, size.height - 2);
    final track = Paint()
      ..color = const Color(0x52FFFFFF)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;
    canvas.drawCircle(rect.center, rect.width / 2, track);
    final paint = Paint()
      ..color = BlueColors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round;
    canvas.drawArc(rect, -1.2, 1.6, false, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _ToastCheckPainter extends CustomPainter {
  const _ToastCheckPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.6
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    final path = Path()
      ..moveTo(20 * sx, 6.5 * sy)
      ..lineTo(9.6 * sx, 17 * sy)
      ..lineTo(4.5 * sx, 12 * sy);
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _DashedRRectPainter extends CustomPainter {
  const _DashedRRectPainter({
    required this.color,
    required this.radius,
    required this.fill,
  });

  final Color color;
  final double radius;
  final Color fill;

  @override
  void paint(Canvas canvas, Size size) {
    final pill = (size.shortestSide / 2).clamp(0.0, radius);
    final rrect = RRect.fromLTRBR(
      0.5,
      0.5,
      size.width - 0.5,
      size.height - 0.5,
      Radius.circular(pill),
    );
    if (fill.a > 0) {
      canvas.drawRRect(rrect, Paint()..color = fill);
    }
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1;
    final path = Path()..addRRect(rrect);
    for (final metric in path.computeMetrics()) {
      var distance = 0.0;
      while (distance < metric.length) {
        final next = math.min(distance + 4, metric.length);
        canvas.drawPath(metric.extractPath(distance, next), paint);
        distance += 8;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _DashedRRectPainter oldDelegate) =>
      oldDelegate.fill != fill;
}

String formatProfilePhone(String raw) {
  final digits = LatinDigits.only(raw);
  var local = digits;
  if (local.startsWith('971') && local.length > 3) {
    local = local.substring(3);
  }
  if (local.startsWith('0') && local.length > 1) {
    local = local.substring(1);
  }
  if (local.isEmpty) return raw.trim().isEmpty ? raw : raw.trim();
  final parts = <String>[
    if (local.isNotEmpty) local.substring(0, local.length.clamp(0, 2)),
    if (local.length > 2) local.substring(2, local.length.clamp(2, 5)),
    if (local.length > 5) local.substring(5, local.length.clamp(5, 9)),
  ];
  return '+971 ${parts.join(' ')}';
}
