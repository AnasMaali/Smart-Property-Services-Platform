import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../data/payment_models.dart';

const _methodFade = Duration(milliseconds: 160);
const _ctaFade = Duration(milliseconds: 180);
const _confirmMark = Duration(milliseconds: 340);
const _sheetIn = Duration(milliseconds: 240);
const _fade = Duration(milliseconds: 180);
const _reveal = Duration(milliseconds: 200);
const _spin = Duration(milliseconds: 800);
const _busySpin = Duration(milliseconds: 700);
const _track = Duration(milliseconds: 1500);
const _press = Duration(milliseconds: 140);
const _skel = Duration(milliseconds: 1400);
const _resultFade = Duration(milliseconds: 240);

const _confirmCurve = Cubic(0.2, 0.8, 0.2, 1);
const _trackCurve = Cubic(0.4, 0, 0.6, 1);

const _goldLine = LinearGradient(
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

const _goldDot = LinearGradient(
  begin: Alignment.topLeft,
  end: Alignment.bottomRight,
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

const _goldRing = LinearGradient(
  begin: Alignment.topLeft,
  end: Alignment.bottomRight,
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

class PaymentBackButton extends StatelessWidget {
  const PaymentBackButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(-11, 0),
      child: BluePressable(
        onPressed: onPressed,
        scale: 0.96,
        duration: _press,
        child: const SizedBox(
          width: 44,
          height: 44,
          child: Center(
            child: CustomPaint(
              size: Size(20, 20),
              painter: PaymentBackPainter(),
            ),
          ),
        ),
      ),
    );
  }
}

class PaymentLockedBack extends StatelessWidget {
  const PaymentLockedBack({super.key, required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        const CustomPaint(
          size: Size(15, 15),
          painter: PaymentLockPainter(color: BlueColors.chevron, stroke: 2),
        ),
        const SizedBox(width: 9),
        Text(
          label,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 12.5,
            fontWeight: FontWeight.w600,
            color: BlueColors.muted,
          ),
        ),
      ],
    );
  }
}

class PaymentHairline extends StatelessWidget {
  const PaymentHairline({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        20,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Divider(height: 1, thickness: 1, color: BlueColors.navLine),
    );
  }
}

class PaymentAmountBlock extends StatelessWidget {
  const PaymentAmountBlock({super.key, required this.amount});

  final String amount;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(
        horizontal: BlueDimens.checkoutGutter,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Total to pay',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13,
              fontWeight: FontWeight.w600,
              letterSpacing: 13 * 0.02,
              color: BlueColors.muted,
            ),
          ),
          const SizedBox(height: 5),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Flexible(
                child: Text(
                  amount,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: BlueDimens.paymentAmount,
                    height: 1.06,
                    fontWeight: FontWeight.w800,
                    letterSpacing: BlueDimens.paymentAmount * -0.028,
                    fontFeatures: [FontFeature.tabularFigures()],
                    color: BlueColors.ink,
                  ),
                ),
              ),
              const SizedBox(width: 10),
              const Padding(
                padding: EdgeInsets.only(bottom: 9),
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: _goldLine,
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
          const SizedBox(height: 9),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 300),
            child: const Text(
              'The final amount confirmed by BLUE. Nothing is charged until you tap Pay.',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.placeholder,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class PaymentCancelledBanner extends StatelessWidget {
  const PaymentCancelledBanner({super.key});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        0,
        BlueDimens.checkoutGutter,
        18,
      ),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: BlueColors.chipSurface,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: BlueColors.border),
        ),
        child: const Padding(
          padding: EdgeInsets.fromLTRB(15, 13, 15, 13),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Payment cancelled',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 13.5,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 13.5 * -0.005,
                  color: BlueColors.ink,
                ),
              ),
              SizedBox(height: 5),
              Text(
                "You closed the payment sheet, so nothing was charged. Your appointment is still reserved — pay whenever you're ready.",
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  height: 1.45,
                  fontWeight: FontWeight.w500,
                  color: BlueColors.muted,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class PaymentSummaryBlock extends StatelessWidget {
  const PaymentSummaryBlock({
    super.key,
    required this.lines,
    required this.onDetails,
  });

  final List<PaymentSummaryLine> lines;
  final VoidCallback onDetails;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        18,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              const Expanded(
                child: Text(
                  "You're paying for",
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 15 * -0.01,
                    color: BlueColors.ink,
                  ),
                ),
              ),
              BluePressable(
                onPressed: onDetails,
                scale: 0.98,
                duration: _press,
                child: const SizedBox(
                  height: 36,
                  child: Align(
                    alignment: Alignment.centerRight,
                    child: Text(
                      'View details',
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: BlueColors.ink,
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          for (var i = 0; i < lines.length; i++) ...[
            if (i > 0) const SizedBox(height: 9),
            _SummaryRow(line: lines[i]),
          ],
        ],
      ),
    );
  }
}

