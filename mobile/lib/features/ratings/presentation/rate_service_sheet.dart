import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../app/theme/blue_theme.dart';
import '../../../core/input/latin_digits.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../data/rating_models.dart';
import 'widgets/rating_widgets.dart';

const rateSheetScrim = Color(0x610C042C);
const rateSubmitHold = Duration(milliseconds: 900);

class RateServiceResult {
  const RateServiceResult({
    required this.stars,
    required this.word,
    required this.comment,
  });

  final int stars;
  final String word;
  final String comment;
}

Future<RateServiceResult?> showRateServiceSheet({
  required BuildContext context,
  required String serviceName,
  required String dateLabel,
}) {
  return showModalBottomSheet<RateServiceResult>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    barrierColor: rateSheetScrim,
    elevation: 0,
    useSafeArea: false,
    sheetAnimationStyle: AnimationStyle(
      duration: BlueMotion.sheet,
      curve: BlueMotion.curve,
      reverseDuration: BlueMotion.sheetOut,
      reverseCurve: BlueMotion.exitCurve,
    ),
    builder: (context) {
      return RateServiceSheet(serviceName: serviceName, dateLabel: dateLabel);
    },
  );
}

class RateServiceSheet extends StatefulWidget {
  const RateServiceSheet({
    super.key,
    required this.serviceName,
    required this.dateLabel,
  });

  final String serviceName;
  final String dateLabel;

  @override
  State<RateServiceSheet> createState() => _RateServiceSheetState();
}

class _RateServiceSheetState extends State<RateServiceSheet> {
  final _note = TextEditingController();
  final _noteFocus = FocusNode();
  int _stars = 0;
  bool _busy = false;

  @override
  void dispose() {
    _note.dispose();
    _noteFocus.dispose();
    super.dispose();
  }

  String get _subtitle {
    if (widget.dateLabel.isEmpty) return widget.serviceName;
    if (widget.serviceName.isEmpty) return widget.dateLabel;
    return '${widget.serviceName} · ${widget.dateLabel}';
  }

  void _setStars(int value) {
    if (_busy) return;
    setState(() => _stars = value);
  }

  void _nudgeStars(int delta) {
    if (_busy) return;
    final next = (_stars + delta).clamp(1, 5);
    if (next == _stars) return;
    BlueMotion.tap();
    _setStars(next);
  }

