import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../data/rating_models.dart';

const ratingEase = Cubic(0.2, 0, 0.2, 1);
const ratingFast = Duration(milliseconds: 140);

const _goldFill = LinearGradient(
  begin: Alignment.topLeft,
  end: Alignment.bottomRight,
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
  stops: [0, 0.38, 0.70, 1],
);

const _starEdge = Color(0xFFE0A800);
const _starOff = Color(0xFFC0C5D8);

double ratingGutter(BuildContext context) {
  return MediaQuery.sizeOf(context).width < 359 ? 18 : 24;
}

class RatingBackButton extends StatefulWidget {
  const RatingBackButton({
    super.key,
    required this.onPressed,
    this.label = 'Back to booking',
  });

  final VoidCallback onPressed;
  final String label;

  @override
  State<RatingBackButton> createState() => _RatingBackButtonState();
}

class _RatingBackButtonState extends State<RatingBackButton> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(-11, 0),
      child: Semantics(
        button: true,
        label: widget.label,
        excludeSemantics: true,
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
            duration: BlueMotion.of(context, ratingFast),
            curve: ratingEase,
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: _down ? BlueColors.press : Colors.transparent,
              borderRadius: BorderRadius.circular(22),
            ),
            alignment: Alignment.center,
            child: const CustomPaint(
              size: Size(19, 19),
              painter: _RatingBackPainter(),
            ),
          ),
        ),
      ),
    );
  }
}

class _RatingBackPainter extends CustomPainter {
  const _RatingBackPainter();

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

class RatingStatusBadge extends StatelessWidget {
  const RatingStatusBadge({super.key, required this.label, this.live = false});

  final String label;
  final bool live;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: live ? BlueColors.ink : BlueColors.chipSurface,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: live ? BlueColors.ink : BlueColors.border),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(8, 2, 8, 2),
        child: Text(
          label,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 12,
            height: 1.25,
            fontWeight: FontWeight.w700,
            letterSpacing: 12 * 0.02,
            color: live ? BlueColors.white : BlueColors.body,
          ),
        ),
      ),
    );
  }
}

const _goldRule = LinearGradient(
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

class RatingGoldRule extends StatelessWidget {
  const RatingGoldRule({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.only(bottom: 7),
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: _goldRule,
          borderRadius: BorderRadius.all(Radius.circular(1)),
        ),
        child: SizedBox(
          width: BlueDimens.checkoutGoldWidth,
          height: BlueDimens.checkoutGoldHeight,
        ),
      ),
    );
  }
}

class RatingGoldTitle extends StatelessWidget {
  const RatingGoldTitle(this.title, {super.key});

  final String title;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Flexible(child: RatingTitle(title)),
        const SizedBox(width: 10),
        const RatingGoldRule(),
      ],
    );
  }
}

class RatingTitle extends StatelessWidget {
  const RatingTitle(this.title, {super.key});

  final String title;

  @override
  Widget build(BuildContext context) {
    return Text(
      title,
      style: const TextStyle(
        fontFamily: BlueFonts.jakarta,
        fontSize: 23,
        height: 1.18,
        fontWeight: FontWeight.w700,
        letterSpacing: 23 * -0.02,
        color: BlueColors.ink,
      ),
    );
  }
}

class RatingMeta extends StatelessWidget {
  const RatingMeta({super.key, required this.reference, required this.rest});

  final String reference;
  final String rest;