class _SummaryRow extends StatelessWidget {
  const _SummaryRow({required this.line});

  final PaymentSummaryLine line;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 2),
          child: CustomPaint(
            size: const Size(15, 15),
            painter: PaymentLinePainter(line.kind),
          ),
        ),
        const SizedBox(width: 11),
        Expanded(
          child: Text(
            line.text,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13.5,
              height: 1.45,
              fontWeight: line.weight == FontWeightToken.semibold
                  ? FontWeight.w600
                  : FontWeight.w500,
              color: line.ink == PaymentInk.ink
                  ? BlueColors.ink
                  : BlueColors.body,
            ),
          ),
        ),
      ],
    );
  }
}

class PaymentHoldBanner extends StatelessWidget {
  const PaymentHoldBanner({
    super.key,
    required this.seconds,
    required this.warn,
  });

  final int seconds;
  final bool warn;

  @override
  Widget build(BuildContext context) {
    final clock = formatHoldClock(seconds);
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        16,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: warn ? BlueColors.unavailableSurface : BlueColors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: warn ? BlueColors.unavailableLine : BlueColors.border,
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(15, 13, 15, 13),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  if (warn)
                    const CustomPaint(
                      size: Size(15, 15),
                      painter: PaymentAlertPainter(
                        color: BlueColors.unavailableInk,
                        stroke: 2.1,
                      ),
                    )
                  else
                    const DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: _goldDot,
                        borderRadius: BorderRadius.all(Radius.circular(4)),
                      ),
                      child: SizedBox(
                        width: BlueDimens.checkoutHoldDot,
                        height: BlueDimens.checkoutHoldDot,
                      ),
                    ),
                  const SizedBox(width: 9),
                  Expanded(
                    child: Text(
                      'Appointment reserved for $clock',
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 13 * 0.005,
                        fontFeatures: const [FontFeature.tabularFigures()],
                        color: warn
                            ? BlueColors.unavailableInk
                            : BlueColors.body,
                      ),
                    ),
                  ),
                ],
              ),
              if (warn) ...[
                const SizedBox(height: 6),
                const Text(
                  'Complete payment soon to keep this appointment. If it expires you can pick another time — nothing will have been charged.',
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12.5,
                    height: 1.45,
                    fontWeight: FontWeight.w500,
                    color: BlueColors.unavailableInk,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class PaymentMethodBlock extends StatelessWidget {
  const PaymentMethodBlock({
    super.key,
    required this.method,
    required this.onChange,
  });

  final PaymentMethod? method;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        18,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Payment method',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 15,
              fontWeight: FontWeight.w700,
              letterSpacing: 15 * -0.01,
              color: BlueColors.ink,
            ),
          ),
          if (method != null)
            Padding(
              padding: const EdgeInsets.only(top: 11),
              child: _MethodRow(method: method!, onChange: onChange),
            )
          else
            Padding(
              padding: const EdgeInsets.only(top: 11),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 290),
                    child: const Text(
                      "Choose how you'd like to pay. Card details are entered in $paymentProviderName's secure sheet — BLUE never sees or stores them.",
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 13.5,
                        height: 1.5,
                        fontWeight: FontWeight.w400,
                        color: BlueColors.muted,
                      ),
                    ),
                  ),
                  const SizedBox(height: 13),
                  BluePressable(
                    onPressed: onChange,
                    scale: 0.985,
                    duration: _press,
                    child: Container(
                      height: 48,
                      padding: const EdgeInsets.symmetric(horizontal: 20),
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: BlueColors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: BlueColors.ghostLine),
                      ),
                      child: const Text(
                        'Select payment method',
                        style: TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 14.5,
                          fontWeight: FontWeight.w700,
                          color: BlueColors.ink,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 14),
          const Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: EdgeInsets.only(top: 2),
                child: CustomPaint(
                  size: Size(14, 14),
                  painter: PaymentLockPainter(
                    color: BlueColors.chevron,
                    stroke: 1.9,
                  ),
                ),
              ),
              SizedBox(width: 8),
              Expanded(
                child: Text(
                  "Card details are handled inside $paymentProviderName's secure sheet. BLUE stores only the card brand and last four digits.",
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12,
                    height: 1.5,
                    fontWeight: FontWeight.w400,
                    color: BlueColors.placeholder,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _MethodRow extends StatelessWidget {
  const _MethodRow({required this.method, required this.onChange});

  final PaymentMethod method;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: BlueColors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: BlueColors.border),
      ),
      child: SizedBox(
        height: BlueDimens.paymentMethodRow,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16),
          child: Row(
            children: [
              PaymentBrandBadge(brand: method.brand),
              const SizedBox(width: 13),
              Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      method.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 14.5 * -0.008,
                        color: BlueColors.ink,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      method.sub,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 12,
                        fontWeight: FontWeight.w500,
                        color: BlueColors.placeholder,
                      ),
                    ),
                  ],
                ),
              ),
              BluePressable(
                onPressed: onChange,
                scale: 0.98,
                duration: _press,
                child: const SizedBox(
                  height: 44,
                  child: Padding(
                    padding: EdgeInsets.symmetric(horizontal: 4),
                    child: Center(
                      child: Text(
                        'Change',
                        style: TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 13.5,
                          fontWeight: FontWeight.w700,
                          color: BlueColors.ink,
                        ),
                      ),
                    ),
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

class PaymentBrandBadge extends StatelessWidget {
  const PaymentBrandBadge({
    super.key,
    required this.brand,
    this.dashed = false,
  });

  final String brand;
  final bool dashed;

  @override
  Widget build(BuildContext context) {
    final child = SizedBox(
      width: 38,
      height: 26,
      child: Center(
        child: brand.isEmpty
            ? const CustomPaint(
                size: Size(14, 14),
                painter: PaymentPlusPainter(),
              )
            : Text(
                brand,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 9,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 9 * 0.02,
                  color: BlueColors.body,
                ),
              ),
      ),
    );
    if (dashed) {
      return CustomPaint(
        painter: const PaymentDashRectPainter(
          color: BlueColors.ghostLine,
          radius: 5,
        ),
        child: child,
      );
    }
    return DecoratedBox(
      decoration: BoxDecoration(
        color: BlueColors.canvas,
        borderRadius: BorderRadius.circular(5),
        border: Border.all(color: BlueColors.border),
      ),
      child: child,
    );
  }
}

class PaymentSkeleton extends StatelessWidget {
  const PaymentSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.symmetric(horizontal: BlueDimens.checkoutGutter),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _Bone(width: 82, height: 11, radius: 5, alt: true),
          SizedBox(height: 10),
          _Bone(width: 184, height: 34, radius: 8),
          SizedBox(height: 12),
          _Bone(width: 246, height: 11, radius: 5, alt: true),
          SizedBox(height: 22),
          Divider(height: 1, thickness: 1, color: BlueColors.navLine),
          SizedBox(height: 20),
          _Bone(width: 126, height: 13, radius: 5),
          SizedBox(height: 14),
          _Bone(width: double.infinity, height: 11, radius: 5, alt: true),
          SizedBox(height: 10),
          _Bone(width: 220, height: 11, radius: 5, alt: true),
          SizedBox(height: 22),
          Divider(height: 1, thickness: 1, color: BlueColors.navLine),
          SizedBox(height: 20),
          _Bone(width: 132, height: 13, radius: 5),
          SizedBox(height: 12),
          _Bone(width: double.infinity, height: 64, radius: 16, alt: true),
        ],
      ),
    );
  }
}

