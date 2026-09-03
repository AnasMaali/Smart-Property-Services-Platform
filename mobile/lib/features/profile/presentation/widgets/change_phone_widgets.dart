import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';

const changePhoneEase = Cubic(0.2, 0, 0.2, 1);
const changePhoneFast = Duration(milliseconds: 140);
const changePhoneBase = Duration(milliseconds: 180);
const changePhoneSlow = Duration(milliseconds: 220);

const changePhoneGold = LinearGradient(
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

class ChangePhoneBackButton extends StatefulWidget {
  const ChangePhoneBackButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  State<ChangePhoneBackButton> createState() => _ChangePhoneBackButtonState();
}

class _ChangePhoneBackButtonState extends State<ChangePhoneBackButton> {
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
          duration: changePhoneFast,
          curve: changePhoneEase,
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: _down ? BlueColors.press : Colors.transparent,
            borderRadius: BorderRadius.circular(22),
          ),
          alignment: Alignment.center,
          child: const CustomPaint(
            size: Size(19, 19),
            painter: _ChangePhoneBackPainter(),
          ),
        ),
      ),
    );
  }
}

class ChangePhoneTitle extends StatelessWidget {
  const ChangePhoneTitle({super.key});

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
                'Change phone number',
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
          child: const Text(
            "Enter the new number you'll use to sign in. We'll send a code to confirm it.",
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

class ChangePhoneHelper extends StatelessWidget {
  const ChangePhoneHelper({super.key});

  @override
  Widget build(BuildContext context) {
    return const Text(
      'Your current number stays active until the new one is confirmed.',
      style: TextStyle(
        fontFamily: BlueFonts.jakarta,
        fontSize: 12.5,
        height: 1.45,
        fontWeight: FontWeight.w400,
        color: BlueColors.muted,
      ),
    );
  }
}

class ChangePhoneContinueButton extends StatelessWidget {
  const ChangePhoneContinueButton({
    super.key,
    required this.enabled,
    required this.busy,
    required this.onPressed,
    this.label = 'Continue',
    this.busyLabel,
  });

  final bool enabled;
  final bool busy;
  final VoidCallback onPressed;
  final String label;
  final String? busyLabel;

  @override
  Widget build(BuildContext context) {
    final awake = enabled || busy;
    return AnimatedOpacity(
      duration: BlueMotion.of(context, changePhoneBase),
      curve: changePhoneEase,
      opacity: awake ? 1 : 0.4,
      child: BluePressable(
        enabled: enabled && !busy,
        onPressed: enabled && !busy ? onPressed : null,
        scale: 0.985,
        duration: changePhoneFast,
        child: AnimatedContainer(
          duration: BlueMotion.of(context, changePhoneBase),
          curve: changePhoneEase,
          height: BlueDimens.fieldHeight,
          decoration: BoxDecoration(
            color: busy ? BlueColors.ctaBusy : BlueColors.ink,
            borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
            boxShadow: awake
                ? const [
                    BoxShadow(
                      color: BlueColors.buttonShadow,
                      offset: Offset(0, 10),
                      blurRadius: 24,
                      spreadRadius: -16,
                    ),
                  ]
                : const [],
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              AnimatedSwitcher(
                duration: BlueMotion.tick,
                switchInCurve: BlueMotion.curve,
                child: busy
                    ? const Padding(
                        key: ValueKey('busy'),
                        padding: EdgeInsets.only(right: 11),
                        child: _ContinueSpinner(),
                      )
                    : const SizedBox.shrink(key: ValueKey('idle')),
              ),
              Text(
                busy ? (busyLabel ?? label) : label,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 16.5,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 16.5 * 0.005,
                  color: BlueColors.white,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class ChangePhoneFooter extends StatelessWidget {
  const ChangePhoneFooter({
    super.key,
    required this.gutter,
    required this.child,
  });

  final double gutter;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    final keyboard = MediaQuery.viewInsetsOf(context).bottom;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: BlueColors.white,
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          gutter,
          12,
          gutter,
          30 + bottom + keyboard,
        ),
        child: child,
      ),
    );
  }
}

class _ContinueSpinner extends StatefulWidget {
  const _ContinueSpinner();

  @override
  State<_ContinueSpinner> createState() => _ContinueSpinnerState();
}

class _ContinueSpinnerState extends State<_ContinueSpinner>
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
      child: CustomPaint(size: const Size(17, 17), painter: _ArcPainter()),
    );
  }
}

class _ArcPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final rect = Rect.fromLTWH(1, 1, size.width - 2, size.height - 2);
    final track = Paint()
      ..color = const Color(0x52FFFFFF)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;
    canvas.drawCircle(rect.center, rect.width / 2, track);
    final paint = Paint()
      ..color = BlueColors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round;
    canvas.drawArc(rect, -1.2, 1.6, false, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _ChangePhoneBackPainter extends CustomPainter {
  const _ChangePhoneBackPainter();

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
