import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../../core/input/latin_digits.dart';
import 'blue_chevron.dart';
import 'blue_country_sheet.dart';
import 'blue_motion.dart';
import 'blue_sheet.dart';

abstract final class UaePhone {
  static String digits(String raw) {
    final digits = LatinDigits.only(raw);
    return digits.length <= 9 ? digits : digits.substring(0, 9);
  }

  static String format(String raw) {
    final d = digits(raw);
    final parts = <String>[
      if (d.isNotEmpty) d.substring(0, d.length.clamp(0, 2)),
      if (d.length > 2) d.substring(2, d.length.clamp(2, 5)),
      if (d.length > 5) d.substring(5, d.length.clamp(5, 9)),
    ];
    return parts.join(' ');
  }

  static String e164(String raw, {String dial = '+971'}) {
    return '$dial${digits(raw)}';
  }
}

class BluePhoneField extends StatefulWidget {
  const BluePhoneField({
    super.key,
    required this.controller,
    required this.focusNode,
    required this.error,
    required this.enabled,
    required this.onChanged,
    this.onSubmitted,
    this.onCountryChanged,
    this.countryLocked = false,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final bool error;
  final bool enabled;
  final ValueChanged<String> onChanged;
  final ValueChanged<String>? onSubmitted;
  final ValueChanged<BlueCountry>? onCountryChanged;
  final bool countryLocked;

  @override
  State<BluePhoneField> createState() => _BluePhoneFieldState();
}

class _BluePhoneFieldState extends State<BluePhoneField> {
  BlueCountry _country = BlueCountry.uae;
  bool _countryOpen = false;

  @override
  void initState() {
    super.initState();
    widget.focusNode.addListener(_onFocus);
  }

  @override
  void didUpdateWidget(covariant BluePhoneField oldWidget) {
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

  Future<void> _openCountries() async {
    if (!widget.enabled || widget.countryLocked || _countryOpen) return;
    widget.focusNode.unfocus();
    setState(() => _countryOpen = true);
    final picked = await showBlueSheet<BlueCountry>(
      context: context,
      builder: (ctx) => BlueCountrySheet(
        selected: _country,
        onPicked: (country) => Navigator.of(ctx).pop(country),
        onClose: () => Navigator.of(ctx).pop(),
      ),
    );
    if (!mounted) return;
    setState(() {
      _countryOpen = false;
      if (picked != null) {
        _country = picked;
        widget.onCountryChanged?.call(picked);
      }
    });
  }

  Color get _ring {
    if (widget.error) return BlueColors.error;
    if (widget.focusNode.hasFocus) return BlueColors.ink;
    return Colors.transparent;
  }

  List<BoxShadow> get _glow {
    if (widget.error) {
      return const [BoxShadow(color: BlueColors.glowError, spreadRadius: 4)];
    }
    if (widget.focusNode.hasFocus) {
      return const [BoxShadow(color: BlueColors.glowInk, spreadRadius: 4)];
    }
    return const [];
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Container(
          height: BlueDimens.fieldHeight,
          padding: const EdgeInsets.symmetric(horizontal: 17),
          decoration: BoxDecoration(
            color: BlueColors.white,
            borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
            border: Border.all(color: BlueColors.border),
          ),
          child: Row(
            children: [
              _CountryCode(
                country: _country,
                expanded: _countryOpen,
                enabled: widget.enabled && !widget.countryLocked,
                locked: widget.countryLocked,
                onPressed: _openCountries,
              ),
              Container(
                width: 1,
                height: 24,
                margin: const EdgeInsets.only(left: 12, right: 13),
                color: BlueColors.border,
              ),
              Expanded(
                child: TextField(
                  controller: widget.controller,
                  focusNode: widget.focusNode,
                  enabled: widget.enabled,
                  keyboardType: TextInputType.phone,
                  textInputAction: TextInputAction.go,
                  autocorrect: false,
                  enableSuggestions: false,
                  inputFormatters: [
                    LatinDigits.formatter,
                    FilteringTextInputFormatter.allow(RegExp(r'[0-9 ]')),
                  ],
                  cursorColor: BlueColors.ink,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 16.5,
                    fontWeight: FontWeight.w500,
                    letterSpacing: 16.5 * 0.02,
                    color: BlueColors.ink,
                  ),
                  decoration: const InputDecoration(
                    isCollapsed: true,
                    border: InputBorder.none,
                    hintText: '50 123 4567',
                    hintStyle: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 16.5,
                      fontWeight: FontWeight.w500,
                      letterSpacing: 16.5 * 0.02,
                      color: BlueColors.placeholder,
                    ),
                  ),
                  onChanged: widget.onChanged,
                  onSubmitted: widget.onSubmitted,
                ),
              ),
            ],
          ),
        ),
        Positioned(
          top: -1,
          left: -1,
          right: -1,
          bottom: -1,
          child: IgnorePointer(
            child: AnimatedContainer(
              duration: BlueMotion.snap,
              curve: BlueMotion.curve,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(BlueDimens.fieldRadius + 1),
                border: Border.all(color: _ring, width: 2),
                boxShadow: _glow,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _CountryCode extends StatefulWidget {
  const _CountryCode({
    required this.country,
    required this.expanded,
    required this.enabled,
    required this.onPressed,
    this.locked = false,
  });

  final BlueCountry country;
  final bool expanded;
  final bool enabled;
  final bool locked;
  final VoidCallback onPressed;

  @override
  State<_CountryCode> createState() => _CountryCodeState();
}

class _CountryCodeState extends State<_CountryCode> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTapDown: widget.enabled ? (_) => setState(() => _down = true) : null,
      onTapUp: widget.enabled ? (_) => setState(() => _down = false) : null,
      onTapCancel: widget.enabled ? () => setState(() => _down = false) : null,
      onTap: widget.enabled ? widget.onPressed : null,
      child: AnimatedScale(
        scale: _down ? 0.96 : 1,
        duration: BlueMotion.press,
        curve: Curves.easeOut,
        child: AnimatedContainer(
          duration: BlueMotion.press,
          padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 6),
          decoration: BoxDecoration(
            color: _down || widget.expanded
                ? BlueColors.press
                : Colors.transparent,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            children: [
              AnimatedSwitcher(
                duration: BlueMotion.tick,
                switchInCurve: BlueMotion.curve,
                transitionBuilder: (child, animation) {
                  return FadeTransition(
                    opacity: animation,
                    child: ScaleTransition(scale: animation, child: child),
                  );
                },
                child: BlueCountryFlag(
                  key: ValueKey(widget.country.iso),
                  iso: widget.country.iso,
                ),
              ),
              const SizedBox(width: 7),
              AnimatedSwitcher(
                duration: BlueMotion.tick,
                child: Text(
                  widget.country.dial,
                  key: ValueKey(widget.country.dial),
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 15.5,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 15.5 * 0.005,
                    color: BlueColors.ink,
                  ),
                ),
              ),
              if (!widget.locked) ...[
                const SizedBox(width: 7),
                BlueChevron(expanded: widget.expanded),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
