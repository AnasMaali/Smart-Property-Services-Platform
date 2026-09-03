import 'package:flutter/material.dart';

class UaeFlag extends StatelessWidget {
  const UaeFlag({super.key});

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
        painter: _UaeFlagPainter(),
        child: SizedBox.expand(),
      ),
    );
  }
}

class _UaeFlagPainter extends CustomPainter {
  const _UaeFlagPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final red = Paint()..color = const Color(0xFFCE1126);
    final green = Paint()..color = const Color(0xFF00732F);
    final white = Paint()..color = const Color(0xFFFFFFFF);
    final black = Paint()..color = const Color(0xFF000000);

    final hoist = size.width * 0.28;
    final band = size.height / 3;

    canvas.drawRect(Rect.fromLTWH(hoist, 0, size.width - hoist, band), green);
    canvas.drawRect(
      Rect.fromLTWH(hoist, band, size.width - hoist, band),
      white,
    );
    canvas.drawRect(
      Rect.fromLTWH(
        hoist,
        band * 2,
        size.width - hoist,
        size.height - band * 2,
      ),
      black,
    );
    canvas.drawRect(Rect.fromLTWH(0, 0, hoist, size.height), red);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
