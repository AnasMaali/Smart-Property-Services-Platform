import 'package:flutter/material.dart';

import '../tokens/blue_colors.dart';
import '../tokens/blue_spacing.dart';
import '../tokens/blue_typography.dart';
import 'blue_feedback.dart';
import 'blue_fields.dart';

/// A tap target that looks like a text field but opens a picker sheet -
/// used for every "choose one from a reference-data list" field (city,
/// area, property type, relationship type, appointment time window...)
/// so these read visually consistent with real text fields while making
/// clear they're a selection, not free text.
class PickerField extends StatelessWidget {
  const PickerField({
    super.key,
    required this.label,
    required this.value,
    this.placeholder = 'Select',
    this.errorText,
    required this.onTap,
    this.enabled = true,
  });

  final String label;
  final String? value;
  final String placeholder;
  final String? errorText;
  final VoidCallback onTap;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: enabled ? onTap : null,
      borderRadius: BorderRadius.circular(12),
      child: InputDecorator(
        decoration: InputDecoration(
          labelText: label,
          errorText: errorText,
          suffixIcon: const Icon(Icons.keyboard_arrow_down_rounded),
          enabled: enabled,
        ),
        child: Text(
          value ?? placeholder,
          style: value == null
              ? BlueTypography.body.copyWith(color: BlueColors.textTertiary)
              : BlueTypography.body,
        ),
      ),
    );
  }
}

/// Presents a searchable single-select list of `{label}`-bearing options
/// in the app's standard modal sheet chrome. Returns the chosen item, or
/// `null` if dismissed without a selection.
Future<T?> showOptionPickerSheet<T>(
  BuildContext context, {
  required String title,
  required List<T> options,
  required String Function(T) labelOf,
  T? selected,
  bool searchable = true,
}) {
  return showBlueModalSheet<T>(
    context,
    builder: (context) => _OptionPickerSheet<T>(
      title: title,
      options: options,
      labelOf: labelOf,
      selected: selected,
      searchable: searchable,
    ),
  );
}

class _OptionPickerSheet<T> extends StatefulWidget {
  const _OptionPickerSheet({
    required this.title,
    required this.options,
    required this.labelOf,
    required this.selected,
    required this.searchable,
  });

  final String title;
  final List<T> options;
  final String Function(T) labelOf;
  final T? selected;
  final bool searchable;

  @override
  State<_OptionPickerSheet<T>> createState() => _OptionPickerSheetState<T>();
}

class _OptionPickerSheetState<T> extends State<_OptionPickerSheet<T>> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final filtered = _query.isEmpty
        ? widget.options
        : widget.options
              .where(
                (o) => widget
                    .labelOf(o)
                    .toLowerCase()
                    .contains(_query.toLowerCase()),
              )
              .toList();

    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.6,
      minChildSize: 0.4,
      maxChildSize: 0.92,
      builder: (context, scrollController) {
        return Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(
                BlueSpacing.pageGutter,
                BlueSpacing.space8,
                BlueSpacing.pageGutter,
                BlueSpacing.space12,
              ),
              child: Text(widget.title, style: BlueTypography.sectionTitle),
            ),
            if (widget.searchable)
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: BlueSpacing.pageGutter,
                ),
                child: BlueTextField.search(
                  hint: 'Search',
                  onChanged: (value) => setState(() => _query = value),
                ),
              ),
            const SizedBox(height: BlueSpacing.space8),
            Flexible(
              child: ListView.builder(
                controller: scrollController,
                shrinkWrap: true,
                itemCount: filtered.length,
                itemBuilder: (context, index) {
                  final option = filtered[index];
                  final isSelected = option == widget.selected;
                  return ListTile(
                    title: Text(widget.labelOf(option)),
                    trailing: isSelected
                        ? const Icon(
                            Icons.check_rounded,
                            color: BlueColors.brandPrimary,
                          )
                        : null,
                    onTap: () => Navigator.of(context).pop(option),
                  );
                },
              ),
            ),
          ],
        );
      },
    );
  }
}
