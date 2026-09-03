import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';

enum BlueGlyph {
  home,
  cart,
  bookings,
  contracts,
  account,
  bell,
  pin,
  calendar,
  search,
  photo,
  warning,
  close,
  chevronRight,
  chevronDown,
  check,
  info,
  plus,
  minus,
  building,
  office,
  sliders,
  ac,
  plug,
  faucet,
  roller,
  sparkle,
  wrench,
  clock,
}

class BlueGlyphIcon extends StatelessWidget {
  const BlueGlyphIcon(
    this.glyph, {
    super.key,
    this.size = 22,
    this.color = BlueColors.ink,
    this.strokeWidth = 1.8,
  });

  final BlueGlyph glyph;
  final double size;
  final Color color;
  final double strokeWidth;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      size: Size.square(size),
      painter: _GlyphPainter(
        glyph: glyph,
        color: color,
        strokeWidth: strokeWidth,
      ),
    );
  }

  static BlueGlyph forCategory(String code) {
    return switch (code.toUpperCase()) {
      'AC' || 'AC_CLEANING' || 'AC_SERVICES' => BlueGlyph.ac,
      'ELECTRICAL' => BlueGlyph.plug,
      'PLUMBING' => BlueGlyph.faucet,
      'PAINTING' || 'RENOVATION' => BlueGlyph.roller,
      'CLEANING' => BlueGlyph.sparkle,
      'HANDYMAN' || 'MAINTENANCE' || 'MASONRY' => BlueGlyph.wrench,
      _ => BlueGlyph.photo,
    };
  }

  static String shortCategoryName(String code, String name) {
    return switch (code.toUpperCase()) {
      'AC' || 'AC_CLEANING' || 'AC_SERVICES' => 'AC',
      'CLEANING' => 'Cleaning',
      'PLUMBING' => 'Plumbing',
      'ELECTRICAL' => 'Electrical',
      'PAINTING' || 'RENOVATION' => 'Painting',
      'HANDYMAN' || 'MAINTENANCE' => 'Maintenance',
      'PEST_CONTROL' => 'Pest',
      'MASONRY' => 'Masonry',
      _ => name,
    };
  }
}

class _GlyphPainter extends CustomPainter {
  const _GlyphPainter({
    required this.glyph,
    required this.color,
    required this.strokeWidth,
  });

  final BlueGlyph glyph;
  final Color color;
  final double strokeWidth;

  @override
  void paint(Canvas canvas, Size size) {
    final sx = size.width / 24;
    final sy = size.height / 24;
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth * (size.width / 22)
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;

    switch (glyph) {
      case BlueGlyph.home:
        _home(canvas, paint, sx, sy);
      case BlueGlyph.cart:
        _cart(canvas, paint, sx, sy);
      case BlueGlyph.bookings:
        _bookings(canvas, paint, sx, sy);
      case BlueGlyph.contracts:
        _contracts(canvas, paint, sx, sy);
      case BlueGlyph.account:
        _account(canvas, paint, sx, sy);
      case BlueGlyph.bell:
        _bell(canvas, paint, sx, sy);
      case BlueGlyph.pin:
        _pin(canvas, paint, sx, sy);
      case BlueGlyph.calendar:
        _calendar(canvas, paint, sx, sy);
      case BlueGlyph.search:
        _search(canvas, paint, sx, sy);
      case BlueGlyph.photo:
        _photo(canvas, paint, sx, sy);
      case BlueGlyph.warning:
        _warning(canvas, paint, sx, sy);
      case BlueGlyph.close:
        _close(canvas, paint, sx, sy);
      case BlueGlyph.chevronRight:
        _chevronRight(canvas, paint, sx, sy);
      case BlueGlyph.chevronDown:
        _chevronDown(canvas, paint, sx, sy);
      case BlueGlyph.check:
        _check(canvas, paint, sx, sy);
      case BlueGlyph.info:
        _info(canvas, paint, sx, sy);
      case BlueGlyph.plus:
        _plus(canvas, paint, sx, sy);
      case BlueGlyph.minus:
        _minus(canvas, paint, sx, sy);
      case BlueGlyph.building:
        _building(canvas, paint, sx, sy);
      case BlueGlyph.office:
        _office(canvas, paint, sx, sy);
      case BlueGlyph.sliders:
        _sliders(canvas, paint, sx, sy);
      case BlueGlyph.ac:
        _ac(canvas, paint, sx, sy);
      case BlueGlyph.plug:
        _plug(canvas, paint, sx, sy);
      case BlueGlyph.faucet:
        _faucet(canvas, paint, sx, sy);
      case BlueGlyph.roller:
        _roller(canvas, paint, sx, sy);
      case BlueGlyph.sparkle:
        _sparkle(canvas, paint, sx, sy);
      case BlueGlyph.wrench:
        _wrench(canvas, paint, sx, sy);
      case BlueGlyph.clock:
        _clock(canvas, paint, sx, sy);
    }
  }

