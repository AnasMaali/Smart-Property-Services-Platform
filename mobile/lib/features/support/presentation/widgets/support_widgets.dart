import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../../core/input/latin_digits.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../data/support_models.dart';

const supportSendHold = Duration(milliseconds: 1000);
const supportToastHold = Duration(milliseconds: 1200);
const supportEase = Cubic(0.2, 0, 0.2, 1);
const supportFast = Duration(milliseconds: 140);
const supportBase = Duration(milliseconds: 180);
const supportSlow = Duration(milliseconds: 220);

const _goldLine = LinearGradient(
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

double supportGutter(BuildContext context) {
  return MediaQuery.sizeOf(context).width < 359 ? 18 : 24;
}

class SupportBackButton extends StatefulWidget {
  const SupportBackButton({
    super.key,
    required this.onPressed,
    this.enabled = true,
  });

  final VoidCallback onPressed;
  final bool enabled;

  @override
  State<SupportBackButton> createState() => _SupportBackButtonState();
}

class _SupportBackButtonState extends State<SupportBackButton> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(-11, 0),
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: widget.enabled ? (_) => setState(() => _down = true) : null,
        onTapUp: widget.enabled ? (_) => setState(() => _down = false) : null,
        onTapCancel: widget.enabled
            ? () => setState(() => _down = false)
            : null,
        onTap: widget.enabled
            ? () {
                BlueMotion.tap();
                widget.onPressed();
              }
            : null,
        child: AnimatedContainer(
          duration: BlueMotion.of(context, supportFast),
          curve: supportEase,
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: _down ? BlueColors.press : Colors.transparent,
            borderRadius: BorderRadius.circular(22),
          ),
          alignment: Alignment.center,
          child: const CustomPaint(
            size: Size(19, 19),
            painter: _SupportBackPainter(),
          ),
        ),
      ),
    );
  }
}

class _SupportBackPainter extends CustomPainter {
  const _SupportBackPainter();

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

class SupportTitle extends StatelessWidget {
  const SupportTitle({
    super.key,
    required this.title,
    this.subtitle = '',
    this.gold = false,
    this.small = false,
  });

  final String title;
  final String subtitle;
  final bool gold;
  final bool small;

  @override
  Widget build(BuildContext context) {
    final narrow = MediaQuery.sizeOf(context).width < 359;
    final size = small ? 23.0 : (narrow ? 24.0 : 26.0);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Flexible(
              child: Text(
                title,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: size,
                  height: 1.18,
                  fontWeight: FontWeight.w700,
                  letterSpacing: size * (small ? -0.02 : -0.022),
                  color: BlueColors.ink,
                ),
              ),
            ),
            if (gold) ...[
              const SizedBox(width: 10),
              const Padding(
                padding: EdgeInsets.only(bottom: 7),
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: _goldLine,
                    borderRadius: BorderRadius.all(Radius.circular(1)),
                  ),
                  child: SizedBox(width: 15, height: 2),
                ),
              ),
            ],
          ],
        ),
        if (subtitle.isNotEmpty) ...[
          const SizedBox(height: 7),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 300),
            child: Text(
              subtitle,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.45,
                fontWeight: FontWeight.w500,
                color: BlueColors.muted,
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class SupportStatusBadge extends StatelessWidget {
  const SupportStatusBadge({super.key, required this.status});

  final SupportStatus status;

  @override
  Widget build(BuildContext context) {
    final Color fill;
    final Color ink;
    final Color line;
    switch (status) {
      case SupportStatus.open:
        fill = BlueColors.unavailableSurface;
        ink = BlueColors.unavailableInk;
        line = BlueColors.unavailableLine;
      case SupportStatus.inProgress:
        fill = BlueColors.ink;
        ink = BlueColors.white;
        line = BlueColors.ink;
      case SupportStatus.resolved:
        fill = BlueColors.chipSurface;
        ink = BlueColors.body;
        line = BlueColors.border;
    }
    return DecoratedBox(
      decoration: BoxDecoration(
        color: fill,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: line),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(8, 2, 8, 2),
        child: Text(
          switch (status) {
            SupportStatus.open => 'Open',
            SupportStatus.inProgress => 'In progress',
            SupportStatus.resolved => 'Resolved',
          },
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 12,
            height: 1.25,
            fontWeight: FontWeight.w700,
            letterSpacing: 12 * 0.02,
            color: ink,
          ),
        ),
      ),
    );
  }
}