  @override
  Widget build(BuildContext context) {
    if (reference.isEmpty && rest.isEmpty) return const SizedBox.shrink();
    final trailing = reference.isEmpty || rest.isEmpty ? rest : ' · $rest';
    return Padding(
      padding: const EdgeInsets.only(top: 7),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 300),
        child: Text.rich(
          TextSpan(
            children: [
              if (reference.isNotEmpty)
                TextSpan(
                  text: reference,
                  style: const TextStyle(
                    fontFamily: BlueFonts.mono,
                    fontSize: 13.5,
                    height: 1.45,
                    fontWeight: FontWeight.w500,
                    fontFeatures: [FontFeature.tabularFigures()],
                    color: BlueColors.muted,
                  ),
                ),
              if (trailing.isNotEmpty)
                TextSpan(
                  text: trailing,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 13.5,
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

class RatingEyebrow extends StatelessWidget {
  const RatingEyebrow(this.text, {super.key});

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

class RatingHelper extends StatelessWidget {
  const RatingHelper(this.text, {super.key});

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

class RatingSubmittedBlock extends StatelessWidget {
  const RatingSubmittedBlock({super.key, required this.view});

  final AlreadyRatedView view;

  @override
  Widget build(BuildContext context) {
    final gutter = ratingGutter(context);
    return DecoratedBox(
      decoration: const BoxDecoration(
        border: Border(
          top: BorderSide(color: BlueColors.navLine),
          bottom: BorderSide(color: BlueColors.navLine),
        ),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(gutter, 16, gutter, 17),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            RatedStarsRow(stars: view.stars, word: view.word),
            const SizedBox(height: 7),
            Text(
              view.submittedLabel,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.45,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
            if (view.comment.isNotEmpty) ...[
              const SizedBox(height: 9),
              DecoratedBox(
                decoration: const BoxDecoration(
                  color: BlueColors.chipSurface,
                  borderRadius: BorderRadius.all(Radius.circular(16)),
                ),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                  child: Text(
                    view.comment,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 13,
                      height: 1.5,
                      fontWeight: FontWeight.w400,
                      color: BlueColors.body,
                    ),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class RatedStarsRow extends StatelessWidget {
  const RatedStarsRow({super.key, required this.stars, required this.word});

  final int stars;
  final String word;

  @override
  Widget build(BuildContext context) {
    final count = stars.clamp(0, 5);
    return Row(
      children: [
        Semantics(
          image: true,
          label: 'Rated $count out of 5',
          excludeSemantics: true,
          child: Row(
            children: [
              for (var i = 0; i < 5; i++) ...[
                if (i > 0) const SizedBox(width: 3),
                CustomPaint(
                  size: const Size(14, 14),
                  painter: _RatedStarPainter(on: i < count),
                ),
              ],
            ],
          ),
        ),
        if (word.isNotEmpty) ...[
          const SizedBox(width: 9),
          Text(
            word,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              height: 1.2,
              fontWeight: FontWeight.w600,
              color: BlueColors.placeholder,
            ),
          ),
        ],
      ],
    );
  }
}

class _RatedStarPainter extends CustomPainter {
  const _RatedStarPainter({required this.on});

  final bool on;

  @override
  void paint(Canvas canvas, Size size) {
    final path = ratingStarPath(size);
    if (on) {
      final fill = Paint()
        ..shader = _goldFill.createShader(Offset.zero & size)
        ..style = PaintingStyle.fill;
      canvas.drawPath(path, fill);
      final stroke = Paint()
        ..color = _starEdge
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.4
        ..strokeJoin = StrokeJoin.round;
      canvas.drawPath(path, stroke);
      return;
    }
    final off = Paint()
      ..color = _starOff
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.4
      ..strokeJoin = StrokeJoin.round;
    canvas.drawPath(path, off);
  }

  @override
  bool shouldRepaint(covariant _RatedStarPainter oldDelegate) {
    return oldDelegate.on != on;
  }
}

class RateServiceChip extends StatefulWidget {
  const RateServiceChip({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  State<RateServiceChip> createState() => _RateServiceChipState();
}

class _RateServiceChipState extends State<RateServiceChip> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: 'Rate service',
      excludeSemantics: true,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: (_) => setState(() => _down = true),
        onTapUp: (_) => setState(() => _down = false),
        onTapCancel: () => setState(() => _down = false),
        onTap: () {
          BlueMotion.tap();
          widget.onPressed();
        },
        child: AnimatedScale(
          scale: _down ? 0.985 : 1,
          duration: BlueMotion.of(context, ratingFast),
          curve: ratingEase,
          child: AnimatedContainer(
            duration: BlueMotion.of(context, ratingFast),
            curve: ratingEase,
            constraints: const BoxConstraints(minHeight: 44),
            padding: const EdgeInsets.symmetric(horizontal: 16),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: _down ? BlueColors.press : BlueColors.white,
              borderRadius: BorderRadius.circular(15),
              border: Border.all(color: BlueColors.border),
            ),
            child: const Text(
              'Rate service',
              maxLines: 1,
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.2,
                fontWeight: FontWeight.w700,
                letterSpacing: 14 * 0.005,
                color: BlueColors.ink,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class RatingQuote extends StatelessWidget {
  const RatingQuote(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: BlueColors.chipSurface,
        borderRadius: BorderRadius.all(Radius.circular(16)),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
        child: Text(
          text,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13,
            height: 1.5,
            fontWeight: FontWeight.w400,
            color: BlueColors.body,
          ),
        ),
      ),
    );
  }
}

class RatingServiceBlock extends StatelessWidget {
  const RatingServiceBlock({
    super.key,
    required this.view,
    this.last = false,
    this.onRate,
  });

  final BookingServiceRatingView view;
  final bool last;
  final VoidCallback? onRate;

  @override
  Widget build(BuildContext context) {
    final gutter = ratingGutter(context);
    return Semantics(
      container: true,
      label: view.name,
      child: DecoratedBox(
        decoration: BoxDecoration(
          border: Border(
            top: const BorderSide(color: BlueColors.navLine),
            bottom: last
                ? const BorderSide(color: BlueColors.navLine)
                : BorderSide.none,
          ),
        ),
        child: Padding(
          padding: EdgeInsets.fromLTRB(gutter, 16, gutter, 17),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                view.name,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 15,
                  height: 1.3,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 15 * -0.008,
                  color: BlueColors.ink,
                ),
              ),
              if (view.meta.isNotEmpty) ...[
                const SizedBox(height: 7),
                Text(
                  view.meta,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12.5,
                    height: 1.45,
                    fontWeight: FontWeight.w400,
                    color: BlueColors.muted,
                  ),
                ),
              ],
              if (view.isRated) ...[
                const SizedBox(height: 7),
                RatedStarsRow(stars: view.stars, word: view.word),
                if (view.comment.isNotEmpty) ...[
                  const SizedBox(height: 9),
                  RatingQuote(view.comment),
                ],
              ],
              if (view.isRateable) ...[
                const SizedBox(height: 10),
                RateServiceChip(onPressed: onRate ?? () {}),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class RateStarButton extends StatefulWidget {
  const RateStarButton({
    super.key,
    required this.value,
    required this.on,
    required this.onPressed,
    this.enabled = true,
  });

  final int value;
  final bool on;
  final VoidCallback onPressed;
  final bool enabled;

  @override
  State<RateStarButton> createState() => _RateStarButtonState();
}

class _RateStarButtonState extends State<RateStarButton> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      inMutuallyExclusiveGroup: true,
      checked: widget.on,
      enabled: widget.enabled,
      label: '${widget.value} out of 5',
      excludeSemantics: true,
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
        child: AnimatedScale(
          scale: _down ? 0.94 : 1,
          duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
          curve: ratingEase,
          child: SizedBox(
            width: 52,
            height: 52,
            child: Center(
              child: CustomPaint(
                size: const Size(32, 32),
                painter: _SheetStarPainter(on: widget.on),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _SheetStarPainter extends CustomPainter {
  const _SheetStarPainter({required this.on});

  final bool on;

  @override
  void paint(Canvas canvas, Size size) {
    final path = ratingStarPath(size);
    // SVG stroke-width is in viewBox units (24), drawn at 32px.
    final strokeW = 1.6 * size.width / 24;
    if (on) {
      final fill = Paint()
        ..shader = _goldFill.createShader(Offset.zero & size)
        ..style = PaintingStyle.fill;
      canvas.drawPath(path, fill);
      final stroke = Paint()
        ..color = _starEdge
        ..style = PaintingStyle.stroke
        ..strokeWidth = strokeW
        ..strokeJoin = StrokeJoin.round;
      canvas.drawPath(path, stroke);
      return;
    }
    final off = Paint()
      ..color = _starOff
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeW
      ..strokeJoin = StrokeJoin.round;
    canvas.drawPath(path, off);
  }

  @override
  bool shouldRepaint(covariant _SheetStarPainter oldDelegate) {
    return oldDelegate.on != on;
  }
}

Path ratingStarPath(Size size) {
  final sx = size.width / 24;
  final sy = size.height / 24;
  return Path()
    ..moveTo(12 * sx, 3.6 * sy)
    ..lineTo(14.63 * sx, 9.12 * sy)
    ..lineTo(20.65 * sx, 9.96 * sy)
    ..lineTo(16.25 * sx, 14.16 * sy)
    ..lineTo(17.33 * sx, 20.16 * sy)
    ..lineTo(12 * sx, 17.26 * sy)
    ..lineTo(6.67 * sx, 20.16 * sy)
    ..lineTo(7.75 * sx, 14.16 * sy)
    ..lineTo(3.35 * sx, 9.96 * sy)
    ..lineTo(9.37 * sx, 9.12 * sy)
    ..close();
}