  void _home(Canvas canvas, Paint paint, double sx, double sy) {
    final path = Path()
      ..moveTo(4 * sx, 11.2 * sy)
      ..lineTo(12 * sx, 4.5 * sy)
      ..lineTo(20 * sx, 11.2 * sy)
      ..lineTo(20 * sx, 20 * sy)
      ..arcToPoint(Offset(19 * sx, 21 * sy), radius: Radius.circular(sx))
      ..lineTo(14.5 * sx, 21 * sy)
      ..lineTo(14.5 * sx, 15 * sy)
      ..lineTo(9.5 * sx, 15 * sy)
      ..lineTo(9.5 * sx, 21 * sy)
      ..lineTo(5 * sx, 21 * sy)
      ..arcToPoint(Offset(4 * sx, 20 * sy), radius: Radius.circular(sx))
      ..close();
    canvas.drawPath(path, paint);
  }

  void _cart(Canvas canvas, Paint paint, double sx, double sy) {
    final bag = Path()
      ..moveTo(4 * sx, 5 * sy)
      ..lineTo(6.2 * sx, 5 * sy)
      ..lineTo(8.5 * sx, 15.6 * sy)
      ..arcToPoint(Offset(9.5 * sx, 16.4 * sy), radius: Radius.circular(sx))
      ..lineTo(17.7 * sx, 16.4 * sy)
      ..arcToPoint(Offset(18.7 * sx, 15.62 * sy), radius: Radius.circular(sx))
      ..lineTo(20.5 * sx, 9 * sy)
      ..lineTo(7 * sx, 9 * sy);
    canvas.drawPath(bag, paint);
    canvas.drawCircle(Offset(10 * sx, 20.2 * sy), 1.05 * sx, paint);
    canvas.drawCircle(Offset(17.3 * sx, 20.2 * sy), 1.05 * sx, paint);
  }

