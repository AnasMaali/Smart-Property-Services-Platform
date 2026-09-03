import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import 'blue_motion.dart';

Future<T?> showBlueSheet<T>({
  required BuildContext context,
  required WidgetBuilder builder,
}) {
  BlueMotion.tap();
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    barrierColor: BlueColors.sheetScrim,
    elevation: 0,
    useSafeArea: true,
    sheetAnimationStyle: AnimationStyle(
      duration: BlueMotion.sheet,
      curve: BlueMotion.curve,
      reverseDuration: BlueMotion.sheetOut,
      reverseCurve: BlueMotion.exitCurve,
    ),
    builder: builder,
  );
}

class BlueSheetPanel extends StatelessWidget {
  const BlueSheetPanel({
    super.key,
    required this.title,
    required this.onClose,
    required this.child,
    this.header,
    this.footer,
    this.maxHeight = 588,
  });

  final String title;
  final VoidCallback onClose;
  final Widget child;
  final Widget? header;
  final Widget? footer;
  final double maxHeight;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.bottomCenter,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: maxHeight),
        child: Material(
          color: Colors.transparent,
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
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const SizedBox(height: 10),
                Container(
                  width: 38,
                  height: 4,
                  decoration: BoxDecoration(
                    color: BlueColors.border,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 12, 10, 0),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          title,
                          style: const TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 17,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 17 * -0.01,
                            color: BlueColors.ink,
                          ),
                        ),
                      ),
                      BluePressable(
                        onPressed: onClose,
                        scale: 0.9,
                        child: const SizedBox(
                          width: 40,
                          height: 40,
                          child: Center(
                            child: CustomPaint(
                              size: Size(17, 17),
                              painter: BlueSheetClosePainter(),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                ?header,
                Flexible(child: child),
                ?footer,
                const SizedBox(height: 26),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Overlay sheet that can stay mounted while it slides out.
class BlueHostedSheet extends StatelessWidget {
  const BlueHostedSheet({
    super.key,
    required this.open,
    required this.onDismiss,
    required this.child,
    this.scrimColor = BlueColors.sheetScrim,
  });

  final bool open;
  final VoidCallback onDismiss;
  final Widget child;
  final Color scrimColor;

  @override
  Widget build(BuildContext context) {
    return Positioned.fill(
      child: IgnorePointer(
        ignoring: !open,
        child: Stack(
          children: [
            GestureDetector(
              onTap: onDismiss,
              child: AnimatedOpacity(
                duration: open ? BlueMotion.sheet : BlueMotion.sheetOut,
                curve: Curves.easeOut,
                opacity: open ? 1 : 0,
                child: ColoredBox(
                  color: scrimColor,
                  child: const SizedBox.expand(),
                ),
              ),
            ),
            AnimatedSlide(
              duration: open ? BlueMotion.sheet : BlueMotion.sheetOut,
              curve: open ? BlueMotion.curve : BlueMotion.exitCurve,
              offset: open ? Offset.zero : const Offset(0, 1),
              child: child,
            ),
          ],
        ),
      ),
    );
  }
}

class BlueSheetRow extends StatefulWidget {
  const BlueSheetRow({
    super.key,
    required this.label,
    required this.selected,
    required this.onPressed,
    this.index = 0,
    this.leading,
  });

  final String label;
  final bool selected;
  final VoidCallback onPressed;
  final int index;
  final Widget? leading;

  @override
  State<BlueSheetRow> createState() => _BlueSheetRowState();
}

class _BlueSheetRowState extends State<BlueSheetRow> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return BlueListReveal(
      index: widget.index,
      child: GestureDetector(
        onTapDown: (_) => setState(() => _down = true),
        onTapUp: (_) => setState(() => _down = false),
        onTapCancel: () => setState(() => _down = false),
        onTap: () {
          BlueMotion.tap();
          widget.onPressed();
        },
        child: AnimatedScale(
          scale: _down ? 0.985 : 1,
          duration: BlueMotion.press,
          curve: Curves.easeOut,
          child: AnimatedContainer(
            duration: BlueMotion.snap,
            curve: BlueMotion.curve,
            constraints: const BoxConstraints(minHeight: 56),
            padding: const EdgeInsets.symmetric(horizontal: 12),
            decoration: BoxDecoration(
              color: _down
                  ? BlueColors.rowPress
                  : (widget.selected
                        ? const Color(0xFFF4F3F8)
                        : Colors.transparent),
              borderRadius: BorderRadius.circular(16),
            ),
            child: Row(
              children: [
                if (widget.leading != null) ...[
                  widget.leading!,
                  const SizedBox(width: 12),
                ],
                Expanded(
                  child: Text(
                    widget.label,
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 16,
                      fontWeight: widget.selected
                          ? FontWeight.w700
                          : FontWeight.w500,
                      color: BlueColors.ink,
                    ),
                  ),
                ),
                AnimatedSwitcher(
                  duration: BlueMotion.tick,
                  switchInCurve: BlueMotion.curve,
                  switchOutCurve: Curves.easeIn,
                  transitionBuilder: (child, animation) {
                    return ScaleTransition(
                      scale: animation,
                      child: FadeTransition(opacity: animation, child: child),
                    );
                  },
                  child: widget.selected
                      ? const CustomPaint(
                          key: ValueKey('on'),
                          size: Size(19, 19),
                          painter: BlueSheetCheckPainter(),
                        )
                      : const SizedBox(
                          key: ValueKey('off'),
                          width: 19,
                          height: 19,
                        ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class BlueSheetClosePainter extends CustomPainter {
  const BlueSheetClosePainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.muted
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.3
      ..strokeCap = StrokeCap.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawLine(Offset(6 * sx, 6 * sy), Offset(18 * sx, 18 * sy), paint);
    canvas.drawLine(Offset(18 * sx, 6 * sy), Offset(6 * sx, 18 * sy), paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class BlueSheetCheckPainter extends CustomPainter {
  const BlueSheetCheckPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.ink
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
