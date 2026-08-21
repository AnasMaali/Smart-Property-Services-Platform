import 'package:flutter/material.dart';

import '../tokens/blue_colors.dart';
import '../tokens/blue_radii.dart';
import '../tokens/blue_sizes.dart';
import '../tokens/blue_typography.dart';

/// Shared loading-spinner treatment so every button variant below shows
/// "busy" identically - label hidden, a small inverse/brand spinner in its
/// place, control kept the same size (no layout jump) and disabled to
/// prevent double-submission.
class _ButtonSpinner extends StatelessWidget {
  const _ButtonSpinner({required this.color});

  final Color color;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 20,
      width: 20,
      child: CircularProgressIndicator(strokeWidth: 2.4, color: color),
    );
  }
}

/// The main call-to-action button. One primary button per screen is the
/// rule of thumb - it must always be the most visually dominant control.
class PrimaryButton extends StatelessWidget {
  const PrimaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.isLoading = false,
    this.icon,
    this.expand = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final bool isLoading;
  final IconData? icon;
  final bool expand;

  @override
  Widget build(BuildContext context) {
    final busy = isLoading || onPressed == null;
    final child = isLoading
        ? const _ButtonSpinner(color: BlueColors.textInverse)
        : icon == null
        ? Text(label)
        : Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: BlueSizes.iconMedium),
              const SizedBox(width: 8),
              Text(label),
            ],
          );

    final button = FilledButton(
      onPressed: isLoading ? null : onPressed,
      child: Semantics(
        label: isLoading ? '$label, loading' : label,
        button: true,
        enabled: !busy,
        child: child,
      ),
    );

    return expand ? SizedBox(width: double.infinity, child: button) : button;
  }
}

/// Secondary emphasis action - paired next to a [PrimaryButton] or used
/// alone for a non-primary but still meaningful action ("View details").
class SecondaryButton extends StatelessWidget {
  const SecondaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.isLoading = false,
    this.icon,
    this.expand = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final bool isLoading;
  final IconData? icon;
  final bool expand;

  @override
  Widget build(BuildContext context) {
    final child = isLoading
        ? const _ButtonSpinner(color: BlueColors.brandPrimary)
        : icon == null
        ? Text(label)
        : Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: BlueSizes.iconMedium),
              const SizedBox(width: 8),
              Text(label),
            ],
          );

    final button = OutlinedButton(
      onPressed: isLoading ? null : onPressed,
      child: Semantics(
        label: isLoading ? '$label, loading' : label,
        button: true,
        enabled: !isLoading && onPressed != null,
        child: child,
      ),
    );

    return expand ? SizedBox(width: double.infinity, child: button) : button;
  }
}

/// Lowest emphasis action - "Skip", "Not now", inline links.
class TertiaryButton extends StatelessWidget {
  const TertiaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return TextButton(
      onPressed: onPressed,
      child: icon == null
          ? Text(label)
          : Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(icon, size: BlueSizes.iconMedium),
                const SizedBox(width: 6),
                Text(label),
              ],
            ),
    );
  }
}

/// A high-severity destructive action rendered as a filled button
/// (e.g. the final "Delete account" / "Cancel booking" confirmation) -
/// visually unmistakable from [PrimaryButton] via the error color, and
/// never placed directly adjacent to a primary CTA without a clear gap so
/// the two can't be mis-tapped for each other.
class DestructiveButton extends StatelessWidget {
  const DestructiveButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.isLoading = false,
    this.expand = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final bool isLoading;
  final bool expand;

  @override
  Widget build(BuildContext context) {
    final button = FilledButton(
      onPressed: isLoading ? null : onPressed,
      style: FilledButton.styleFrom(
        backgroundColor: BlueColors.error,
        foregroundColor: BlueColors.textInverse,
        disabledBackgroundColor: BlueColors.disabled,
        minimumSize: const Size.fromHeight(BlueSizes.controlHeightLarge),
        textStyle: BlueTypography.button,
        shape: RoundedRectangleBorder(borderRadius: BlueRadii.mediumRadius),
      ),
      child: Semantics(
        label: isLoading ? '$label, loading' : label,
        button: true,
        enabled: !isLoading && onPressed != null,
        child: isLoading
            ? const _ButtonSpinner(color: BlueColors.textInverse)
            : Text(label),
      ),
    );

    return expand ? SizedBox(width: double.infinity, child: button) : button;
  }
}

/// A lower-severity destructive action rendered as text only
/// (e.g. "Remove item" in a cart row) - still colored with [BlueColors.error]
/// so it reads as destructive without the visual weight of a full button.
class DestructiveTextButton extends StatelessWidget {
  const DestructiveTextButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return TextButton(
      onPressed: onPressed,
      style: TextButton.styleFrom(foregroundColor: BlueColors.error),
      child: icon == null
          ? Text(label)
          : Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(icon, size: BlueSizes.iconMedium),
                const SizedBox(width: 6),
                Text(label),
              ],
            ),
    );
  }
}

/// A standalone icon-only button that always meets the minimum touch
/// target and always requires a semantic label (icon-only controls are
/// otherwise invisible to VoiceOver/TalkBack).
class BlueIconButton extends StatelessWidget {
  const BlueIconButton({
    super.key,
    required this.icon,
    required this.semanticLabel,
    required this.onPressed,
    this.filled = false,
  });

  final IconData icon;
  final String semanticLabel;
  final VoidCallback? onPressed;

  /// When true, renders on a soft brand-tinted circular background - used
  /// for a floating/overlaid icon action (e.g. back button over a hero
  /// image) rather than an inline toolbar icon.
  final bool filled;

  @override
  Widget build(BuildContext context) {
    final button = IconButton(
      onPressed: onPressed,
      tooltip: semanticLabel,
      icon: Icon(icon),
      style: filled
          ? IconButton.styleFrom(
              backgroundColor: BlueColors.surface.withValues(alpha: 0.92),
              foregroundColor: BlueColors.textPrimary,
            )
          : null,
    );
    return SizedBox(
      width: BlueSizes.minTouchTarget,
      height: BlueSizes.minTouchTarget,
      child: button,
    );
  }
}
