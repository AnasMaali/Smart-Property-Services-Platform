import 'package:flutter/material.dart';

class PalestineFlag extends StatelessWidget {
  const PalestineFlag({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 22,
      height: 15,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(3),
        border: Border.all(color: const Color(0x33140050), width: 0.6),
      ),
      clipBehavior: Clip.antiAlias,
      child: const CustomPaint(
        painter: _PalestineFlagPainter(),
        child: SizedBox.expand(),
      ),
    );
  }
}

class _PalestineFlagPainter extends CustomPainter {
  const _PalestineFlagPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final black = Paint()..color = const Color(0xFF000000);
    final white = Paint()..color = const Color(0xFFFFFFFF);
    final green = Paint()..color = const Color(0xFF007A3D);
    final red = Paint()..color = const Color(0xFFCE1126);

    final band = size.height / 3;
    canvas.drawRect(Rect.fromLTWH(0, 0, size.width, band), black);
    canvas.drawRect(Rect.fromLTWH(0, band, size.width, band), white);
    canvas.drawRect(
      Rect.fromLTWH(0, band * 2, size.width, size.height - band * 2),
      green,
    );

    final triangle = Path()
      ..moveTo(0, 0)
      ..lineTo(size.width * 0.38, size.height / 2)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(triangle, red);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
