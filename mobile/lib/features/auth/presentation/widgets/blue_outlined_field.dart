import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../../core/input/latin_digits.dart';

class BlueOutlinedField extends StatefulWidget {
  const BlueOutlinedField({
    super.key,
    required this.controller,
    required this.focusNode,
    required this.hint,
    required this.error,
    required this.enabled,
    required this.onChanged,
    this.keyboardType,
    this.textInputAction,
    this.autofillHints,
    this.inputFormatters,
    this.autocorrect = true,
    this.enableSuggestions = true,
    this.textCapitalization = TextCapitalization.none,
    this.obscureText = false,
    this.minLines,
    this.maxLines = 1,
    this.onSubmitted,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final String hint;
  final bool error;
  final bool enabled;
  final ValueChanged<String> onChanged;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final Iterable<String>? autofillHints;
  final List<TextInputFormatter>? inputFormatters;
  final bool autocorrect;
  final bool enableSuggestions;
  final TextCapitalization textCapitalization;
  final bool obscureText;
  final int? minLines;
  final int maxLines;
  final ValueChanged<String>? onSubmitted;

  @override
  State<BlueOutlinedField> createState() => _BlueOutlinedFieldState();
}

class _BlueOutlinedFieldState extends State<BlueOutlinedField> {
  late bool _hidden = widget.obscureText;

  @override
  void initState() {
    super.initState();
    widget.focusNode.addListener(_onFocus);
  }

  @override
  void didUpdateWidget(covariant BlueOutlinedField oldWidget) {
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
          height: widget.maxLines == 1 ? BlueDimens.fieldHeight : null,
          constraints: widget.maxLines == 1
              ? null
              : BoxConstraints(
                  minHeight: 24.0 * (widget.minLines ?? widget.maxLines) + 32,
                ),
          padding: widget.maxLines == 1
              ? const EdgeInsets.symmetric(horizontal: 17)
              : const EdgeInsets.fromLTRB(17, 16, 17, 16),
          alignment: widget.maxLines == 1
              ? Alignment.centerLeft
              : Alignment.topLeft,
          decoration: BoxDecoration(
            color: BlueColors.white,
            borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
            border: Border.all(color: BlueColors.border),
          ),
          child: Row(
            crossAxisAlignment: widget.maxLines == 1
                ? CrossAxisAlignment.center
                : CrossAxisAlignment.start,
            children: [
              Expanded(
                child: TextField(
                  controller: widget.controller,
                  focusNode: widget.focusNode,
                  enabled: widget.enabled,
                  obscureText: _hidden,
                  minLines: widget.minLines,
                  maxLines: widget.maxLines,
                  keyboardType: widget.maxLines == 1
                      ? widget.keyboardType
                      : (widget.keyboardType ?? TextInputType.multiline),
                  textInputAction: widget.textInputAction,
                  textCapitalization: widget.textCapitalization,
                  textAlignVertical: widget.maxLines == 1
                      ? TextAlignVertical.center
                      : TextAlignVertical.top,
                  autocorrect: widget.autocorrect,
                  enableSuggestions: widget.enableSuggestions,
                  autofillHints: widget.autofillHints,
                  inputFormatters: LatinDigits.merge(widget.inputFormatters),
                  cursorColor: BlueColors.ink,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 16.5,
                    height: 1.35,
                    fontWeight: FontWeight.w500,
                    color: BlueColors.ink,
                  ),
                  decoration: InputDecoration(
                    isCollapsed: true,
                    border: InputBorder.none,
                    hintText: widget.hint,
                    hintStyle: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 16.5,
                      height: 1.35,
                      fontWeight: FontWeight.w500,
                      color: BlueColors.placeholder,
                    ),
                  ),
                  onChanged: widget.onChanged,
                  onSubmitted: widget.onSubmitted,
                ),
              ),
              if (widget.obscureText)
                GestureDetector(
                  onTap: () => setState(() => _hidden = !_hidden),
                  child: Icon(
                    _hidden
                        ? Icons.visibility_outlined
                        : Icons.visibility_off_outlined,
                    size: 20,
                    color: BlueColors.chevron,
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
              duration: const Duration(milliseconds: 200),
              curve: const Cubic(0.22, 0.61, 0.36, 1),
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