class _Bone extends StatefulWidget {
  const _Bone({
    required this.width,
    required this.height,
    required this.radius,
    this.alt = false,
  });

  final double width;
  final double height;
  final double radius;
  final bool alt;

  @override
  State<_Bone> createState() => _BoneState();
}

class _BoneState extends State<_Bone> with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: _skel)
      ..repeat(reverse: true);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: Tween<double>(begin: 1, end: 0.55).animate(_controller),
      child: Align(
        alignment: Alignment.centerLeft,
        child: Container(
          width: widget.width == double.infinity ? null : widget.width,
          height: widget.height,
          decoration: BoxDecoration(
            color: widget.alt ? BlueColors.skeletonAlt : BlueColors.skeleton,
            borderRadius: BorderRadius.circular(widget.radius),
          ),
        ),
      ),
    );
  }
}

class PaymentResult extends StatelessWidget {
  const PaymentResult({
    super.key,
    required this.phase,
    required this.title,
    required this.body,
    required this.top,
    required this.titleSize,
    this.receipt = const [],
    this.note,
    this.warmNote = false,
  });

  final PaymentPhase phase;
  final String title;
  final String body;
  final double top;
  final double titleSize;
  final List<PaymentReceiptRow> receipt;
  final String? note;
  final bool warmNote;

  bool get _success =>
      phase == PaymentPhase.success || phase == PaymentPhase.alreadyPaid;

  bool get _spinner =>
      phase == PaymentPhase.processing || phase == PaymentPhase.confirming;

  bool get _track =>
      phase == PaymentPhase.processing || phase == PaymentPhase.confirming;

  Color get _iconColor {
    return phase == PaymentPhase.failed || phase == PaymentPhase.initError
        ? BlueColors.error
        : BlueColors.unavailableInk;
  }

