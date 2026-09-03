import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';

class LockNote extends StatelessWidget {
  const LockNote({
    super.key,
    required this.text,
    this.maxWidth,
    this.centered = false,
  });

  final String text;
  final double? maxWidth;
  final bool centered;

  @override
  Widget build(BuildContext context) {
    final row = Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: centered ? MainAxisSize.min : MainAxisSize.max,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 2),
          child: CustomPaint(size: const Size(14, 14), painter: _LockPainter()),
        ),
        const SizedBox(width: 8),
        Flexible(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: maxWidth ?? double.infinity),
            child: Text(
              text,
              textAlign: TextAlign.left,
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
      ],
    );

    if (!centered) return row;
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [Flexible(child: row)],
    );
  }
}

class _LockPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.chevron
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.9 * (size.width / 24)
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    final sx = size.width / 24;
    final sy = size.height / 24;
    final body = RRect.fromLTRBR(
      4 * sx,
      10.5 * sy,
      20 * sx,
      20.5 * sy,
      Radius.circular(3 * sx),
    );
    canvas.drawRRect(body, paint);

    final shackle = Path()
      ..moveTo(8 * sx, 10.5 * sy)
      ..lineTo(8 * sx, 7.5 * sy)
      ..cubicTo(8 * sx, 4.2 * sy, 10.7 * sx, 3.5 * sy, 12 * sx, 3.5 * sy)
      ..cubicTo(13.3 * sx, 3.5 * sy, 16 * sx, 4.2 * sy, 16 * sx, 7.5 * sy)
      ..lineTo(16 * sx, 10.5 * sy);
    canvas.drawPath(shackle, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