  Future<void> _submit() async {
    if (_stars < 1 || _busy) return;
    setState(() => _busy = true);
    _noteFocus.unfocus();
    await Future<void>.delayed(rateSubmitHold);
    if (!mounted) return;
    Navigator.of(context).pop(
      RateServiceResult(
        stars: _stars,
        word: rateServiceWord(_stars),
        comment: _note.text.trim(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final gutter = ratingGutter(context);
    final safe = MediaQuery.paddingOf(context).bottom;
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    final word = rateServiceWord(_stars);
    return PopScope(
      canPop: !_busy,
      child: Align(
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
              padding: EdgeInsets.fromLTRB(
                gutter,
                14,
                gutter,
                30 + (inset > 0 ? inset : safe),
              ),
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Center(
                      child: DecoratedBox(
                        decoration: BoxDecoration(
                          color: BlueColors.border,
                          borderRadius: BorderRadius.all(Radius.circular(2)),
                        ),
                        child: SizedBox(width: 38, height: 4),
                      ),
                    ),
                    const SizedBox(height: 16),
                    const Text(
                      'How was your service?',
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 19,
                        height: 1.3,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 19 * -0.018,
                        color: BlueColors.ink,
                      ),
                    ),
                    if (_subtitle.isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Text(
                        _subtitle,
                        style: const TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 14,
                          height: 1.5,
                          fontWeight: FontWeight.w400,
                          color: BlueColors.muted,
                        ),
                      ),
                    ],
                    const SizedBox(height: 18),
                    CallbackShortcuts(
                      bindings: {
                        const SingleActivator(
                          LogicalKeyboardKey.arrowRight,
                        ): () =>
                            _nudgeStars(1),
                        const SingleActivator(LogicalKeyboardKey.arrowUp): () =>
                            _nudgeStars(1),
                        const SingleActivator(
                          LogicalKeyboardKey.arrowLeft,
                        ): () =>
                            _nudgeStars(-1),
                        const SingleActivator(
                          LogicalKeyboardKey.arrowDown,
                        ): () =>
                            _nudgeStars(-1),
                      },
                      child: Focus(
                        autofocus: true,
                        canRequestFocus: !_busy,
                        child: Semantics(
                          container: true,
                          label: 'How was your service?',
                          child: Row(
                            children: [
                              for (var i = 1; i <= 5; i++) ...[
                                if (i > 1) const SizedBox(width: 6),
                                RateStarButton(
                                  value: i,
                                  on: i <= _stars,
                                  enabled: !_busy,
                                  onPressed: () => _setStars(i),
                                ),
                              ],
                            ],
                          ),
                        ),
                      ),
                    ),
                    _RatingLabel(stars: _stars, word: word),
                    const SizedBox(height: 22),
                    const _LabelRow(),
                    const SizedBox(height: 8),
                    _CommentField(
                      controller: _note,
                      focusNode: _noteFocus,
                      enabled: !_busy,
                      onChanged: (_) => setState(() {}),
                    ),
                    const SizedBox(height: 22),
                    _SubmitButton(
                      enabled: _stars > 0,
                      busy: _busy,
                      onPressed: _submit,
                    ),
                    if (_stars == 0)
                      const Padding(
                        padding: EdgeInsets.only(top: 8),
                        child: Text(
                          'Choose a star rating to submit.',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 12.5,
                            height: 1.45,
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
    );
  }
}

class _RatingLabel extends StatelessWidget {
  const _RatingLabel({required this.stars, required this.word});

  final int stars;
  final String word;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 14),
      child: Semantics(
        liveRegion: true,
        container: true,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 22),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                stars == 0 ? 'Tap a star to rate' : '$stars / 5',
                style: const TextStyle(
                  fontFamily: BlueFonts.mono,
                  fontSize: 12,
                  height: 1.2,
                  fontWeight: FontWeight.w400,
                  letterSpacing: 12 * 0.08,
                  color: BlueColors.placeholder,
                ),
              ),
              if (word.isNotEmpty) ...[
                const SizedBox(width: 8),
                Text(
                  word,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 15,
                    height: 1.2,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 15 * -0.008,
                    color: BlueColors.ink,
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

class _LabelRow extends StatelessWidget {
  const _LabelRow();

  @override
  Widget build(BuildContext context) {
    return const Row(
      crossAxisAlignment: CrossAxisAlignment.baseline,
      textBaseline: TextBaseline.alphabetic,
      children: [
        Expanded(
          child: Text(
            'Tell us more',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13,
              height: 1.2,
              fontWeight: FontWeight.w600,
              letterSpacing: 13 * 0.005,
              color: BlueColors.muted,
            ),
          ),
        ),
        DecoratedBox(
          decoration: BoxDecoration(
            color: BlueColors.white,
            borderRadius: BorderRadius.all(Radius.circular(5)),
            border: Border.fromBorderSide(
              BorderSide(color: BlueColors.badgeBorder),
            ),
          ),
          child: Padding(
            padding: EdgeInsets.fromLTRB(6, 2, 6, 2),
            child: Text(
              'OPTIONAL',
              style: TextStyle(
                fontFamily: BlueFonts.mono,
                fontSize: 10,
                height: 1.2,
                fontWeight: FontWeight.w400,
                letterSpacing: 10 * 0.1,
                color: BlueColors.muted,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _CommentField extends StatefulWidget {
  const _CommentField({
    required this.controller,
    required this.focusNode,
    required this.enabled,
    required this.onChanged,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final bool enabled;
  final ValueChanged<String> onChanged;

  @override
  State<_CommentField> createState() => _CommentFieldState();
}

class _CommentFieldState extends State<_CommentField> {
  @override
  void initState() {
    super.initState();
    widget.focusNode.addListener(_onFocus);
  }

  @override
  void didUpdateWidget(covariant _CommentField oldWidget) {
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

  @override
  Widget build(BuildContext context) {
    final focused = widget.focusNode.hasFocus;
    return Stack(
      clipBehavior: Clip.none,
      children: [
        ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 104),
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: BlueColors.white,
              borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
              border: Border.all(color: BlueColors.border),
            ),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(17, 15, 17, 15),
              child: TextField(
                key: const Key('rate-note'),
                controller: widget.controller,
                focusNode: widget.focusNode,
                enabled: widget.enabled,
                minLines: 3,
                maxLines: 6,
                keyboardType: TextInputType.multiline,
                textCapitalization: TextCapitalization.sentences,
                textAlignVertical: TextAlignVertical.top,
                inputFormatters: const [LatinDigits.formatter],
                cursorColor: BlueColors.ink,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 16.5,
                  height: 1.5,
                  fontWeight: FontWeight.w500,
                  color: BlueColors.ink,
                ),
                decoration: const InputDecoration(
                  isCollapsed: true,
                  border: InputBorder.none,
                  hintText: 'Anything the team should know.',
                  hintStyle: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 16.5,
                    height: 1.5,
                    fontWeight: FontWeight.w500,
                    color: BlueColors.placeholder,
                  ),
                ),
                onChanged: widget.onChanged,
              ),
            ),
          ),
        ),
        Positioned(
          top: -1,
          left: -1,
          right: -1,
          bottom: -1,
          child: IgnorePointer(
            child: AnimatedContainer(
              duration: BlueMotion.of(context, ratingFast),
              curve: ratingEase,
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

class _SubmitButton extends StatelessWidget {
  const _SubmitButton({
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
      duration: BlueMotion.of(context, ratingFast),
      curve: ratingEase,
      opacity: awake ? 1 : 0.4,
      child: BluePressable(
        enabled: enabled && !busy,
        onPressed: enabled && !busy ? onPressed : null,
        scale: 0.985,
        duration: ratingFast,
        child: AnimatedContainer(
          duration: BlueMotion.of(context, ratingFast),
          curve: ratingEase,
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
                        child: _Spinner(),
                      )
                    : const SizedBox.shrink(key: ValueKey('idle')),
              ),
              AnimatedSwitcher(
                duration: BlueMotion.tick,
                child: Text(
                  busy ? 'Submitting…' : 'Submit rating',
                  key: ValueKey(busy ? 'busy' : 'idle'),
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

class _Spinner extends StatefulWidget {
  const _Spinner();

  @override
  State<_Spinner> createState() => _SpinnerState();
}

class _SpinnerState extends State<_Spinner>
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