  @override
  Widget build(BuildContext context) {
    final live =
        phase == PaymentPhase.failed ||
        phase == PaymentPhase.unknown ||
        phase == PaymentPhase.holdExpired ||
        phase == PaymentPhase.initError ||
        phase == PaymentPhase.priceChanged;
    return Semantics(
      liveRegion: true,
      container: true,
      explicitChildNodes: true,
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          BlueDimens.checkoutGutter,
          top,
          BlueDimens.checkoutGutter,
          0,
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (_success) const PaymentSuccessMark(),
            if (_spinner) const PaymentSpinner(),
            if (!_success && !_spinner)
              CustomPaint(
                size: const Size(27, 27),
                painter: PaymentAlertPainter(color: _iconColor, stroke: 1.9),
              ),
            const SizedBox(height: 16),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 300),
              child: Text(
                title,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: titleSize,
                  height: 1.26,
                  fontWeight: FontWeight.w700,
                  letterSpacing: titleSize * -0.02,
                  color: BlueColors.ink,
                ),
              ),
            ),
            const SizedBox(height: 9),
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 296),
              child: Text(
                body,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 14,
                  height: 1.55,
                  fontWeight: FontWeight.w400,
                  color: BlueColors.muted,
                ),
              ),
            ),
            if (_track) const PaymentTrack(),
            if (receipt.isNotEmpty) ...[
              const SizedBox(height: 22),
              const Divider(height: 1, thickness: 1, color: BlueColors.navLine),
              const SizedBox(height: 18),
              for (var i = 0; i < receipt.length; i++) ...[
                if (i > 0) const SizedBox(height: 12),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.baseline,
                  textBaseline: TextBaseline.alphabetic,
                  children: [
                    Text(
                      receipt[i].label,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 13,
                        fontWeight: FontWeight.w500,
                        color: BlueColors.placeholder,
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Text(
                        receipt[i].value,
                        textAlign: TextAlign.right,
                        style: const TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 13.5,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 13.5 * -0.005,
                          color: BlueColors.ink,
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ],
            if (note != null && note!.isNotEmpty) ...[
              const SizedBox(height: 20),
              _ResultNote(text: note!, warm: warmNote),
            ],
            if (live) const SizedBox.shrink(),
          ],
        ),
      ),
    );
  }
}

class _ResultNote extends StatelessWidget {
  const _ResultNote({required this.text, required this.warm});

  final String text;
  final bool warm;

  @override
  Widget build(BuildContext context) {
    final color = warm ? BlueColors.unavailableInk : BlueColors.body;
    return DecoratedBox(
      decoration: BoxDecoration(
        color: warm ? BlueColors.unavailableSurface : BlueColors.chipSurface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: warm ? BlueColors.unavailableLine : BlueColors.border,
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(15, 13, 15, 13),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 1),
              child: CustomPaint(
                size: const Size(15, 15),
                painter: PaymentAlertPainter(color: color, stroke: 2),
              ),
            ),
            const SizedBox(width: 9),
            Expanded(
              child: Text(
                text,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  height: 1.45,
                  fontWeight: FontWeight.w500,
                  color: color,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class PaymentSuccessMark extends StatefulWidget {
  const PaymentSuccessMark({super.key});

  @override
  State<PaymentSuccessMark> createState() => _PaymentSuccessMarkState();
}

class _PaymentSuccessMarkState extends State<PaymentSuccessMark>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _scale;
  late final Animation<double> _opacity;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: _confirmMark);
    _scale = TweenSequence<double>([
      TweenSequenceItem(tween: Tween(begin: 0.92, end: 1.015), weight: 60),
      TweenSequenceItem(tween: Tween(begin: 1.015, end: 1), weight: 40),
    ]).animate(CurvedAnimation(parent: _controller, curve: _confirmCurve));
    _opacity = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0, 0.6, curve: Curves.easeOut),
      ),
    );
    _controller.forward();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _opacity,
      child: ScaleTransition(
        scale: _scale,
        child: const SizedBox(
          width: BlueDimens.paymentMark,
          height: BlueDimens.paymentMark,
          child: DecoratedBox(
            decoration: BoxDecoration(
              gradient: _goldRing,
              shape: BoxShape.circle,
            ),
            child: Padding(
              padding: EdgeInsets.all(2),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: BlueColors.ink,
                  shape: BoxShape.circle,
                ),
                child: Center(
                  child: CustomPaint(
                    size: Size(26, 26),
                    painter: PaymentCheckPainter(
                      color: BlueColors.white,
                      stroke: 2.6,
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class PaymentSpinner extends StatefulWidget {
  const PaymentSpinner({super.key, this.size = 34, this.stroke = 2.5});

  final double size;
  final double stroke;

  @override
  State<PaymentSpinner> createState() => _PaymentSpinnerState();
}

class _PaymentSpinnerState extends State<PaymentSpinner>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: _spin)..repeat();
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
        size: Size.square(widget.size),
        painter: _SpinnerPainter(stroke: widget.stroke),
      ),
    );
  }
}

class _SpinnerPainter extends CustomPainter {
  const _SpinnerPainter({required this.stroke});

  final double stroke;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final inset = stroke / 2;
    final ring = Rect.fromLTWH(
      inset,
      inset,
      size.width - stroke,
      size.height - stroke,
    );
    final track = Paint()
      ..color = BlueColors.badgeBorder
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke;
    final head = Paint()
      ..color = BlueColors.ink
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round;
    canvas.drawOval(ring, track);
    canvas.drawArc(ring, -1.2, 1.7, false, head);
    rect;
  }

  @override
  bool shouldRepaint(covariant _SpinnerPainter oldDelegate) {
    return oldDelegate.stroke != stroke;
  }
}

