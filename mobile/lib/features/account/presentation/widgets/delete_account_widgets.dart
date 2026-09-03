import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../auth/presentation/widgets/error_hint.dart';

const deleteSheetScrim = Color(0x610C042C);
const deleteEase = Cubic(0.2, 0, 0.2, 1);
const deleteFast = Duration(milliseconds: 140);
const deleteBase = Duration(milliseconds: 180);
const deleteSlow = Duration(milliseconds: 220);

class DeleteAccountBackButton extends StatefulWidget {
  const DeleteAccountBackButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  State<DeleteAccountBackButton> createState() =>
      _DeleteAccountBackButtonState();
}

class _DeleteAccountBackButtonState extends State<DeleteAccountBackButton> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(-11, 0),
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: (_) => setState(() => _down = true),
        onTapUp: (_) => setState(() => _down = false),
        onTapCancel: () => setState(() => _down = false),
        onTap: () {
          BlueMotion.tap();
          widget.onPressed();
        },
        child: AnimatedContainer(
          duration: deleteFast,
          curve: deleteEase,
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: _down ? BlueColors.press : Colors.transparent,
            borderRadius: BorderRadius.circular(22),
          ),
          alignment: Alignment.center,
          child: const CustomPaint(
            size: Size(19, 19),
            painter: _DeleteBackPainter(),
          ),
        ),
      ),
    );
  }
}

class DeleteAccountTitle extends StatelessWidget {
  const DeleteAccountTitle({super.key});

  @override
  Widget build(BuildContext context) {
    final titleSize = MediaQuery.sizeOf(context).width < 359 ? 24.0 : 26.0;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Delete account',
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: titleSize,
            height: 1.18,
            fontWeight: FontWeight.w700,
            letterSpacing: titleSize * -0.022,
            color: BlueColors.ink,
          ),
        ),
        const SizedBox(height: 7),
        ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 300),
          child: const Text(
            'This closes your BLUE account and signs you out on every device.',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13.5,
              height: 1.45,
              fontWeight: FontWeight.w500,
              color: BlueColors.muted,
            ),
          ),
        ),
      ],
    );
  }
}

class DeleteAccountFacts extends StatelessWidget {
  const DeleteAccountFacts({super.key});

  static const _body = TextStyle(
    fontFamily: BlueFonts.jakarta,
    fontSize: 13.5,
    height: 1.5,
    fontWeight: FontWeight.w400,
    color: BlueColors.muted,
  );

  static const _emphasis = TextStyle(
    fontFamily: BlueFonts.jakarta,
    fontSize: 13.5,
    height: 1.5,
    fontWeight: FontWeight.w700,
    color: BlueColors.ink,
  );

  @override
  Widget build(BuildContext context) {
    return const Column(
      children: [
        _Fact(
          painter: DeleteCalendarPainter(),
          children: [
            TextSpan(text: 'Your '),
            TextSpan(
              text: 'saved properties and service interests',
              style: _emphasis,
            ),
            TextSpan(text: ' are removed.'),
          ],
        ),
        SizedBox(height: 14),
        _Fact(
          painter: DeleteDocumentPainter(),
          children: [
            TextSpan(text: 'Records we must keep — '),
            TextSpan(text: 'invoices and signed contracts', style: _emphasis),
            TextSpan(text: ' — stay with BLUE as the law requires.'),
          ],
        ),
        SizedBox(height: 14),
        _Fact(
          painter: DeleteClockPainter(),
          children: [
            TextSpan(
              text: 'Scheduled visits and running contracts',
              style: _emphasis,
            ),
            TextSpan(text: ' need to be finished or cancelled with us first.'),
          ],
        ),
      ],
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact({required this.painter, required this.children});

  final CustomPainter painter;
  final List<InlineSpan> children;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 2),
          child: CustomPaint(size: const Size(17, 17), painter: painter),
        ),
        const SizedBox(width: 11),
        Expanded(
          child: Text.rich(
            TextSpan(style: DeleteAccountFacts._body, children: children),
          ),
        ),
      ],
    );
  }
}