class SupportCreateRow extends StatefulWidget {
  const SupportCreateRow({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  State<SupportCreateRow> createState() => _SupportCreateRowState();
}

class _SupportCreateRowState extends State<SupportCreateRow> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    final gutter = supportGutter(context);
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: (_) => setState(() => _down = true),
      onTapUp: (_) => setState(() => _down = false),
      onTapCancel: () => setState(() => _down = false),
      onTap: () {
        BlueMotion.tap();
        widget.onPressed();
      },
      child: AnimatedContainer(
        duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
        curve: supportEase,
        width: double.infinity,
        padding: EdgeInsets.fromLTRB(gutter, 16, gutter, 16),
        decoration: BoxDecoration(
          color: _down ? BlueColors.selectPress : BlueColors.white,
          border: const Border(
            top: BorderSide(color: BlueColors.navLine),
            bottom: BorderSide(color: BlueColors.navLine),
          ),
        ),
        child: Row(
          children: [
            const DecoratedBox(
              decoration: BoxDecoration(
                color: BlueColors.chipSurface,
                borderRadius: BorderRadius.all(Radius.circular(13)),
                border: Border.fromBorderSide(
                  BorderSide(color: BlueColors.navLine),
                ),
              ),
              child: SizedBox(
                width: 38,
                height: 38,
                child: Center(
                  child: CustomPaint(
                    size: Size(19, 19),
                    painter: _PlusPainter(),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 13),
            const Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Create support request',
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15,
                      height: 1.25,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 15 * -0.008,
                      color: BlueColors.ink,
                    ),
                  ),
                  SizedBox(height: 3),
                  Text(
                    'Usually answered within one working day.',
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 12.5,
                      height: 1.45,
                      fontWeight: FontWeight.w400,
                      color: BlueColors.muted,
                    ),
                  ),
                ],
              ),
            ),
            const BlueGlyphIcon(
              BlueGlyph.chevronRight,
              size: 17,
              color: BlueColors.rowChevron,
              strokeWidth: 2.1,
            ),
          ],
        ),
      ),
    );
  }
}

