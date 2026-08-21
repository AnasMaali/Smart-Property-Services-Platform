import 'package:flutter/material.dart';

import '../tokens/blue_colors.dart';
import '../tokens/blue_radii.dart';
import '../tokens/blue_sizes.dart';
import '../tokens/blue_spacing.dart';
import '../tokens/blue_typography.dart';

/// A single-choice filter chip (category filters, status filters). For
/// multi-select option values, see [MultiSelectOptionCard]/[BlueCheckboxTile].
class BlueChoiceChip extends StatelessWidget {
  const BlueChoiceChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      child: InkWell(
        onTap: onTap,
        borderRadius: BlueRadii.pillRadius,
        child: Container(
          constraints: const BoxConstraints(
            minHeight: BlueSizes.minTouchTarget - 8,
          ),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          decoration: BoxDecoration(
            color: selected ? BlueColors.selectedSurface() : BlueColors.surface,
            borderRadius: BlueRadii.pillRadius,
            border: Border.all(
              color: selected ? BlueColors.brandPrimary : BlueColors.border,
              width: selected ? 1.5 : 1,
            ),
          ),
          child: Text(
            label,
            style: BlueTypography.bodyStrong.copyWith(
              color: selected
                  ? BlueColors.brandPrimary
                  : BlueColors.textPrimary,
              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            ),
          ),
        ),
      ),
    );
  }
}

/// A card-shaped single-select option (e.g. SINGLE_SELECT service option
/// choices, appointment time-window choice). Shows a leading radio dot so
/// selection state never relies on the border/fill color alone.
class SingleSelectOptionCard extends StatelessWidget {
  const SingleSelectOptionCard({
    super.key,
    required this.title,
    this.subtitle,
    required this.selected,
    required this.onTap,
  });

  final String title;
  final String? subtitle;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return _SelectableCard(
      selected: selected,
      onTap: onTap,
      isRadio: true,
      title: title,
      subtitle: subtitle,
    );
  }
}

/// A card-shaped multi-select option (MULTI_SELECT service option
/// choices) - a checkbox indicator instead of a radio dot.
class MultiSelectOptionCard extends StatelessWidget {
  const MultiSelectOptionCard({
    super.key,
    required this.title,
    this.subtitle,
    required this.selected,
    required this.onTap,
  });

  final String title;
  final String? subtitle;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return _SelectableCard(
      selected: selected,
      onTap: onTap,
      isRadio: false,
      title: title,
      subtitle: subtitle,
    );
  }
}

class _SelectableCard extends StatelessWidget {
  const _SelectableCard({
    required this.selected,
    required this.onTap,
    required this.isRadio,
    required this.title,
    this.subtitle,
  });

  final bool selected;
  final VoidCallback onTap;
  final bool isRadio;
  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      child: InkWell(
        onTap: onTap,
        borderRadius: BlueRadii.mediumRadius,
        child: Container(
          padding: const EdgeInsets.all(BlueSpacing.space12),
          decoration: BoxDecoration(
            color: selected ? BlueColors.selectedSurface() : BlueColors.surface,
            borderRadius: BlueRadii.mediumRadius,
            border: Border.all(
              color: selected ? BlueColors.brandPrimary : BlueColors.border,
              width: selected ? 1.5 : 1,
            ),
          ),
          child: Row(
            children: [
              Icon(
                isRadio
                    ? (selected
                          ? Icons.radio_button_checked_rounded
                          : Icons.radio_button_off_rounded)
                    : (selected
                          ? Icons.check_box_rounded
                          : Icons.check_box_outline_blank_rounded),
                color: selected
                    ? BlueColors.brandPrimary
                    : BlueColors.textTertiary,
                size: BlueSizes.iconLarge,
              ),
              const SizedBox(width: BlueSpacing.space12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: BlueTypography.bodyStrong),
                    if (subtitle != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text(
                          subtitle!,
                          style: BlueTypography.supporting,
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A stepper for cart-item / booking quantity (1-1000 per the backend's
/// contract). Enforces [min]/[max] locally for immediate feedback; the
/// server remains authoritative and may still reject a value.
class QuantitySelector extends StatelessWidget {
  const QuantitySelector({
    super.key,
    required this.quantity,
    required this.onChanged,
    this.min = 1,
    this.max = 1000,
  });

  final int quantity;
  final ValueChanged<int> onChanged;
  final int min;
  final int max;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: BlueColors.border),
        borderRadius: BlueRadii.pillRadius,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _StepButton(
            icon: Icons.remove_rounded,
            semanticLabel: 'Decrease quantity',
            onPressed: quantity > min ? () => onChanged(quantity - 1) : null,
          ),
          SizedBox(
            width: 32,
            child: Text(
              '$quantity',
              textAlign: TextAlign.center,
              style: BlueTypography.bodyStrong,
              semanticsLabel: 'Quantity $quantity',
            ),
          ),
          _StepButton(
            icon: Icons.add_rounded,
            semanticLabel: 'Increase quantity',
            onPressed: quantity < max ? () => onChanged(quantity + 1) : null,
          ),
        ],
      ),
    );
  }
}

class _StepButton extends StatelessWidget {
  const _StepButton({
    required this.icon,
    required this.semanticLabel,
    required this.onPressed,
  });

  final IconData icon;
  final String semanticLabel;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 40,
      height: 40,
      child: IconButton(
        onPressed: onPressed,
        tooltip: semanticLabel,
        icon: Icon(icon, size: BlueSizes.iconMedium),
        color: onPressed == null
            ? BlueColors.disabled
            : BlueColors.brandPrimary,
      ),
    );
  }
}

/// A horizontally-laid-out segmented control for 2-4 mutually-exclusive
/// options (e.g. property status filter: Active / Archived / All).
class BlueSegmentedControl<T> extends StatelessWidget {
  const BlueSegmentedControl({
    super.key,
    required this.segments,
    required this.selected,
    required this.onChanged,
  });

  final List<({T value, String label})> segments;
  final T selected;
  final ValueChanged<T> onChanged;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: BlueColors.surfaceMuted,
        borderRadius: BlueRadii.mediumRadius,
      ),
      child: Row(
        children: [
          for (final segment in segments)
            Expanded(
              child: _SegmentButton(
                label: segment.label,
                selected: segment.value == selected,
                onTap: () => onChanged(segment.value),
              ),
            ),
        ],
      ),
    );
  }
}

class _SegmentButton extends StatelessWidget {
  const _SegmentButton({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      selected: selected,
      child: InkWell(
        onTap: onTap,
        borderRadius: BlueRadii.smallRadius,
        child: Container(
          height: 36,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: selected ? BlueColors.surface : Colors.transparent,
            borderRadius: BlueRadii.smallRadius,
          ),
          child: Text(
            label,
            style: BlueTypography.caption.copyWith(
              color: selected
                  ? BlueColors.textPrimary
                  : BlueColors.textSecondary,
              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            ),
          ),
        ),
      ),
    );
  }
}
