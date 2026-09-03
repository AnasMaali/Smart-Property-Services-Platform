import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../../core/input/latin_digits.dart';
import '../../../auth/presentation/widgets/blue_chevron.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../auth/presentation/widgets/blue_sheet.dart';
import '../../../auth/presentation/widgets/error_hint.dart';

const locationOtherReveal = Duration(milliseconds: 220);
const locationAreaFade = Duration(milliseconds: 200);

class LocationFieldLabel extends StatelessWidget {
  const LocationFieldLabel({
    super.key,
    required this.label,
    required this.required,
    this.error = false,
    this.dimmed = false,
  });

  final String label;
  final bool required;
  final bool error;
  final bool dimmed;

  @override
  Widget build(BuildContext context) {
    final flagColor = error
        ? BlueColors.error
        : (dimmed ? BlueColors.ctaDisabledText : BlueColors.placeholder);
    final labelColor = error
        ? BlueColors.error
        : (dimmed ? BlueColors.ctaDisabledText : BlueColors.ink);
    return Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13.5,
              height: 1.3,
              fontWeight: required ? FontWeight.w700 : FontWeight.w600,
              letterSpacing: 13.5 * -0.01,
              color: labelColor,
            ),
          ),
        ),
        Text(
          required ? 'Required' : 'Optional',
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 11.5,
            height: 1.3,
            fontWeight: FontWeight.w500,
            color: flagColor,
          ),
        ),
      ],
    );
  }
}

class LocationGroupHead extends StatelessWidget {
  const LocationGroupHead({super.key, required this.title, this.subtitle});

  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 15,
            height: 1.3,
            fontWeight: FontWeight.w700,
            letterSpacing: 15 * -0.01,
            color: BlueColors.ink,
          ),
        ),
        if (subtitle != null) ...[
          const SizedBox(height: 5),
          Text(
            subtitle!,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13,
              height: 1.45,
              fontWeight: FontWeight.w500,
              color: BlueColors.muted,
            ),
          ),
        ],
      ],
    );
  }
}

class LocationTypeChip extends StatelessWidget {
  const LocationTypeChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onPressed,
    this.error = false,
  });

  final String label;
  final bool selected;
  final bool error;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.97,
      duration: const Duration(milliseconds: 140),
      child: AnimatedContainer(
        duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
        curve: BlueMotion.curve,
        height: 40,
        alignment: Alignment.center,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        decoration: BoxDecoration(
          color: selected ? BlueColors.ink : BlueColors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: selected
                ? BlueColors.ink
                : (error ? BlueColors.choiceError : BlueColors.ghostLine),
          ),
        ),
        child: Text(
          label,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13.5,
            fontWeight: FontWeight.w700,
            color: selected ? BlueColors.white : BlueColors.ink,
          ),
        ),
      ),
    );
  }
}

class LocationPickerField extends StatelessWidget {
  const LocationPickerField({
    super.key,
    required this.value,
    required this.placeholder,
    required this.onPressed,
    this.locked = false,
    this.error = false,
    this.expanded = false,
  });

  final String value;
  final bool placeholder;
  final bool locked;
  final bool error;
  final bool expanded;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final fill = locked ? BlueColors.areaLocked : BlueColors.white;
    final border = error
        ? BlueColors.fieldError
        : (expanded
              ? BlueColors.ink
              : (locked ? const Color(0xFFEAEBF2) : BlueColors.border));
    return BluePressable(
      enabled: !locked && onPressed != null,
      onPressed: locked ? null : onPressed,
      scale: 0.985,
      child: AnimatedContainer(
        duration: BlueMotion.of(context, locationAreaFade),
        curve: BlueMotion.curve,
        height: BlueDimens.fieldHeight,
        padding: const EdgeInsets.symmetric(horizontal: 18),
        decoration: BoxDecoration(
          color: fill,
          borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
          border: Border.all(color: border),
        ),
        child: Row(
          children: [
            Expanded(
              child: AnimatedSwitcher(
                duration: BlueMotion.of(context, locationAreaFade),
                child: Text(
                  value,
                  key: ValueKey(value),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 16.5,
                    fontWeight: FontWeight.w500,
                    color: locked || placeholder
                        ? BlueColors.placeholder
                        : BlueColors.ink,
                  ),
                ),
              ),
            ),
            BlueChevron(size: 13, strokeWidth: 2.4, expanded: expanded),
          ],
        ),
      ),
    );
  }
}

class LocationSaveBar extends StatelessWidget {
  const LocationSaveBar({
    super.key,
    required this.label,
    required this.busy,
    required this.onPressed,
    this.failure,
  });