class _PlusPainter extends CustomPainter {
  const _PlusPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.plusStroke
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.9
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawLine(
      Offset(12 * sx, 5.4 * sy),
      Offset(12 * sx, 18.6 * sy),
      paint,
    );
    canvas.drawLine(
      Offset(5.4 * sx, 12 * sy),
      Offset(18.6 * sx, 12 * sy),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class SupportRequestRow extends StatefulWidget {
  const SupportRequestRow({
    super.key,
    required this.request,
    required this.onPressed,
    this.last = false,
  });

  final SupportRequest request;
  final VoidCallback onPressed;
  final bool last;

  @override
  State<SupportRequestRow> createState() => _SupportRequestRowState();
}

class _SupportRequestRowState extends State<SupportRequestRow> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    final gutter = supportGutter(context);
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: (_) => setState(() => _down = true),
      onTapUp: (_) => setState(() => _down = false),
      onTapCancel: () => setState(() => _down = false),
      onTap: () {
        BlueMotion.tap();
        widget.onPressed();
      },
      child: AnimatedContainer(
        duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
        curve: supportEase,
        width: double.infinity,
        padding: EdgeInsets.fromLTRB(gutter, 15, gutter, 16),
        decoration: BoxDecoration(
          color: _down ? BlueColors.press : Colors.transparent,
          border: Border(
            top: const BorderSide(color: BlueColors.navLine),
            bottom: widget.last
                ? const BorderSide(color: BlueColors.navLine)
                : BorderSide.none,
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SupportStatusBadge(status: widget.request.status),
                  const SizedBox(height: 5),
                  Text(
                    widget.request.subject,
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15,
                      height: 1.3,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 15 * -0.008,
                      color: widget.request.status == SupportStatus.resolved
                          ? BlueColors.body
                          : BlueColors.ink,
                    ),
                  ),
                  const SizedBox(height: 5),
                  Text(
                    widget.request.listMeta,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 12.5,
                      height: 1.45,
                      fontWeight: FontWeight.w400,
                      color: BlueColors.muted,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            const Padding(
              padding: EdgeInsets.only(top: 20),
              child: BlueGlyphIcon(
                BlueGlyph.chevronRight,
                size: 17,
                color: BlueColors.rowChevron,
                strokeWidth: 2.1,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class SupportEyebrow extends StatelessWidget {
  const SupportEyebrow(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text.toUpperCase(),
      style: const TextStyle(
        fontFamily: BlueFonts.jakarta,
        fontSize: 12,
        height: 1.2,
        fontWeight: FontWeight.w700,
        letterSpacing: 12 * 0.06,
        color: BlueColors.placeholder,
      ),
    );
  }
}

class SupportHelper extends StatelessWidget {
  const SupportHelper(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: const TextStyle(
        fontFamily: BlueFonts.jakarta,
        fontSize: 12.5,
        height: 1.45,
        fontWeight: FontWeight.w400,
        color: BlueColors.muted,
      ),
    );
  }
}

class SupportFieldLabel extends StatelessWidget {
  const SupportFieldLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: const TextStyle(
        fontFamily: BlueFonts.jakarta,
        fontSize: 13,
        height: 1.2,
        fontWeight: FontWeight.w600,
        letterSpacing: 13 * 0.005,
        color: BlueColors.muted,
      ),
    );
  }
}

class SupportOutlinedField extends StatefulWidget {
  const SupportOutlinedField({
    super.key,
    required this.controller,
    required this.focusNode,
    required this.hint,
    required this.onChanged,
    this.minLines,
    this.maxLines = 1,
    this.enabled = true,
    this.textInputAction,
    this.keyboardType,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final String hint;
  final ValueChanged<String> onChanged;
  final int? minLines;
  final int maxLines;
  final bool enabled;
  final TextInputAction? textInputAction;
  final TextInputType? keyboardType;

  @override
  State<SupportOutlinedField> createState() => _SupportOutlinedFieldState();
}

class _SupportOutlinedFieldState extends State<SupportOutlinedField> {
  @override
  void initState() {
    super.initState();
    widget.focusNode.addListener(_onFocus);
  }

  @override
  void didUpdateWidget(covariant SupportOutlinedField oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.focusNode != widget.focusNode) {
      oldWidget.focusNode.removeListener(_onFocus);
      widget.focusNode.addListener(_onFocus);
    }
  }

  @override
  void dispose() {
    widget.focusNode.removeListener(_onFocus);
    super.dispose();
  }

  void _onFocus() => setState(() {});

  bool get _multiline => widget.maxLines != 1;

  @override
  Widget build(BuildContext context) {
    final focused = widget.focusNode.hasFocus;
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Container(
          height: _multiline ? null : BlueDimens.fieldHeight,
          constraints: _multiline ? const BoxConstraints(minHeight: 168) : null,
          padding: _multiline
              ? const EdgeInsets.fromLTRB(17, 15, 17, 15)
              : const EdgeInsets.symmetric(horizontal: 17),
          alignment: _multiline ? Alignment.topLeft : Alignment.centerLeft,
          decoration: BoxDecoration(
            color: BlueColors.white,
            borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
            border: Border.all(color: BlueColors.border),
          ),
          child: TextField(
            controller: widget.controller,
            focusNode: widget.focusNode,
            enabled: widget.enabled,
            minLines: widget.minLines,
            maxLines: widget.maxLines,
            keyboardType: _multiline
                ? TextInputType.multiline
                : widget.keyboardType,
            textInputAction: widget.textInputAction,
            textCapitalization: TextCapitalization.sentences,
            textAlignVertical: _multiline
                ? TextAlignVertical.top
                : TextAlignVertical.center,
            inputFormatters: const [LatinDigits.formatter],
            cursorColor: BlueColors.ink,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 16.5,
              height: _multiline ? 1.5 : 1.35,
              fontWeight: FontWeight.w500,
              color: BlueColors.ink,
            ),
            decoration: InputDecoration(
              isCollapsed: true,
              border: InputBorder.none,
              hintText: widget.hint,
              hintStyle: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 16.5,
                height: _multiline ? 1.5 : 1.35,
                fontWeight: FontWeight.w500,
                color: BlueColors.placeholder,
              ),
            ),
            onChanged: widget.onChanged,
          ),
        ),
        Positioned(
          top: -1,
          left: -1,
          right: -1,
          bottom: -1,
          child: IgnorePointer(
            child: AnimatedContainer(
              duration: BlueMotion.of(context, supportBase),
              curve: supportEase,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(BlueDimens.fieldRadius + 1),
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

class SupportSubmitButton extends StatelessWidget {
  const SupportSubmitButton({
    super.key,
    required this.enabled,
    required this.busy,
    required this.onPressed,
  });

  final bool enabled;
  final bool busy;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final awake = enabled || busy;
    return AnimatedOpacity(
      duration: BlueMotion.of(context, supportBase),
      curve: supportEase,
      opacity: awake ? 1 : 0.4,
      child: BluePressable(
        enabled: enabled && !busy,
        onPressed: enabled && !busy ? onPressed : null,
        scale: 0.985,
        duration: supportFast,
        child: AnimatedContainer(
          duration: BlueMotion.of(context, supportBase),
          curve: supportEase,
          constraints: const BoxConstraints(minHeight: BlueDimens.fieldHeight),
          decoration: BoxDecoration(
            color: busy ? BlueColors.ctaBusy : BlueColors.ink,
            borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
            boxShadow: awake && !busy
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
                        child: _SupportSpinner(),
                      )
                    : const SizedBox.shrink(key: ValueKey('idle')),
              ),
              AnimatedSwitcher(
                duration: BlueMotion.tick,
                child: Text(
                  busy ? 'Sending…' : 'Submit request',
                  key: ValueKey(busy ? 'sending' : 'submit'),
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

class _SupportSpinner extends StatefulWidget {
  const _SupportSpinner();

  @override
  State<_SupportSpinner> createState() => _SupportSpinnerState();
}

class _SupportSpinnerState extends State<_SupportSpinner>
    with SingleTickerProviderStateMixin {
  late final AnimationController _spin;

  @override
  void initState() {
    super.initState();
    _spin = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 700),
    )..repeat();
  }

  @override
  void dispose() {
    _spin.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return RotationTransition(
      turns: _spin,
      child: CustomPaint(size: const Size(17, 17), painter: _SpinnerPainter()),
    );
  }
}

class _SpinnerPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final rect = Rect.fromLTWH(1, 1, size.width - 2, size.height - 2);
    final track = Paint()
      ..color = const Color(0x52FFFFFF)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2;
    final arc = Paint()
      ..color = BlueColors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round;
    canvas.drawCircle(size.center(Offset.zero), size.width / 2 - 1, track);
    canvas.drawArc(rect, -1.2, 1.6, false, arc);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class SupportToast extends StatelessWidget {
  const SupportToast({super.key, required this.visible, required this.label});

  final bool visible;
  final String label;

  @override
  Widget build(BuildContext context) {
    final gutter = supportGutter(context);
    final bottom = MediaQuery.paddingOf(context).bottom;
    return IgnorePointer(
      child: AnimatedSlide(
        duration: BlueMotion.of(context, supportSlow),
        curve: visible ? supportEase : BlueMotion.exitCurve,
        offset: visible ? Offset.zero : const Offset(0, 0.18),
        child: AnimatedOpacity(
          duration: BlueMotion.of(context, supportSlow),
          curve: supportEase,
          opacity: visible ? 1 : 0,
          child: Padding(
            padding: EdgeInsets.fromLTRB(gutter, 0, gutter, 30 + bottom),
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: BlueColors.ink,
                borderRadius: BorderRadius.circular(18),
                boxShadow: const [
                  BoxShadow(
                    color: BlueColors.toastLift,
                    offset: Offset(0, 16),
                    blurRadius: 34,
                    spreadRadius: -22,
                  ),
                ],
              ),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 13, 16, 13),
                child: Row(
                  children: [
                    const CustomPaint(
                      size: Size(16, 16),
                      painter: _ToastCheckPainter(),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        label,
                        style: const TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 14,
                          height: 1.25,
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

class SupportMessageBubble extends StatelessWidget {
  const SupportMessageBubble({super.key, required this.message});

  final SupportMessage message;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: message.fromSupport ? BlueColors.chipSurface : BlueColors.white,
        borderRadius: BorderRadius.circular(18),
        border: message.fromSupport
            ? null
            : Border.all(color: BlueColors.border),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: [
                Expanded(
                  child: Text(
                    message.author,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 12.5,
                      height: 1.2,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 12.5 * 0.005,
                      color: BlueColors.ink,
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Text(
                  message.time,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 11.5,
                    height: 1.2,
                    fontWeight: FontWeight.w500,
                    fontFeatures: [FontFeature.tabularFigures()],
                    color: BlueColors.placeholder,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              message.text,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.55,
                fontWeight: FontWeight.w400,
                color: BlueColors.body,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class SupportReplyBar extends StatelessWidget {
  const SupportReplyBar({
    super.key,
    required this.controller,
    required this.focusNode,
    required this.enabled,
    required this.onChanged,
    required this.onSend,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final bool enabled;
  final ValueChanged<String> onChanged;
  final VoidCallback onSend;

  @override
  Widget build(BuildContext context) {
    final gutter = supportGutter(context);
    final safe = MediaQuery.paddingOf(context).bottom;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: BlueColors.white,
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(gutter, 12, gutter, 22 + safe),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Expanded(
              child: ConstrainedBox(
                constraints: const BoxConstraints(
                  minHeight: 52,
                  maxHeight: 120,
                ),
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    color: BlueColors.canvas,
                    borderRadius: BorderRadius.circular(18),
                    border: Border.all(color: BlueColors.border),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
                    child: TextField(
                      controller: controller,
                      focusNode: focusNode,
                      minLines: 1,
                      maxLines: 5,
                      textCapitalization: TextCapitalization.sentences,
                      textInputAction: TextInputAction.newline,
                      inputFormatters: const [LatinDigits.formatter],
                      cursorColor: BlueColors.ink,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 15.5,
                        height: 1.4,
                        fontWeight: FontWeight.w500,
                        color: BlueColors.ink,
                      ),
                      decoration: const InputDecoration(
                        isCollapsed: true,
                        border: InputBorder.none,
                        hintText: 'Write a reply…',
                        hintStyle: TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 15.5,
                          height: 1.4,
                          fontWeight: FontWeight.w500,
                          color: BlueColors.placeholder,
                        ),
                      ),
                      onChanged: onChanged,
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 10),
            Tooltip(
              message: 'Send reply',
              child: _SendButton(enabled: enabled, onPressed: onSend),
            ),
          ],
        ),
      ),
    );
  }
}

class _SendButton extends StatelessWidget {
  const _SendButton({required this.enabled, required this.onPressed});

  final bool enabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: enabled ? 1 : 0.35,
      child: BluePressable(
        enabled: enabled,
        onPressed: enabled ? onPressed : null,
        scale: 0.96,
        duration: supportFast,
        child: const SizedBox(
          key: Key('support-send'),
          width: 52,
          height: 52,
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: BlueColors.ink,
              borderRadius: BorderRadius.all(Radius.circular(18)),
            ),
            child: Center(
              child: CustomPaint(size: Size(19, 19), painter: _SendPainter()),
            ),
          ),
        ),
      ),
    );
  }
}

class _SendPainter extends CustomPainter {
  const _SendPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.1
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawLine(
      Offset(4.5 * sx, 12 * sy),
      Offset(18.5 * sx, 12 * sy),
      paint,
    );
    final path = Path()
      ..moveTo(12.5 * sx, 5.5 * sy)
      ..lineTo(19 * sx, 12 * sy)
      ..lineTo(12.5 * sx, 18.5 * sy);
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class SupportHairline extends StatelessWidget {
  const SupportHairline({super.key});

  @override
  Widget build(BuildContext context) {
    return const ColoredBox(
      color: BlueColors.border,
      child: SizedBox(height: 1, width: double.infinity),
    );
  }
}

class SupportRequestMeta extends StatelessWidget {
  const SupportRequestMeta({
    super.key,
    required this.number,
    required this.openedLabel,
  });

  final String number;
  final String openedLabel;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 7),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 300),
        child: Row(
          children: [
            Text(
              number,
              style: const TextStyle(
                fontFamily: BlueFonts.mono,
                fontSize: 13.5,
                height: 1.45,
                fontWeight: FontWeight.w500,
                fontFeatures: [FontFeature.tabularFigures()],
                color: BlueColors.muted,
              ),
            ),
            Expanded(
              child: Text(
                ' · $openedLabel',
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 13.5,
                  height: 1.45,
                  fontWeight: FontWeight.w500,
                  color: BlueColors.muted,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
