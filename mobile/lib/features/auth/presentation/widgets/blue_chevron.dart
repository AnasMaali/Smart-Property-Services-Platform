import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import 'blue_motion.dart';

class BlueChevron extends StatelessWidget {
  const BlueChevron({
    super.key,
    this.size = 11,
    this.strokeWidth = 2.6,
    this.expanded = false,
  });

  final double size;
  final double strokeWidth;
  final bool expanded;

  @override
  Widget build(BuildContext context) {
    return AnimatedRotation(
      turns: expanded ? 0.5 : 0,
      duration: BlueMotion.snap,
      curve: BlueMotion.curve,
      child: CustomPaint(
        size: Size(size, size),
        painter: _ChevronPainter(strokeWidth: strokeWidth),
      ),
    );
  }
}

class _ChevronPainter extends CustomPainter {
  const _ChevronPainter({required this.strokeWidth});

  final double strokeWidth;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.chevron
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth * (size.width / 24)
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    final path = Path()
      ..moveTo(6 * sx, 9 * sy)
      ..lineTo(12 * sx, 15 * sy)
      ..lineTo(18 * sx, 9 * sy);
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
