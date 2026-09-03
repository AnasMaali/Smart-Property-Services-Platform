import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import 'blue_motion.dart';

class BlueBackButton extends StatefulWidget {
  const BlueBackButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  State<BlueBackButton> createState() => _BlueBackButtonState();
}

class _BlueBackButtonState extends State<BlueBackButton> {
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
        scale: _down ? 0.92 : 1,
        duration: BlueMotion.press,
        curve: Curves.easeOut,
        child: AnimatedContainer(
          duration: BlueMotion.press,
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: _down ? BlueColors.press : BlueColors.white,
            shape: BoxShape.circle,
            border: Border.all(color: BlueColors.border),
          ),
          alignment: Alignment.center,
          child: const CustomPaint(
            size: Size(19, 19),
            painter: _BackArrowPainter(),
          ),
        ),
      ),
    );
  }
}

class _BackArrowPainter extends CustomPainter {
  const _BackArrowPainter();
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.ink
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.1
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawLine(Offset(19 * sx, 12 * sy), Offset(5 * sx, 12 * sy), paint);
    final path = Path()
      ..moveTo(11 * sx, 18 * sy)
      ..lineTo(5 * sx, 12 * sy)
      ..lineTo(11 * sx, 6 * sy);
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
