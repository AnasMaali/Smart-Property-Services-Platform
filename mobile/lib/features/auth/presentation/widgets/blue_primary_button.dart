import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import 'blue_motion.dart';

class BluePrimaryButton extends StatelessWidget {
  const BluePrimaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.busy = false,
    this.enabled = true,
    this.verified = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final bool busy;
  final bool enabled;
  final bool verified;

  @override
  Widget build(BuildContext context) {
    final background = verified ? BlueColors.verified : BlueColors.ink;
    final opacity = (!enabled && !busy && !verified)
        ? 0.42
        : (busy ? 0.9 : 1.0);
    final canTap = enabled && !busy && !verified;

    return Opacity(
      opacity: opacity,
      child: BluePressable(
        enabled: canTap,
        onPressed: canTap ? onPressed : null,
        scale: 0.975,
        child: AnimatedContainer(
          duration: BlueMotion.snap,
          curve: BlueMotion.curve,
          height: BlueDimens.fieldHeight,
          decoration: BoxDecoration(
            color: background,
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
                        child: _SpinningRing(),
                      )
                    : verified
                    ? const Padding(
                        key: ValueKey('ok'),
                        padding: EdgeInsets.only(right: 11),
                        child: CustomPaint(
                          size: Size(19, 19),
                          painter: _CheckPainter(),
                        ),
                      )
                    : const SizedBox.shrink(key: ValueKey('idle')),
              ),
              AnimatedSwitcher(
                duration: BlueMotion.tick,
                child: Text(
                  label,
                  key: ValueKey(label),
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

class _SpinningRing extends StatefulWidget {
  const _SpinningRing();

  @override
  State<_SpinningRing> createState() => _SpinningRingState();
}

class _SpinningRingState extends State<_SpinningRing>
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
        width: 17,
        height: 17,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          border: Border.all(color: const Color(0x52FFFFFF), width: 2),
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

class _CheckPainter extends CustomPainter {
  const _CheckPainter();

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
