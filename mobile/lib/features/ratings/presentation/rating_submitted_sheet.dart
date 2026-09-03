import 'package:flutter/material.dart';

import '../../../app/theme/blue_theme.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import 'rate_service_sheet.dart';
import 'widgets/rating_widgets.dart';

Future<void> showRatingSubmittedSheet({required BuildContext context}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    barrierColor: rateSheetScrim,
    elevation: 0,
    useSafeArea: false,
    isDismissible: true,
    enableDrag: true,
    sheetAnimationStyle: AnimationStyle(
      duration: BlueMotion.sheet,
      curve: BlueMotion.curve,
      reverseDuration: BlueMotion.sheetOut,
      reverseCurve: BlueMotion.exitCurve,
    ),
    builder: (context) => const RatingSubmittedSheet(),
  );
}

class RatingSubmittedSheet extends StatelessWidget {
  const RatingSubmittedSheet({super.key});

  @override
  Widget build(BuildContext context) {
    final gutter = ratingGutter(context);
    final safe = MediaQuery.paddingOf(context).bottom;
    return Align(
      alignment: Alignment.bottomCenter,
      child: Material(
        color: Colors.transparent,
        child: Semantics(
          namesRoute: true,
          label: 'Thank you for your feedback.',
          child: DecoratedBox(
            decoration: const BoxDecoration(
              color: BlueColors.white,
              borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
              boxShadow: [
                BoxShadow(
                  color: Color(0x59140050),
                  offset: Offset(0, -18),
                  blurRadius: 44,
                  spreadRadius: -30,
                ),
              ],
            ),
            child: Padding(
              padding: EdgeInsets.fromLTRB(gutter, 14, gutter, 30 + safe),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Center(
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        color: BlueColors.border,
                        borderRadius: BorderRadius.all(Radius.circular(2)),
                      ),
                      child: SizedBox(width: 38, height: 4),
                    ),
                  ),
                  const SizedBox(height: 16),
                  const BlueEnter(
                    duration: Duration(milliseconds: 220),
                    offset: Offset.zero,
                    child: _SuccessBlock(),
                  ),
                  const SizedBox(height: 22),
                  const _BackButton(),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _SuccessBlock extends StatelessWidget {
  const _SuccessBlock();

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.fromLTRB(0, 8, 0, 2),
      child: Column(
        children: [
          DecoratedBox(
            decoration: BoxDecoration(
              color: BlueColors.chipSurface,
              shape: BoxShape.circle,
            ),
            child: SizedBox(
              width: 44,
              height: 44,
              child: Center(
                child: CustomPaint(
                  size: Size(20, 20),
                  painter: _CheckPainter(),
                ),
              ),
            ),
          ),
          SizedBox(height: 14),
          Text(
            'Thank you for your feedback.',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 17,
              height: 1.3,
              fontWeight: FontWeight.w700,
              letterSpacing: 17 * -0.014,
              color: BlueColors.ink,
            ),
          ),
          SizedBox(height: 6),
          Text(
            'Your rating is saved with this service.',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13.5,
              height: 1.5,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
        ],
      ),
    );
  }
}

class _BackButton extends StatefulWidget {
  const _BackButton();

  @override
  State<_BackButton> createState() => _BackButtonState();
}

class _BackButtonState extends State<_BackButton> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: 'Back to booking',
      excludeSemantics: true,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: (_) => setState(() => _down = true),
        onTapUp: (_) => setState(() => _down = false),
        onTapCancel: () => setState(() => _down = false),
        onTap: () {
          BlueMotion.tap();
          Navigator.of(context).maybePop();
        },
        child: AnimatedScale(
          scale: _down ? 0.985 : 1,
          duration: BlueMotion.of(context, ratingFast),
          curve: ratingEase,
          child: AnimatedContainer(
            duration: BlueMotion.of(context, ratingFast),
            curve: ratingEase,
            constraints: const BoxConstraints(
              minHeight: BlueDimens.fieldHeight,
            ),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: _down ? BlueColors.canvas : BlueColors.white,
              borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
              border: Border.all(color: BlueColors.border),
            ),
            child: const Text(
              'Back to booking',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 16.5,
                fontWeight: FontWeight.w700,
                letterSpacing: 16.5 * 0.005,
                color: BlueColors.ink,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _CheckPainter extends CustomPainter {
  const _CheckPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final sx = size.width / 24;
    final sy = size.height / 24;
    final paint = Paint()
      ..color = BlueColors.ink
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.4 * sx
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final path = Path()
      ..moveTo(20 * sx, 6.5 * sy)
      ..lineTo(9.6 * sx, 17 * sy)
      ..lineTo(4.5 * sx, 12 * sy);
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