class PaymentTrack extends StatefulWidget {
  const PaymentTrack({super.key});

  @override
  State<PaymentTrack> createState() => _PaymentTrackState();
}

class _PaymentTrackState extends State<PaymentTrack>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: _track)..repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: SizedBox(
        width: 150,
        height: 3,
        child: ClipRRect(
          borderRadius: BorderRadius.circular(2),
          child: ColoredBox(
            color: BlueColors.navLine,
            child: AnimatedBuilder(
              animation: _controller,
              builder: (context, child) {
                final t = _trackCurve.transform(_controller.value);
                return Transform.translate(
                  offset: Offset(-150 + (150 * 3.4 * t), 0),
                  child: child,
                );
              },
              child: Align(
                alignment: Alignment.centerLeft,
                child: Container(
                  width: 150 * 0.42,
                  height: 3,
                  decoration: BoxDecoration(
                    color: BlueColors.ink,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class PaymentActions extends StatelessWidget {
  const PaymentActions({
    super.key,
    required this.cta,
    required this.enabled,
    required this.onPrimary,
    this.secondary,
    this.onSecondary,
    this.foot,
    this.busy = false,
  });

  final String cta;
  final bool enabled;
  final VoidCallback onPrimary;
  final String? secondary;
  final VoidCallback? onSecondary;
  final String? foot;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: BlueColors.barFill,
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          BlueDimens.checkoutGutter,
          12,
          BlueDimens.checkoutGutter,
          bottom < 30 ? 30 : bottom,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            BluePressable(
              enabled: enabled && !busy,
              onPressed: enabled && !busy ? onPrimary : null,
              scale: 0.99,
              duration: _press,
              child: AnimatedContainer(
                duration: BlueMotion.of(context, _ctaFade),
                curve: BlueMotion.curve,
                constraints: const BoxConstraints(
                  minHeight: BlueDimens.paymentCta,
                ),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: enabled ? BlueColors.ink : BlueColors.ctaDisabled,
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    if (busy) ...[
                      const SizedBox(
                        width: 16,
                        height: 16,
                        child: PaymentBusySpinner(),
                      ),
                      const SizedBox(width: 10),
                    ],
                    Text(
                      cta,
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 15.5,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 15.5 * -0.005,
                        color: enabled
                            ? BlueColors.white
                            : BlueColors.ctaDisabledText,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            if (secondary != null && secondary!.isNotEmpty) ...[
              const SizedBox(height: 4),
              BluePressable(
                onPressed: onSecondary,
                scale: 0.98,
                duration: _press,
                child: SizedBox(
                  width: double.infinity,
                  height: 44,
                  child: Center(
                    child: Text(
                      secondary!,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: BlueColors.ink,
                      ),
                    ),
                  ),
                ),
              ),
            ],
            if (foot != null && foot!.isNotEmpty) ...[
              const SizedBox(height: 9),
              Text(
                foot!,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 11.5,
                  height: 1.45,
                  fontWeight: FontWeight.w400,
                  color: BlueColors.placeholder,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class PaymentBusySpinner extends StatefulWidget {
  const PaymentBusySpinner({super.key});

  @override
  State<PaymentBusySpinner> createState() => _PaymentBusySpinnerState();
}

class _PaymentBusySpinnerState extends State<PaymentBusySpinner>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: _busySpin)
      ..repeat();
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
      child: DecoratedBox(
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          border: Border.all(color: const Color(0x59FFFFFF), width: 2),
        ),
        child: const SizedBox.expand(),
      ),
    );
  }
}

class PaymentMethodSheet extends StatelessWidget {
  const PaymentMethodSheet({
    super.key,
    required this.open,
    required this.selectedId,
    required this.onClose,
    required this.onPick,
    required this.onNewCard,
  });

  final bool open;
  final String? selectedId;
  final VoidCallback onClose;
  final ValueChanged<String> onPick;
  final VoidCallback onNewCard;

  @override
  Widget build(BuildContext context) {
    if (!open) return const SizedBox.shrink();
    return Positioned.fill(
      child: IgnorePointer(
        ignoring: !open,
        child: Stack(
          children: [
            GestureDetector(
              onTap: onClose,
              child: AnimatedOpacity(
                duration: BlueMotion.of(context, open ? _fade : _ctaFade),
                curve: Curves.easeOut,
                opacity: open ? 1 : 0,
                child: const ColoredBox(
                  color: BlueColors.methodScrim,
                  child: SizedBox.expand(),
                ),
              ),
            ),
            AnimatedSlide(
              duration: BlueMotion.of(
                context,
                open ? _sheetIn : BlueMotion.sheetOut,
              ),
              curve: open ? _confirmCurve : BlueMotion.exitCurve,
              offset: open ? Offset.zero : const Offset(0, 1),
              child: Align(
                alignment: Alignment.bottomCenter,
                child: Material(
                  color: Colors.transparent,
                  child: DecoratedBox(
                    decoration: const BoxDecoration(
                      color: BlueColors.white,
                      borderRadius: BorderRadius.vertical(
                        top: Radius.circular(24),
                      ),
                    ),
                    child: Padding(
                      padding: EdgeInsets.only(
                        top: 10,
                        bottom: MediaQuery.paddingOf(context).bottom + 30,
                      ),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(
                            width: 38,
                            height: 4,
                            decoration: BoxDecoration(
                              color: BlueColors.badgeBorder,
                              borderRadius: BorderRadius.circular(2),
                            ),
                          ),
                          Padding(
                            padding: const EdgeInsets.fromLTRB(22, 14, 11, 0),
                            child: Row(
                              children: [
                                const Expanded(
                                  child: Text(
                                    'Payment method',
                                    style: TextStyle(
                                      fontFamily: BlueFonts.jakarta,
                                      fontSize: 18,
                                      fontWeight: FontWeight.w700,
                                      letterSpacing: 18 * -0.018,
                                      color: BlueColors.ink,
                                    ),
                                  ),
                                ),
                                Transform.translate(
                                  offset: const Offset(11, 0),
                                  child: BluePressable(
                                    onPressed: onClose,
                                    scale: 0.92,
                                    child: const SizedBox(
                                      width: 44,
                                      height: 44,
                                      child: Center(
                                        child: CustomPaint(
                                          size: Size(16, 16),
                                          painter: PaymentClosePainter(),
                                        ),
                                      ),
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          Padding(
                            padding: const EdgeInsets.fromLTRB(22, 14, 22, 0),
                            child: Column(
                              children: [
                                for (final method in paymentSavedMethods) ...[
                                  _MethodChoice(
                                    method: method,
                                    selected: selectedId == method.id,
                                    onPressed: () => onPick(method.id),
                                  ),
                                  const SizedBox(height: 9),
                                ],
                                _NewCardChoice(onPressed: onNewCard),
                              ],
                            ),
                          ),
                          const Padding(
                            padding: EdgeInsets.fromLTRB(22, 16, 22, 0),
                            child: Text(
                              "Saved methods are held by $paymentProviderName as tokens. BLUE stores only the brand and last four digits — never a full card number or security code.",
                              style: TextStyle(
                                fontFamily: BlueFonts.jakarta,
                                fontSize: 11.5,
                                height: 1.5,
                                fontWeight: FontWeight.w400,
                                color: BlueColors.placeholder,
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
          ],
        ),
      ),
    );
  }
}

class _MethodChoice extends StatelessWidget {
  const _MethodChoice({
    required this.method,
    required this.selected,
    required this.onPressed,
  });

  final PaymentMethod method;
  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      label: '${method.a11y}${selected ? ', selected' : ''}',
      child: BluePressable(
        onPressed: onPressed,
        scale: 0.99,
        duration: _press,
        child: AnimatedContainer(
          duration: BlueMotion.of(context, _methodFade),
          curve: BlueMotion.curve,
          constraints: const BoxConstraints(minHeight: 66),
          padding: const EdgeInsets.symmetric(horizontal: 15),
          decoration: BoxDecoration(
            color: BlueColors.white,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(
              color: selected ? BlueColors.ink : BlueColors.border,
              width: 1.5,
            ),
          ),
          child: Row(
            children: [
              PaymentBrandBadge(brand: method.brand),
              const SizedBox(width: 13),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      method.title,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 14.5 * -0.008,
                        color: BlueColors.ink,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      method.sub,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 12,
                        fontWeight: FontWeight.w500,
                        color: BlueColors.placeholder,
                      ),
                    ),
                  ],
                ),
              ),
              if (selected)
                const CustomPaint(
                  size: Size(17, 17),
                  painter: PaymentCheckPainter(
                    color: BlueColors.ink,
                    stroke: 2.5,
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NewCardChoice extends StatelessWidget {
  const _NewCardChoice({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.99,
      duration: _press,
      child: CustomPaint(
        painter: const PaymentDashRectPainter(
          color: BlueColors.ghostLine,
          radius: 16,
          stroke: 1.5,
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 15, vertical: 10),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 46),
            child: const Row(
              children: [
                PaymentBrandBadge(brand: '', dashed: true),
                SizedBox(width: 13),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        'Pay with a new card',
                        style: TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 14.5,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 14.5 * -0.008,
                          color: BlueColors.ink,
                        ),
                      ),
                      SizedBox(height: 2),
                      Text(
                        "Opens $paymentProviderName's secure sheet",
                        style: TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 12,
                          height: 1.4,
                          fontWeight: FontWeight.w500,
                          color: BlueColors.placeholder,
                        ),
                      ),
                    ],
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

class PaymentProviderSheet extends StatelessWidget {
  const PaymentProviderSheet({
    super.key,
    required this.open,
    required this.onDone,
  });

  final bool open;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    if (!open) return const SizedBox.shrink();
    return Positioned.fill(
      child: IgnorePointer(
        ignoring: !open,
        child: Stack(
          children: [
            AnimatedOpacity(
              duration: BlueMotion.of(context, _fade),
              opacity: open ? 1 : 0,
              child: const ColoredBox(
                color: BlueColors.providerScrim,
                child: SizedBox.expand(),
              ),
            ),
            AnimatedSlide(
              duration: BlueMotion.of(
                context,
                open ? _sheetIn : BlueMotion.sheetOut,
              ),
              curve: open ? _confirmCurve : BlueMotion.exitCurve,
              offset: open ? Offset.zero : const Offset(0, 1),
              child: Align(
                alignment: Alignment.bottomCenter,
                child: SizedBox(
                  height: 470,
                  width: double.infinity,
                  child: CustomPaint(
                    painter: const PaymentDashRectPainter(
                      color: BlueColors.paymentDash,
                      radius: 16,
                      stroke: 1,
                      topOnly: true,
                    ),
                    child: DecoratedBox(
                      decoration: const BoxDecoration(
                        color: BlueColors.paymentSheet,
                        borderRadius: BorderRadius.vertical(
                          top: Radius.circular(16),
                        ),
                      ),
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 34),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Text(
                              '${paymentProviderName.toUpperCase()} PAYMENT SHEET',
                              textAlign: TextAlign.center,
                              style: const TextStyle(
                                fontFamily: BlueFonts.mono,
                                fontSize: 11,
                                letterSpacing: 11 * 0.16,
                                fontWeight: FontWeight.w500,
                                color: BlueColors.placeholder,
                              ),
                            ),
                            const SizedBox(height: 12),
                            ConstrainedBox(
                              constraints: const BoxConstraints(maxWidth: 270),
                              child: const Text(
                                'Provider-owned UI. Card entry, saved cards, wallets and 3-D Secure all happen here — BLUE renders nothing inside this area and never receives the card data.',
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 13,
                                  height: 1.55,
                                  fontWeight: FontWeight.w400,
                                  color: BlueColors.muted,
                                ),
                              ),
                            ),
                            const SizedBox(height: 20),
                            BluePressable(
                              onPressed: onDone,
                              scale: 0.985,
                              child: Container(
                                height: 44,
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 18,
                                ),
                                alignment: Alignment.center,
                                decoration: BoxDecoration(
                                  color: BlueColors.white,
                                  borderRadius: BorderRadius.circular(14),
                                  border: Border.all(
                                    color: BlueColors.ghostLine,
                                  ),
                                ),
                                child: const Text(
                                  'Simulate return',
                                  style: TextStyle(
                                    fontFamily: BlueFonts.jakarta,
                                    fontSize: 13.5,
                                    fontWeight: FontWeight.w700,
                                    color: BlueColors.ink,
                                  ),
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
            ),
          ],
        ),
      ),
    );
  }
}

class PaymentBackPainter extends CustomPainter {
  const PaymentBackPainter();

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

class PaymentLockPainter extends CustomPainter {
  const PaymentLockPainter({required this.color, required this.stroke});

  final Color color;
  final double stroke;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke * (size.width / 24)
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawRRect(
      RRect.fromLTRBR(
        5 * sx,
        10.6 * sy,
        19 * sx,
        20.6 * sy,
        Radius.circular(2.4 * sx),
      ),
      paint,
    );
    final shackle = Path()
      ..moveTo(8.4 * sx, 10.6 * sy)
      ..lineTo(8.4 * sx, 8.2 * sy)
      ..cubicTo(8.4 * sx, 6.2 * sy, 10 * sx, 4.6 * sy, 12 * sx, 4.6 * sy)
      ..cubicTo(14 * sx, 4.6 * sy, 15.6 * sx, 6.2 * sy, 15.6 * sx, 8.2 * sy)
      ..lineTo(15.6 * sx, 10.6 * sy);
    canvas.drawPath(shackle, paint);
  }

  @override
  bool shouldRepaint(covariant PaymentLockPainter oldDelegate) {
    return oldDelegate.color != color || oldDelegate.stroke != stroke;
  }
}

class PaymentLinePainter extends CustomPainter {
  const PaymentLinePainter(this.kind);

  final PaymentLineKind kind;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.chevron
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.9 * (size.width / 15)
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    switch (kind) {
      case PaymentLineKind.bag:
        final bag = Path()
          ..moveTo(4 * sx, 5 * sy)
          ..lineTo(6.2 * sx, 5 * sy)
          ..lineTo(8.5 * sx, 15.6 * sy)
          ..arcToPoint(Offset(9.5 * sx, 16.4 * sy), radius: Radius.circular(sx))
          ..lineTo(17.7 * sx, 16.4 * sy)
          ..arcToPoint(
            Offset(18.7 * sx, 15.62 * sy),
            radius: Radius.circular(sx),
          )
          ..lineTo(20.5 * sx, 9 * sy)
          ..lineTo(7 * sx, 9 * sy);
        canvas.drawPath(bag, paint);
      case PaymentLineKind.calendar:
        canvas.drawRRect(
          RRect.fromLTRBR(
            5 * sx,
            6.5 * sy,
            19 * sx,
            21 * sy,
            Radius.circular(sx),
          ),
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
      case PaymentLineKind.pin:
        final pin = Path()
          ..moveTo(12 * sx, 21 * sy)
          ..cubicTo(
            16.3 * sx,
            16.4 * sy,
            18.4 * sx,
            13.1 * sy,
            18.4 * sx,
            10.5 * sy,
          )
          ..cubicTo(
            18.4 * sx,
            6.96 * sy,
            15.54 * sx,
            4.1 * sy,
            12 * sx,
            4.1 * sy,
          )
          ..cubicTo(
            8.46 * sx,
            4.1 * sy,
            5.6 * sx,
            6.96 * sy,
            5.6 * sx,
            10.5 * sy,
          )
          ..cubicTo(5.6 * sx, 13.1 * sy, 7.7 * sx, 16.4 * sy, 12 * sx, 21 * sy);
        canvas.drawPath(pin, paint);
    }
  }

  @override
  bool shouldRepaint(covariant PaymentLinePainter oldDelegate) {
    return oldDelegate.kind != kind;
  }
}

class PaymentAlertPainter extends CustomPainter {
  const PaymentAlertPainter({required this.color, required this.stroke});

  final Color color;
  final double stroke;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke * (size.width / 24)
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
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
  bool shouldRepaint(covariant PaymentAlertPainter oldDelegate) {
    return oldDelegate.color != color || oldDelegate.stroke != stroke;
  }
}

class PaymentCheckPainter extends CustomPainter {
  const PaymentCheckPainter({required this.color, required this.stroke});

  final Color color;
  final double stroke;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke * (size.width / 24)
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    final path = Path()
      ..moveTo(5 * sx, 12.6 * sy)
      ..lineTo(9.6 * sx, 17 * sy)
      ..lineTo(19 * sx, 6.6 * sy);
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant PaymentCheckPainter oldDelegate) {
    return oldDelegate.color != color || oldDelegate.stroke != stroke;
  }
}

class PaymentPlusPainter extends CustomPainter {
  const PaymentPlusPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.ink
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.3 * (size.width / 14)
      ..strokeCap = StrokeCap.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawLine(
      Offset(12 * sx, 5.5 * sy),
      Offset(12 * sx, 18.5 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(5.5 * sx, 12 * sy),
      Offset(18.5 * sx, 12 * sy),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class PaymentClosePainter extends CustomPainter {
  const PaymentClosePainter();

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

class PaymentDashRectPainter extends CustomPainter {
  const PaymentDashRectPainter({
    required this.color,
    required this.radius,
    this.stroke = 1,
    this.topOnly = false,
  });

  final Color color;
  final double radius;
  final double stroke;
  final bool topOnly;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke;
    final rect = RRect.fromLTRBR(
      stroke / 2,
      stroke / 2,
      size.width - stroke / 2,
      size.height - stroke / 2,
      Radius.circular(radius),
    );
    final path = Path()..addRRect(rect);
    for (final metric in path.computeMetrics()) {
      var distance = 0.0;
      const dash = 5.0;
      const gap = 4.0;
      while (distance < metric.length) {
        final next = distance + dash;
        canvas.drawPath(
          metric.extractPath(distance, next.clamp(0, metric.length)),
          paint,
        );
        distance = next + gap;
      }
    }
  }

  @override
  bool shouldRepaint(covariant PaymentDashRectPainter oldDelegate) {
    return oldDelegate.color != color ||
        oldDelegate.radius != radius ||
        oldDelegate.stroke != stroke;
  }
}

Duration paymentFade(BuildContext context) => BlueMotion.of(context, _fade);
Duration paymentReveal(BuildContext context) => BlueMotion.of(context, _reveal);
Duration paymentResultFade(BuildContext context) {
  return BlueMotion.of(context, _resultFade);
}