class DeleteAccountNotice extends StatelessWidget {
  const DeleteAccountNotice({super.key});

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: BlueColors.unavailableSurface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: BlueColors.unavailableLine),
      ),
      child: const Padding(
        padding: EdgeInsets.fromLTRB(15, 13, 15, 13),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: EdgeInsets.only(top: 1),
              child: CustomPaint(
                size: Size(15, 15),
                painter: DeleteInfoPainter(),
              ),
            ),
            SizedBox(width: 10),
            Expanded(
              child: Text(
                "Once deletion is complete it can't be undone, and the same phone number will start a new account from scratch.",
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  height: 1.45,
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

class DeleteAccountHelper extends StatelessWidget {
  const DeleteAccountHelper({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.only(top: 8),
      child: Text(
        'You can come back to this any time from Account.',
        textAlign: TextAlign.center,
        style: TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: 12.5,
          height: 1.45,
          fontWeight: FontWeight.w400,
          color: BlueColors.placeholder,
        ),
      ),
    );
  }
}

class DeleteGhostButton extends StatefulWidget {
  const DeleteGhostButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.enabled = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final bool enabled;

  @override
  State<DeleteGhostButton> createState() => _DeleteGhostButtonState();
}

class _DeleteGhostButtonState extends State<DeleteGhostButton> {
  bool _down = false;

  bool get _canTap => widget.enabled && widget.onPressed != null;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: widget.enabled ? 1 : 0.4,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: _canTap ? (_) => setState(() => _down = true) : null,
        onTapUp: _canTap ? (_) => setState(() => _down = false) : null,
        onTapCancel: _canTap ? () => setState(() => _down = false) : null,
        onTap: _canTap
            ? () {
                BlueMotion.tap();
                widget.onPressed!();
              }
            : null,
        child: AnimatedScale(
          scale: _down ? 0.985 : 1,
          duration: deleteFast,
          curve: deleteEase,
          child: AnimatedContainer(
            duration: deleteBase,
            curve: deleteEase,
            height: BlueDimens.fieldHeight,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: _down ? BlueColors.canvas : BlueColors.white,
              borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
              border: Border.all(color: BlueColors.border),
            ),
            child: Text(
              widget.label,
              style: const TextStyle(
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

class DeleteDangerButton extends StatelessWidget {
  const DeleteDangerButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.busy = false,
    this.enabled = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final bool busy;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final canTap = enabled && !busy;
    return Opacity(
      opacity: (!enabled && !busy) ? 0.4 : 1,
      child: BluePressable(
        enabled: canTap,
        onPressed: canTap ? onPressed : null,
        scale: 0.985,
        duration: deleteFast,
        child: AnimatedContainer(
          duration: deleteBase,
          curve: deleteEase,
          height: BlueDimens.fieldHeight,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: BlueColors.error,
            borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (busy) ...[const _DeleteSpinner(), const SizedBox(width: 11)],
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

class DeleteHostedSheet extends StatelessWidget {
  const DeleteHostedSheet({
    super.key,
    required this.open,
    required this.onDismiss,
    required this.child,
  });

  final bool open;
  final VoidCallback onDismiss;
  final Widget child;

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
                duration: deleteSlow,
                curve: deleteEase,
                opacity: open ? 1 : 0,
                child: const ColoredBox(
                  color: deleteSheetScrim,
                  child: SizedBox.expand(),
                ),
              ),
            ),
            TweenAnimationBuilder<double>(
              duration: deleteSlow,
              curve: deleteEase,
              tween: Tween(begin: 0, end: open ? 1 : 0),
              builder: (context, t, child) {
                return Opacity(
                  opacity: 0.6 + (0.4 * t),
                  child: Transform.translate(
                    offset: Offset(0, 14 * (1 - t)),
                    child: child,
                  ),
                );
              },
              child: child,
            ),
          ],
        ),
      ),
    );
  }
}

class DeleteConfirmSheet extends StatelessWidget {
  const DeleteConfirmSheet({
    super.key,
    required this.busy,
    required this.error,
    required this.onConfirm,
    required this.onKeep,
  });

  final bool busy;
  final String? error;
  final VoidCallback onConfirm;
  final VoidCallback onKeep;

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return Align(
      alignment: Alignment.bottomCenter,
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
          child: Padding(
            padding: EdgeInsets.fromLTRB(24, 22, 24, bottom < 30 ? 30 : bottom),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Center(
                  child: Transform.translate(
                    offset: const Offset(0, -8),
                    child: Container(
                      width: 38,
                      height: 4,
                      decoration: BoxDecoration(
                        color: BlueColors.border,
                        borderRadius: BorderRadius.circular(2),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                const Text(
                  'Delete your account?',
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
                const Text(
                  "Your BLUE account, saved properties and preferences will be permanently deleted. This can't be undone.",
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 14,
                    height: 1.5,
                    fontWeight: FontWeight.w400,
                    color: BlueColors.muted,
                  ),
                ),
                if (error != null) ...[
                  const SizedBox(height: 12),
                  BlueErrorHint(message: error!),
                ],
                const SizedBox(height: 20),
                DeleteDangerButton(
                  key: const Key('delete-account-confirm'),
                  label: busy ? 'Deleting…' : 'Delete account',
                  busy: busy,
                  onPressed: onConfirm,
                ),
                const SizedBox(height: 10),
                DeleteGhostButton(
                  label: 'Keep my account',
                  enabled: !busy,
                  onPressed: onKeep,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class DeleteAccountLink extends StatelessWidget {
  const DeleteAccountLink({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: BluePressable(
        onPressed: onPressed,
        scale: 0.96,
        child: const Padding(
          padding: EdgeInsets.symmetric(horizontal: 10),
          child: SizedBox(
            height: 44,
            child: Center(
              child: Text(
                'Delete account',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 14 * 0.005,
                  color: BlueColors.error,
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _DeleteSpinner extends StatefulWidget {
  const _DeleteSpinner();

  @override
  State<_DeleteSpinner> createState() => _DeleteSpinnerState();
}

class _DeleteSpinnerState extends State<_DeleteSpinner>
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
      child: CustomPaint(
        size: const Size(17, 17),
        painter: _DeleteSpinnerPainter(),
      ),
    );
  }
}

class _DeleteSpinnerPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final track = Paint()
      ..color = const Color(0x52FFFFFF)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;
    final arc = Paint()
      ..color = BlueColors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round;
    final rect = Rect.fromLTWH(1, 1, size.width - 2, size.height - 2);
    canvas.drawCircle(
      Offset(size.width / 2, size.height / 2),
      (size.width - 2) / 2,
      track,
    );
    canvas.drawArc(rect, -1.2, 1.6, false, arc);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

Paint _stroke(Color color, double width, Size size) {
  final k = size.width / 24;
  return Paint()
    ..color = color
    ..style = PaintingStyle.stroke
    ..strokeWidth = width * k
    ..strokeCap = StrokeCap.round
    ..strokeJoin = StrokeJoin.round;
}

class _DeleteBackPainter extends CustomPainter {
  const _DeleteBackPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = _stroke(BlueColors.ink, 2.1, size);
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

class DeleteCalendarPainter extends CustomPainter {
  const DeleteCalendarPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = _stroke(BlueColors.chevron, 1.9, size);
    final sx = size.width / 24;
    final sy = size.height / 24;
    final body = Path()
      ..moveTo(4.5 * sx, 6.5 * sy)
      ..lineTo(19.5 * sx, 6.5 * sy)
      ..lineTo(19.5 * sx, 19.5 * sy)
      ..arcToPoint(Offset(18.5 * sx, 20.5 * sy), radius: Radius.circular(sx))
      ..lineTo(5.5 * sx, 20.5 * sy)
      ..arcToPoint(Offset(4.5 * sx, 19.5 * sy), radius: Radius.circular(sx))
      ..close();
    canvas.drawPath(body, paint);
    canvas.drawLine(Offset(9 * sx, 3.5 * sy), Offset(9 * sx, 7.5 * sy), paint);
    canvas.drawLine(
      Offset(15 * sx, 3.5 * sy),
      Offset(15 * sx, 7.5 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(4.5 * sx, 11 * sy),
      Offset(19.5 * sx, 11 * sy),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class DeleteDocumentPainter extends CustomPainter {
  const DeleteDocumentPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = _stroke(BlueColors.chevron, 1.9, size);
    final sx = size.width / 24;
    final sy = size.height / 24;
    final page = Path()
      ..moveTo(7 * sx, 3.5 * sy)
      ..lineTo(14.2 * sx, 3.5 * sy)
      ..lineTo(19 * sx, 8.4 * sy)
      ..lineTo(19 * sx, 20 * sy)
      ..arcToPoint(Offset(18 * sx, 21 * sy), radius: Radius.circular(sx))
      ..lineTo(7 * sx, 21 * sy)
      ..arcToPoint(Offset(6 * sx, 20 * sy), radius: Radius.circular(sx))
      ..lineTo(6 * sx, 4.5 * sy)
      ..arcToPoint(Offset(7 * sx, 3.5 * sy), radius: Radius.circular(sx));
    canvas.drawPath(page, paint);
    final fold = Path()
      ..moveTo(13.8 * sx, 3.8 * sy)
      ..lineTo(13.8 * sx, 8.8 * sy)
      ..lineTo(18.8 * sx, 8.8 * sy);
    canvas.drawPath(fold, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class DeleteClockPainter extends CustomPainter {
  const DeleteClockPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = _stroke(BlueColors.chevron, 1.9, size);
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawCircle(Offset(12 * sx, 12 * sy), 8.6 * sx, paint);
    final hands = Path()
      ..moveTo(12 * sx, 7.6 * sy)
      ..lineTo(12 * sx, 12.5 * sy)
      ..lineTo(15 * sx, 14.5 * sy);
    canvas.drawPath(hands, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class DeleteInfoPainter extends CustomPainter {
  const DeleteInfoPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = _stroke(BlueColors.unavailableInk, 2.1, size);
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawCircle(Offset(12 * sx, 12 * sy), 8.6 * sx, paint);
    canvas.drawLine(Offset(12 * sx, 8 * sy), Offset(12 * sx, 12.6 * sy), paint);
    canvas.drawLine(
      Offset(12 * sx, 15.8 * sy),
      Offset(12.01 * sx, 15.8 * sy),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
