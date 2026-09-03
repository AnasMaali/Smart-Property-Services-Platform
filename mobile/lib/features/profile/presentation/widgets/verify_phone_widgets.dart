import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import 'change_phone_widgets.dart';

class VerifyPhoneTitle extends StatelessWidget {
  const VerifyPhoneTitle({super.key, required this.displayPhone});

  final String displayPhone;

  @override
  Widget build(BuildContext context) {
    final titleSize = MediaQuery.sizeOf(context).width < 359 ? 24.0 : 26.0;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Flexible(
              child: Text(
                'Verify phone number',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: titleSize,
                  height: 1.18,
                  fontWeight: FontWeight.w700,
                  letterSpacing: titleSize * -0.022,
                  color: BlueColors.ink,
                ),
              ),
            ),
            const SizedBox(width: 10),
            const Padding(
              padding: EdgeInsets.only(bottom: 7),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: changePhoneGold,
                  borderRadius: BorderRadius.all(Radius.circular(1)),
                ),
                child: SizedBox(
                  width: BlueDimens.checkoutGoldWidth,
                  height: BlueDimens.checkoutGoldHeight,
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 7),
        ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 300),
          child: Text.rich(
            TextSpan(
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.45,
                fontWeight: FontWeight.w500,
                color: BlueColors.muted,
              ),
              children: [
                const TextSpan(text: 'Enter the 6-digit code we sent to '),
                TextSpan(
                  text: displayPhone,
                  style: const TextStyle(
                    fontWeight: FontWeight.w700,
                    color: BlueColors.ink,
                  ),
                ),
                const TextSpan(text: '.'),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class VerifyPhoneEditLink extends StatefulWidget {
  const VerifyPhoneEditLink({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  State<VerifyPhoneEditLink> createState() => _VerifyPhoneEditLinkState();
}

class _VerifyPhoneEditLinkState extends State<VerifyPhoneEditLink> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(-10, 0),
      child: Align(
        alignment: Alignment.centerLeft,
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
            duration: changePhoneFast,
            curve: changePhoneEase,
            constraints: const BoxConstraints(minHeight: 44),
            padding: const EdgeInsets.symmetric(horizontal: 10),
            alignment: Alignment.centerLeft,
            decoration: BoxDecoration(
              color: _down ? BlueColors.press : Colors.transparent,
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Text(
              'Edit phone number',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: BlueColors.ink,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class VerifyPhoneOtpBoxes extends StatelessWidget {
  const VerifyPhoneOtpBoxes({
    super.key,
    required this.code,
    required this.focused,
    required this.invalid,
  });

  final String code;
  final bool focused;
  final bool invalid;

  @override
  Widget build(BuildContext context) {
    final active = code.length.clamp(0, 5);
    return SizedBox(
      height: BlueDimens.otpBoxHeight,
      child: Row(
        children: [
          for (var i = 0; i < 6; i++) ...[
            if (i > 0) const SizedBox(width: 8),
            Expanded(
              child: _OtpCell(
                char: i < code.length ? code[i] : '',
                focused: focused && i == active,
                invalid: invalid,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _OtpCell extends StatelessWidget {
  const _OtpCell({
    required this.char,
    required this.focused,
    required this.invalid,
  });

  final String char;
  final bool focused;
  final bool invalid;

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        AnimatedContainer(
          duration: BlueMotion.of(context, changePhoneBase),
          curve: changePhoneEase,
          height: BlueDimens.otpBoxHeight,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: BlueColors.white,
            borderRadius: BorderRadius.circular(BlueDimens.otpBoxRadius),
            border: Border.all(
              color: invalid ? BlueColors.fieldError : BlueColors.border,
            ),
          ),
          child: AnimatedSwitcher(
            duration: BlueMotion.tick,
            switchInCurve: BlueMotion.curve,
            child: Text(
              char,
              key: ValueKey(char),
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 23,
                fontWeight: FontWeight.w700,
                letterSpacing: 23 * 0.01,
                color: BlueColors.ink,
              ),
            ),
          ),
        ),
        Positioned.fill(
          child: IgnorePointer(
            child: AnimatedContainer(
              duration: BlueMotion.of(context, changePhoneBase),
              curve: changePhoneEase,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(
                  BlueDimens.otpBoxRadius + 1,
                ),
                border: Border.all(
                  color: focused ? BlueColors.ink : Colors.transparent,
                  width: 2,
                ),
                boxShadow: focused
                    ? const [
                        BoxShadow(color: BlueColors.glowInk, spreadRadius: 4),
                      ]
                    : const [],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class VerifyPhoneResend extends StatefulWidget {
  const VerifyPhoneResend({
    super.key,
    required this.secondsLeft,
    required this.onResend,
  });

  final int secondsLeft;
  final VoidCallback onResend;

  @override
  State<VerifyPhoneResend> createState() => _VerifyPhoneResendState();
}

class _VerifyPhoneResendState extends State<VerifyPhoneResend> {
  bool _down = false;

  String get _clock {
    final minutes = widget.secondsLeft ~/ 60;
    final seconds = widget.secondsLeft % 60;
    return '$minutes:${seconds.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    if (widget.secondsLeft > 0) {
      return SizedBox(
        height: 44,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Text(
              'Resend code in',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
            const SizedBox(width: 5),
            Text(
              _clock,
              style: const TextStyle(
                fontFamily: BlueFonts.mono,
                fontSize: 14,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
                fontFeatures: [FontFeature.tabularFigures()],
              ),
            ),
          ],
        ),
      );
    }

    return Center(
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: (_) => setState(() => _down = true),
        onTapUp: (_) => setState(() => _down = false),
        onTapCancel: () => setState(() => _down = false),
        onTap: () {
          BlueMotion.tap();
          widget.onResend();
        },
        child: AnimatedContainer(
          duration: changePhoneFast,
          curve: changePhoneEase,
          constraints: const BoxConstraints(minHeight: 44),
          padding: const EdgeInsets.symmetric(horizontal: 10),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: _down ? BlueColors.press : Colors.transparent,
            borderRadius: BorderRadius.circular(12),
          ),
          child: const Text(
            'Resend code',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14,
              fontWeight: FontWeight.w700,
              color: BlueColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}

class VerifyPhoneLockNote extends StatelessWidget {
  const VerifyPhoneLockNote({super.key});

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 2),
          child: CustomPaint(
            size: const Size(14, 14),
            painter: const _ShieldPainter(),
          ),
        ),
        SizedBox(width: 8),
        Flexible(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: 250),
            child: Text(
              'Codes expire after 10 minutes. Never share it with anyone.',
              style: TextStyle(
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
  }
}

class VerifyPhoneToast extends StatelessWidget {
  const VerifyPhoneToast({super.key, required this.visible});

  final bool visible;

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return IgnorePointer(
      child: AnimatedSlide(
        duration: BlueMotion.of(context, changePhoneSlow),
        curve: visible ? changePhoneEase : BlueMotion.exitCurve,
        offset: visible ? Offset.zero : const Offset(0, 0.35),
        child: AnimatedOpacity(
          duration: BlueMotion.of(context, changePhoneSlow),
          curve: changePhoneEase,
          opacity: visible ? 1 : 0,
          child: Padding(
            padding: EdgeInsets.fromLTRB(24, 0, 24, 30 + bottom),
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: BlueColors.ink,
                borderRadius: BorderRadius.circular(18),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0xBF140050),
                    offset: Offset(0, 16),
                    blurRadius: 34,
                    spreadRadius: -22,
                  ),
                ],
              ),
              child: const Padding(
                padding: EdgeInsets.fromLTRB(16, 13, 16, 13),
                child: Row(
                  children: [
                    CustomPaint(
                      size: Size(16, 16),
                      painter: _ToastCheckPainter(),
                    ),
                    SizedBox(width: 10),
                    Flexible(
                      child: Text(
                        'Phone number updated',
                        style: TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 14,
                          fontWeight: FontWeight.w600,
                          letterSpacing: 14 * 0.005,
                          color: BlueColors.white,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ShieldPainter extends CustomPainter {
  const _ShieldPainter();

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
    final path = Path()
      ..moveTo(12 * sx, 3.8 * sy)
      ..lineTo(19 * sx, 6.8 * sy)
      ..lineTo(19 * sx, 12.2 * sy)
      ..cubicTo(19 * sx, 16.2 * sy, 16.1 * sx, 19.1 * sy, 12 * sx, 20.1 * sy)
      ..cubicTo(7.9 * sx, 19.1 * sy, 5 * sx, 16.2 * sy, 5 * sx, 12.2 * sy)
      ..lineTo(5 * sx, 6.8 * sy)
      ..close();
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _ToastCheckPainter extends CustomPainter {
  const _ToastCheckPainter();

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
