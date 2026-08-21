import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../tokens/blue_colors.dart';
import '../tokens/blue_radii.dart';
import '../tokens/blue_sizes.dart';
import '../tokens/blue_spacing.dart';
import '../tokens/blue_typography.dart';

/// The one text-input widget used across the app - Register, Login,
/// Checkout Location, Edit Profile, Add Property, etc. all configure this
/// rather than each screen building its own [TextField]. Covers text,
/// email, phone, password (with visibility toggle), search, and
/// multi-line textarea via constructor parameters.
class BlueTextField extends StatefulWidget {
  const BlueTextField({
    super.key,
    this.controller,
    this.label,
    this.hint,
    this.helperText,
    this.errorText,
    this.keyboardType,
    this.textInputAction,
    this.isPassword = false,
    this.isMultiline = false,
    this.maxLines = 1,
    this.autofillHints,
    this.prefixIcon,
    this.enabled = true,
    this.onChanged,
    this.onSubmitted,
    this.textCapitalization = TextCapitalization.none,
    this.inputFormatters,
    this.focusNode,
  });

  final TextEditingController? controller;
  final String? label;
  final String? hint;
  final String? helperText;
  final String? errorText;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final bool isPassword;
  final bool isMultiline;
  final int maxLines;
  final Iterable<String>? autofillHints;
  final IconData? prefixIcon;
  final bool enabled;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final TextCapitalization textCapitalization;
  final List<TextInputFormatter>? inputFormatters;
  final FocusNode? focusNode;

  /// Convenience factory for a search field - rounded, magnifier-prefixed,
  /// no label/helper/error chrome.
  factory BlueTextField.search({
    Key? key,
    TextEditingController? controller,
    String hint = 'Search',
    ValueChanged<String>? onChanged,
  }) {
    return BlueTextField(
      key: key,
      controller: controller,
      hint: hint,
      prefixIcon: Icons.search_rounded,
      textInputAction: TextInputAction.search,
      onChanged: onChanged,
    );
  }

  @override
  State<BlueTextField> createState() => _BlueTextFieldState();
}

class _BlueTextFieldState extends State<BlueTextField> {
  bool _obscure = true;

  @override
  Widget build(BuildContext context) {
    final hasError = widget.errorText != null && widget.errorText!.isNotEmpty;

    return TextField(
      controller: widget.controller,
      focusNode: widget.focusNode,
      enabled: widget.enabled,
      obscureText: widget.isPassword && _obscure,
      keyboardType: widget.isMultiline
          ? TextInputType.multiline
          : widget.keyboardType,
      textInputAction: widget.textInputAction,
      minLines: widget.isMultiline ? 3 : 1,
      maxLines: widget.isPassword
          ? 1
          : (widget.isMultiline ? 6 : widget.maxLines),
      autofillHints: widget.autofillHints,
      textCapitalization: widget.textCapitalization,
      inputFormatters: widget.inputFormatters,
      onChanged: widget.onChanged,
      onSubmitted: widget.onSubmitted,
      style: BlueTypography.body,
      decoration: InputDecoration(
        labelText: widget.label,
        hintText: widget.hint,
        helperText: hasError ? null : widget.helperText,
        errorText: widget.errorText,
        prefixIcon: widget.prefixIcon == null
            ? null
            : Icon(widget.prefixIcon, size: BlueSizes.iconMedium),
        suffixIcon: widget.isPassword
            ? IconButton(
                icon: Icon(
                  _obscure
                      ? Icons.visibility_outlined
                      : Icons.visibility_off_outlined,
                  size: BlueSizes.iconMedium,
                ),
                tooltip: _obscure ? 'Show password' : 'Hide password',
                onPressed: () => setState(() => _obscure = !_obscure),
              )
            : null,
      ),
    );
  }
}

/// A 6-box one-time-passcode entry field. Emits [onCompleted] once all
/// digits are entered and exposes [onChanged] for live state (e.g.
/// clearing a stale error as soon as the user edits).
class OtpCodeField extends StatefulWidget {
  const OtpCodeField({
    super.key,
    this.length = 6,
    required this.onCompleted,
    this.onChanged,
    this.hasError = false,
  });

  final int length;
  final ValueChanged<String> onCompleted;
  final ValueChanged<String>? onChanged;
  final bool hasError;

  @override
  State<OtpCodeField> createState() => _OtpCodeFieldState();
}

class _OtpCodeFieldState extends State<OtpCodeField> {
  late final List<TextEditingController> _controllers;
  late final List<FocusNode> _focusNodes;

  @override
  void initState() {
    super.initState();
    _controllers = List.generate(widget.length, (_) => TextEditingController());
    _focusNodes = List.generate(widget.length, (_) => FocusNode());
  }

  @override
  void dispose() {
    for (final c in _controllers) {
      c.dispose();
    }
    for (final f in _focusNodes) {
      f.dispose();
    }
    super.dispose();
  }

  void _handleChanged(int index, String value) {
    if (value.isNotEmpty && index < widget.length - 1) {
      _focusNodes[index + 1].requestFocus();
    }
    if (value.isEmpty && index > 0) {
      _focusNodes[index - 1].requestFocus();
    }
    final code = _controllers.map((c) => c.text).join();
    widget.onChanged?.call(code);
    if (code.length == widget.length) {
      widget.onCompleted(code);
    }
  }

  @override
  Widget build(BuildContext context) {
    final borderColor = widget.hasError ? BlueColors.error : BlueColors.border;

    return Semantics(
      label: 'One-time passcode, ${widget.length} digits',
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: List.generate(widget.length, (index) {
          return SizedBox(
            width: 44,
            height: 52,
            child: TextField(
              controller: _controllers[index],
              focusNode: _focusNodes[index],
              textAlign: TextAlign.center,
              keyboardType: TextInputType.number,
              maxLength: 1,
              style: BlueTypography.sectionTitle,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(
                counterText: '',
                contentPadding: EdgeInsets.zero,
                border: OutlineInputBorder(
                  borderRadius: BlueRadii.smallRadius,
                  borderSide: BorderSide(color: borderColor),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BlueRadii.smallRadius,
                  borderSide: BorderSide(color: borderColor),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BlueRadii.smallRadius,
                  borderSide: const BorderSide(
                    color: BlueColors.focus,
                    width: 2,
                  ),
                ),
              ),
              onChanged: (value) => _handleChanged(index, value),
            ),
          );
        }),
      ),
    );
  }
}

/// A labeled field group used to attach a section label above a
/// non-text-field control (e.g. a picker row) with the same vertical
/// rhythm as [BlueTextField].
class FieldLabel extends StatelessWidget {
  const FieldLabel({super.key, required this.text, this.required = false});

  final String text;
  final bool required;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: BlueSpacing.space8),
      child: RichText(
        text: TextSpan(
          style: BlueTypography.caption.copyWith(
            color: BlueColors.textSecondary,
          ),
          children: [
            TextSpan(text: text),
            if (required)
              const TextSpan(
                text: ' *',
                style: TextStyle(color: BlueColors.error),
              ),
          ],
        ),
      ),
    );
  }
}