  void _bookings(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawRRect(
      RRect.fromLTRBR(4 * sx, 6.5 * sy, 20 * sx, 21 * sy, Radius.circular(sx)),
      paint,
    );
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

  void _contracts(Canvas canvas, Paint paint, double sx, double sy) {
    final page = Path()
      ..moveTo(7 * sx, 3.5 * sy)
      ..lineTo(14.2 * sx, 3.5 * sy)
      ..lineTo(19 * sx, 8.4 * sy)
      ..lineTo(19 * sx, 20 * sy)
      ..arcToPoint(Offset(18 * sx, 21 * sy), radius: Radius.circular(sx))
      ..lineTo(7 * sx, 21 * sy)
      ..arcToPoint(Offset(6 * sx, 20 * sy), radius: Radius.circular(sx))
      ..lineTo(6 * sx, 4.5 * sy)
      ..arcToPoint(Offset(7 * sx, 3.5 * sy), radius: Radius.circular(sx))
      ..close();
    canvas.drawPath(page, paint);
    canvas.drawLine(
      Offset(13.8 * sx, 3.8 * sy),
      Offset(13.8 * sx, 8.8 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(13.8 * sx, 8.8 * sy),
      Offset(18.8 * sx, 8.8 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(9.2 * sx, 13.5 * sy),
      Offset(14.8 * sx, 13.5 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(9.2 * sx, 17 * sy),
      Offset(13.2 * sx, 17 * sy),
      paint,
    );
  }

  void _account(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawCircle(Offset(12 * sx, 7.7 * sy), 3.9 * sx, paint);
    final body = Path()
      ..moveTo(4.6 * sx, 20.4 * sy)
      ..cubicTo(5.3 * sx, 16.8 * sy, 8.4 * sx, 14.7 * sy, 12 * sx, 14.7 * sy)
      ..cubicTo(
        15.6 * sx,
        14.7 * sy,
        18.7 * sx,
        16.8 * sy,
        19.4 * sx,
        20.4 * sy,
      );
    canvas.drawPath(body, paint);
  }

  void _bell(Canvas canvas, Paint paint, double sx, double sy) {
    final cup = Path()
      ..moveTo(18 * sx, 15.5 * sy)
      ..lineTo(18 * sx, 10 * sy)
      ..cubicTo(18 * sx, 6.7 * sy, 15.3 * sx, 4 * sy, 12 * sx, 4 * sy)
      ..cubicTo(8.7 * sx, 4 * sy, 6 * sx, 6.7 * sy, 6 * sx, 10 * sy)
      ..lineTo(6 * sx, 15.5 * sy)
      ..lineTo(4.5 * sx, 18 * sy)
      ..lineTo(19.5 * sx, 18 * sy)
      ..close();
    canvas.drawPath(cup, paint);
    final clapper = Path()
      ..moveTo(9.7 * sx, 21 * sy)
      ..cubicTo(10.3 * sx, 22.4 * sy, 11.1 * sx, 23.2 * sy, 12 * sx, 23.2 * sy)
      ..cubicTo(12.9 * sx, 23.2 * sy, 13.7 * sx, 22.4 * sy, 14.3 * sx, 21 * sy);
    canvas.drawPath(clapper, paint);
  }

  void _calendar(Canvas canvas, Paint paint, double sx, double sy) {
    final body = Path()
      ..moveTo(5 * sx, 6.5 * sy)
      ..lineTo(19 * sx, 6.5 * sy)
      ..arcToPoint(Offset(20 * sx, 7.5 * sy), radius: Radius.circular(sx))
      ..lineTo(20 * sx, 20 * sy)
      ..arcToPoint(Offset(19 * sx, 21 * sy), radius: Radius.circular(sx))
      ..lineTo(5 * sx, 21 * sy)
      ..arcToPoint(Offset(4 * sx, 20 * sy), radius: Radius.circular(sx))
      ..lineTo(4 * sx, 7.5 * sy)
      ..arcToPoint(Offset(5 * sx, 6.5 * sy), radius: Radius.circular(sx))
      ..close();
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

  void _pin(Canvas canvas, Paint paint, double sx, double sy) {
    final pin = Path()
      ..moveTo(12 * sx, 21 * sy)
      ..cubicTo(12 * sx, 21 * sy, 19 * sx, 15.3 * sy, 19 * sx, 10 * sy)
      ..cubicTo(19 * sx, 6.1 * sy, 15.9 * sx, 3 * sy, 12 * sx, 3 * sy)
      ..cubicTo(8.1 * sx, 3 * sy, 5 * sx, 6.1 * sy, 5 * sx, 10 * sy)
      ..cubicTo(5 * sx, 15.3 * sy, 12 * sx, 21 * sy, 12 * sx, 21 * sy)
      ..close();
    canvas.drawPath(pin, paint);
    canvas.drawCircle(Offset(12 * sx, 10 * sy), 2.4 * sx, paint);
  }

  void _search(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawCircle(Offset(11 * sx, 11 * sy), 6.5 * sx, paint);
    canvas.drawLine(
      Offset(16 * sx, 16 * sy),
      Offset(20.5 * sx, 20.5 * sy),
      paint,
    );
  }

  void _photo(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawRRect(
      RRect.fromLTRBR(
        3 * sx,
        3 * sy,
        21 * sx,
        21 * sy,
        Radius.circular(2 * sx),
      ),
      paint,
    );
    canvas.drawCircle(Offset(8.5 * sx, 8.5 * sy), 1.5 * sx, paint);
    final mountain = Path()
      ..moveTo(21 * sx, 15 * sy)
      ..lineTo(16 * sx, 10 * sy)
      ..lineTo(5 * sx, 21 * sy);
    canvas.drawPath(mountain, paint);
  }

  void _warning(Canvas canvas, Paint paint, double sx, double sy) {
    final triangle = Path()
      ..moveTo(12 * sx, 3.5 * sy)
      ..lineTo(21 * sx, 19 * sy)
      ..lineTo(3 * sx, 19 * sy)
      ..close();
    canvas.drawPath(triangle, paint);
    canvas.drawLine(
      Offset(12 * sx, 9.5 * sy),
      Offset(12 * sx, 13.5 * sy),
      paint,
    );
    canvas.drawCircle(Offset(12 * sx, 16.4 * sy), 0.7 * sx, paint);
  }

  void _close(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawLine(
      Offset(6.5 * sx, 6.5 * sy),
      Offset(17.5 * sx, 17.5 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(17.5 * sx, 6.5 * sy),
      Offset(6.5 * sx, 17.5 * sy),
      paint,
    );
  }

  void _chevronRight(Canvas canvas, Paint paint, double sx, double sy) {
    final path = Path()
      ..moveTo(9 * sx, 5 * sy)
      ..lineTo(16 * sx, 12 * sy)
      ..lineTo(9 * sx, 19 * sy);
    canvas.drawPath(path, paint);
  }

  void _chevronDown(Canvas canvas, Paint paint, double sx, double sy) {
    final path = Path()
      ..moveTo(5 * sx, 9 * sy)
      ..lineTo(12 * sx, 16 * sy)
      ..lineTo(19 * sx, 9 * sy);
    canvas.drawPath(path, paint);
  }

  void _check(Canvas canvas, Paint paint, double sx, double sy) {
    final path = Path()
      ..moveTo(5 * sx, 12.6 * sy)
      ..lineTo(9.6 * sx, 17 * sy)
      ..lineTo(19 * sx, 6.6 * sy);
    canvas.drawPath(path, paint);
  }

  void _info(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawCircle(Offset(12 * sx, 12 * sy), 8.6 * sx, paint);
    canvas.drawLine(Offset(12 * sx, 8 * sy), Offset(12 * sx, 12.6 * sy), paint);
    canvas.drawCircle(Offset(12 * sx, 15.8 * sy), 0.7 * sx, paint);
  }

  void _plus(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawLine(
      Offset(12 * sx, 6.2 * sy),
      Offset(12 * sx, 17.8 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(6.2 * sx, 12 * sy),
      Offset(17.8 * sx, 12 * sy),
      paint,
    );
  }

  void _minus(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawLine(
      Offset(6.2 * sx, 12 * sy),
      Offset(17.8 * sx, 12 * sy),
      paint,
    );
  }

  void _building(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawRRect(
      RRect.fromLTRBR(
        5 * sx,
        4 * sy,
        19 * sx,
        21 * sy,
        Radius.circular(1.4 * sx),
      ),
      paint,
    );
    canvas.drawLine(Offset(5 * sx, 21 * sy), Offset(19 * sx, 21 * sy), paint);
    canvas.drawLine(
      Offset(8.2 * sx, 8 * sy),
      Offset(8.2 * sx, 10.2 * sy),
      paint,
    );
    canvas.drawLine(Offset(12 * sx, 8 * sy), Offset(12 * sx, 10.2 * sy), paint);
    canvas.drawLine(
      Offset(15.8 * sx, 8 * sy),
      Offset(15.8 * sx, 10.2 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(8.2 * sx, 13 * sy),
      Offset(8.2 * sx, 15.2 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(12 * sx, 13 * sy),
      Offset(12 * sx, 15.2 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(15.8 * sx, 13 * sy),
      Offset(15.8 * sx, 15.2 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(10.4 * sx, 17.4 * sy),
      Offset(13.6 * sx, 17.4 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(10.4 * sx, 17.4 * sy),
      Offset(10.4 * sx, 21 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(13.6 * sx, 17.4 * sy),
      Offset(13.6 * sx, 21 * sy),
      paint,
    );
  }

  void _office(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawRRect(
      RRect.fromLTRBR(
        3.5 * sx,
        6 * sy,
        14.2 * sx,
        21 * sy,
        Radius.circular(1.2 * sx),
      ),
      paint,
    );
    canvas.drawRRect(
      RRect.fromLTRBR(
        14.2 * sx,
        3.5 * sy,
        20.5 * sx,
        21 * sy,
        Radius.circular(1.2 * sx),
      ),
      paint,
    );
    canvas.drawLine(
      Offset(6.2 * sx, 9.4 * sy),
      Offset(6.2 * sx, 11.2 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(9.6 * sx, 9.4 * sy),
      Offset(9.6 * sx, 11.2 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(6.2 * sx, 13.6 * sy),
      Offset(6.2 * sx, 15.4 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(9.6 * sx, 13.6 * sy),
      Offset(9.6 * sx, 15.4 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(17.4 * sx, 7.2 * sy),
      Offset(17.4 * sx, 9 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(17.4 * sx, 11.4 * sy),
      Offset(17.4 * sx, 13.2 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(17.4 * sx, 15.6 * sy),
      Offset(17.4 * sx, 17.4 * sy),
      paint,
    );
  }

  void _sliders(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawLine(Offset(4 * sx, 7 * sy), Offset(20 * sx, 7 * sy), paint);
    canvas.drawLine(Offset(4 * sx, 12 * sy), Offset(20 * sx, 12 * sy), paint);
    canvas.drawLine(Offset(4 * sx, 17 * sy), Offset(20 * sx, 17 * sy), paint);
    canvas.drawCircle(Offset(9 * sx, 7 * sy), 2.1 * sx, paint);
    canvas.drawCircle(Offset(16 * sx, 12 * sy), 2.1 * sx, paint);
    canvas.drawCircle(Offset(11 * sx, 17 * sy), 2.1 * sx, paint);
  }

  void _ac(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawRRect(
      RRect.fromLTRBR(
        3.5 * sx,
        6 * sy,
        20.5 * sx,
        16.5 * sy,
        Radius.circular(2 * sx),
      ),
      paint,
    );
    canvas.drawLine(
      Offset(6.5 * sx, 10 * sy),
      Offset(17.5 * sx, 10 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(6.5 * sx, 12.6 * sy),
      Offset(17.5 * sx, 12.6 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(8 * sx, 16.5 * sy),
      Offset(7 * sx, 19.5 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(16 * sx, 16.5 * sy),
      Offset(17 * sx, 19.5 * sy),
      paint,
    );
  }

  void _plug(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawLine(Offset(9 * sx, 3.5 * sy), Offset(9 * sx, 8 * sy), paint);
    canvas.drawLine(Offset(15 * sx, 3.5 * sy), Offset(15 * sx, 8 * sy), paint);
    canvas.drawRRect(
      RRect.fromLTRBR(
        6.5 * sx,
        8 * sy,
        17.5 * sx,
        16.5 * sy,
        Radius.circular(2.2 * sx),
      ),
      paint,
    );
    canvas.drawLine(
      Offset(12 * sx, 16.5 * sy),
      Offset(12 * sx, 21 * sy),
      paint,
    );
  }

  void _faucet(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawRRect(
      RRect.fromLTRBR(4.5 * sx, 5 * sy, 11 * sx, 8.5 * sy, Radius.circular(sx)),
      paint,
    );
    final neck = Path()
      ..moveTo(7.6 * sx, 8.5 * sy)
      ..lineTo(7.6 * sx, 13 * sy)
      ..cubicTo(7.6 * sx, 17.4 * sy, 11.4 * sx, 19.6 * sy, 15.4 * sx, 19.6 * sy)
      ..lineTo(18.4 * sx, 19.6 * sy);
    canvas.drawPath(neck, paint);
    canvas.drawLine(
      Offset(18.4 * sx, 19.6 * sy),
      Offset(18.4 * sx, 21.4 * sy),
      paint,
    );
  }

  void _roller(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawRRect(
      RRect.fromLTRBR(
        4 * sx,
        4 * sy,
        16.5 * sx,
        9.4 * sy,
        Radius.circular(1.6 * sx),
      ),
      paint,
    );
    canvas.drawLine(
      Offset(16.5 * sx, 6.7 * sy),
      Offset(19.4 * sx, 6.7 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(19.4 * sx, 6.7 * sy),
      Offset(19.4 * sx, 13.4 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(19.4 * sx, 13.4 * sy),
      Offset(12.4 * sx, 17.2 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(12.4 * sx, 17.2 * sy),
      Offset(12.4 * sx, 21 * sy),
      paint,
    );
  }

  void _sparkle(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawLine(
      Offset(12 * sx, 3.6 * sy),
      Offset(12 * sx, 20.4 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(4.2 * sx, 12 * sy),
      Offset(19.8 * sx, 12 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(6.6 * sx, 6.6 * sy),
      Offset(17.4 * sx, 17.4 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(17.4 * sx, 6.6 * sy),
      Offset(6.6 * sx, 17.4 * sy),
      paint,
    );
  }

  void _wrench(Canvas canvas, Paint paint, double sx, double sy) {
    final path = Path()
      ..moveTo(7.2 * sx, 4.4 * sy)
      ..lineTo(10.4 * sx, 7.6 * sy)
      ..lineTo(16.8 * sx, 14 * sy)
      ..lineTo(19.4 * sx, 16.6 * sy)
      ..lineTo(16.6 * sx, 19.4 * sy)
      ..lineTo(14 * sx, 16.8 * sy)
      ..lineTo(7.6 * sx, 10.4 * sy)
      ..lineTo(4.4 * sx, 7.2 * sy)
      ..cubicTo(3.2 * sx, 6 * sy, 3.4 * sx, 4.2 * sy, 5 * sx, 4.2 * sy)
      ..cubicTo(6.2 * sx, 4.2 * sy, 7.2 * sx, 4.4 * sy, 7.2 * sx, 4.4 * sy);
    canvas.drawPath(path, paint);
    canvas.drawCircle(Offset(6.2 * sx, 6.2 * sy), 1.3 * sx, paint);
  }

  void _clock(Canvas canvas, Paint paint, double sx, double sy) {
    canvas.drawCircle(Offset(12 * sx, 12.2 * sy), 8.2 * sx, paint);
    canvas.drawLine(
      Offset(12 * sx, 12.2 * sy),
      Offset(12 * sx, 8.2 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(12 * sx, 12.2 * sy),
      Offset(16 * sx, 13.6 * sy),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant _GlyphPainter oldDelegate) {
    return oldDelegate.glyph != glyph ||
        oldDelegate.color != color ||
        oldDelegate.strokeWidth != strokeWidth;
  }
}