  final String label;
  final bool busy;
  final VoidCallback onPressed;
  final String? failure;

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
          failure == null ? 12 : 10,
          BlueDimens.checkoutGutter,
          bottom < 18 ? 18 : bottom,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (failure != null) ...[
              LocationFailStrip(message: failure!),
              const SizedBox(height: 10),
            ],
            LocationSaveButton(label: label, busy: busy, onPressed: onPressed),
          ],
        ),
      ),
    );
  }
}

class LocationFailStrip extends StatelessWidget {
  const LocationFailStrip({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: BlueColors.unavailableSurface,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: BlueColors.unavailableLine),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 16,
              height: 16,
              margin: const EdgeInsets.only(top: 1),
              alignment: Alignment.center,
              decoration: const BoxDecoration(
                color: BlueColors.unavailableInk,
                shape: BoxShape.circle,
              ),
              child: const Text(
                'i',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 11,
                  fontWeight: FontWeight.w800,
                  height: 1,
                  color: BlueColors.white,
                ),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                message,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  height: 1.4,
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

class LocationSaveButton extends StatelessWidget {
  const LocationSaveButton({
    super.key,
    required this.label,
    required this.busy,
    required this.onPressed,
  });

  final String label;
  final bool busy;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: busy ? 0.9 : 1,
      child: BluePressable(
        enabled: !busy,
        onPressed: busy ? null : onPressed,
        scale: 0.99,
        duration: const Duration(milliseconds: 140),
        child: AnimatedContainer(
          duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
          curve: BlueMotion.curve,
          height: BlueDimens.checkoutCtaHeight,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: BlueColors.ink,
            borderRadius: BorderRadius.circular(16),
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
                        child: _SaveSpinner(),
                      )
                    : const SizedBox.shrink(key: ValueKey('idle')),
              ),
              AnimatedSwitcher(
                duration: BlueMotion.tick,
                child: Text(
                  label,
                  key: ValueKey(label),
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 15.5,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 15.5 * -0.005,
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

class LocationRetryHint extends StatelessWidget {
  const LocationRetryHint({
    super.key,
    required this.message,
    required this.onRetry,
  });

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Text.rich(
              TextSpan(
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  height: 1.4,
                  fontWeight: FontWeight.w500,
                  color: BlueColors.unavailableInk,
                ),
                children: [
                  TextSpan(text: message),
                  const TextSpan(text: ' '),
                  WidgetSpan(
                    alignment: PlaceholderAlignment.baseline,
                    baseline: TextBaseline.alphabetic,
                    child: GestureDetector(
                      onTap: onRetry,
                      child: const Text(
                        'Try again',
                        style: TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 12.5,
                          height: 1.4,
                          fontWeight: FontWeight.w700,
                          color: BlueColors.unavailableInk,
                          decoration: TextDecoration.underline,
                          decorationColor: BlueColors.unavailableInk,
                        ),
                      ),
                    ),
                  ),
                  const TextSpan(text: '.'),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class LocationFieldError extends StatelessWidget {
  const LocationFieldError({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: BlueErrorHint(message: message),
    );
  }
}

Future<T?> showLocationSearchSheet<T>({
  required BuildContext context,
  required String title,
  required String searchHint,
  required List<T> items,
  required String Function(T item) labelOf,
  required bool Function(T item) selected,
}) {
  return showBlueSheet<T>(
    context: context,
    builder: (context) => _LocationSearchSheet<T>(
      title: title,
      searchHint: searchHint,
      items: items,
      labelOf: labelOf,
      selected: selected,
    ),
  );
}

class _LocationSearchSheet<T> extends StatefulWidget {
  const _LocationSearchSheet({
    required this.title,
    required this.searchHint,
    required this.items,
    required this.labelOf,
    required this.selected,
  });

  final String title;
  final String searchHint;
  final List<T> items;
  final String Function(T item) labelOf;
  final bool Function(T item) selected;

  @override
  State<_LocationSearchSheet<T>> createState() =>
      _LocationSearchSheetState<T>();
}

class _LocationSearchSheetState<T> extends State<_LocationSearchSheet<T>> {
  final _search = TextEditingController();
  final _focus = FocusNode();
  String _query = '';

  @override
  void dispose() {
    _search.dispose();
    _focus.dispose();
    super.dispose();
  }

  List<T> get _filtered {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return widget.items;
    return widget.items
        .where((item) => widget.labelOf(item).toLowerCase().contains(q))
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    final options = _filtered;
    return BlueSheetPanel(
      title: widget.title,
      onClose: () => Navigator.pop(context),
      header: Padding(
        padding: const EdgeInsets.fromLTRB(20, 6, 20, 12),
        child: Container(
          height: 50,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          decoration: BoxDecoration(
            color: BlueColors.canvas,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: BlueColors.border),
          ),
          child: Row(
            children: [
              const CustomPaint(size: Size(16, 16), painter: _SearchPainter()),
              const SizedBox(width: 10),
              Expanded(
                child: TextField(
                  controller: _search,
                  focusNode: _focus,
                  onChanged: (value) => setState(() => _query = value),
                  inputFormatters: const [LatinDigits.formatter],
                  cursorColor: BlueColors.ink,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 15.5,
                    fontWeight: FontWeight.w500,
                    color: BlueColors.ink,
                  ),
                  decoration: InputDecoration(
                    isCollapsed: true,
                    border: InputBorder.none,
                    hintText: widget.searchHint,
                    hintStyle: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15.5,
                      fontWeight: FontWeight.w500,
                      color: BlueColors.placeholder,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
        shrinkWrap: true,
        children: [
          for (var i = 0; i < options.length; i++)
            BlueSheetRow(
              index: i,
              label: widget.labelOf(options[i]),
              selected: widget.selected(options[i]),
              onPressed: () => Navigator.pop(context, options[i]),
            ),
          if (options.isEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 22, 12, 0),
              child: Text(
                'No match for "$_query". Check the spelling or pick from the list.',
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 14,
                  height: 1.5,
                  fontWeight: FontWeight.w400,
                  color: BlueColors.muted,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

Future<bool> confirmLocationDiscard(BuildContext context) async {
  final result = await showGeneralDialog<bool>(
    context: context,
    barrierDismissible: true,
    barrierLabel: 'Dismiss',
    barrierColor: BlueColors.sheetScrim,
    transitionDuration: BlueMotion.sheetOut,
    pageBuilder: (context, _, _) {
      return Align(
        alignment: Alignment.center,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 28),
          child: Material(
            color: Colors.transparent,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: BlueColors.white,
                borderRadius: BorderRadius.circular(22),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x33140050),
                    blurRadius: 32,
                    offset: Offset(0, 18),
                  ),
                ],
              ),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(22, 22, 22, 18),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Discard these changes?',
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 18,
                        height: 1.25,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 18 * -0.02,
                        color: BlueColors.ink,
                      ),
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'Your edits to this location will be lost.',
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 14,
                        height: 1.45,
                        fontWeight: FontWeight.w500,
                        color: BlueColors.muted,
                      ),
                    ),
                    const SizedBox(height: 20),
                    LocationSaveButton(
                      label: 'Keep editing',
                      busy: false,
                      onPressed: () => Navigator.pop(context, false),
                    ),
                    const SizedBox(height: 8),
                    SizedBox(
                      width: double.infinity,
                      child: BluePressable(
                        onPressed: () => Navigator.pop(context, true),
                        child: const Padding(
                          padding: EdgeInsets.symmetric(vertical: 12),
                          child: Text(
                            'Discard',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              fontFamily: BlueFonts.jakarta,
                              fontSize: 14.5,
                              fontWeight: FontWeight.w700,
                              color: BlueColors.muted,
                            ),
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
      );
    },
  );
  return result == true;
}

class _SaveSpinner extends StatefulWidget {
  const _SaveSpinner();

  @override
  State<_SaveSpinner> createState() => _SaveSpinnerState();
}

class _SaveSpinnerState extends State<_SaveSpinner>
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
      child: CustomPaint(size: const Size(17, 17), painter: _SpinnerPainter()),
    );
  }
}

class _SpinnerPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final rect = Rect.fromLTWH(1, 1, size.width - 2, size.height - 2);
    canvas.drawArc(
      rect,
      0,
      6.28,
      false,
      Paint()
        ..color = const Color(0x52FFFFFF)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2,
    );
    canvas.drawArc(
      rect,
      -1.2,
      1.6,
      false,
      Paint()
        ..color = BlueColors.white
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeCap = StrokeCap.round,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class _SearchPainter extends CustomPainter {
  const _SearchPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.chevron
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.8
      ..strokeCap = StrokeCap.round;
    canvas.drawCircle(
      Offset(size.width * 0.42, size.height * 0.42),
      size.width * 0.32,
      paint,
    );
    canvas.drawLine(
      Offset(size.width * 0.66, size.height * 0.66),
      Offset(size.width * 0.88, size.height * 0.88),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
