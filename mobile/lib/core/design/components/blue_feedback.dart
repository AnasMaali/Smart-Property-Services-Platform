import 'package:flutter/material.dart';

import '../tokens/blue_colors.dart';
import '../tokens/blue_motion.dart';
import '../tokens/blue_radii.dart';
import '../tokens/blue_sizes.dart';
import '../tokens/blue_spacing.dart';
import '../tokens/blue_typography.dart';
import 'blue_buttons.dart';

/// A shimmering placeholder block for loading content. Respects reduced
/// motion by freezing the shimmer instead of disabling it outright (the
/// block itself still communicates "loading" via its shape).
class SkeletonBlock extends StatefulWidget {
  const SkeletonBlock({
    super.key,
    this.width,
    this.height = 16,
    this.borderRadius,
  });

  final double? width;
  final double height;
  final BorderRadius? borderRadius;

  @override
  State<SkeletonBlock> createState() => _SkeletonBlockState();
}

class _SkeletonBlockState extends State<SkeletonBlock>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1100),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final reduceMotion = MediaQuery.of(context).disableAnimations;
    return ExcludeSemantics(
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, _) {
          final t = reduceMotion ? 0.5 : _controller.value;
          return Container(
            width: widget.width,
            height: widget.height,
            decoration: BoxDecoration(
              color: Color.lerp(
                BlueColors.surfaceMuted,
                BlueColors.borderSubtle,
                t,
              ),
              borderRadius: widget.borderRadius ?? BlueRadii.smallRadius,
            ),
          );
        },
      ),
    );
  }
}

/// A card-shaped skeleton for list screens (service/category/booking
/// cards while their first fetch is in flight).
class SkeletonListTile extends StatelessWidget {
  const SkeletonListTile({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(BlueSpacing.cardPadding),
      decoration: BoxDecoration(
        color: BlueColors.surface,
        borderRadius: BlueRadii.largeRadius,
        border: Border.all(color: BlueColors.borderSubtle),
      ),
      child: Row(
        children: [
          const SkeletonBlock(
            width: 56,
            height: 56,
            borderRadius: BlueRadii.mediumRadius,
          ),
          const SizedBox(width: BlueSpacing.space12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: const [
                SkeletonBlock(width: 160, height: 14),
                SizedBox(height: 8),
                SkeletonBlock(width: 100, height: 12),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The base "nothing here yet" / "something went wrong" full-content
/// state - centered icon, headline, body, and up to one primary action.
/// [EmptyStateView] and [ErrorStateView] are thin named wrappers over
/// this so call sites read intent-first.
class _CenteredStateView extends StatelessWidget {
  const _CenteredStateView({
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
    this.iconColor = BlueColors.textTertiary,
    this.iconSurface = BlueColors.surfaceMuted,
  });

  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;
  final Color iconColor;
  final Color iconSurface;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: BlueSpacing.space32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 72,
              height: 72,
              decoration: BoxDecoration(
                color: iconSurface,
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: BlueSizes.iconXL, color: iconColor),
            ),
            const SizedBox(height: BlueSpacing.space20),
            Text(
              title,
              style: BlueTypography.sectionTitle,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: BlueSpacing.space8),
            Text(
              message,
              style: BlueTypography.supporting,
              textAlign: TextAlign.center,
            ),
            if (actionLabel != null && onAction != null) ...[
              const SizedBox(height: BlueSpacing.space24),
              SecondaryButton(
                label: actionLabel!,
                onPressed: onAction,
                expand: false,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// "Nothing here yet" state (empty cart, no bookings, no contracts, no
/// properties, no services in a category...).
class EmptyStateView extends StatelessWidget {
  const EmptyStateView({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
  });

  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return _CenteredStateView(
      icon: icon,
      title: title,
      message: message,
      actionLabel: actionLabel,
      onAction: onAction,
      iconColor: BlueColors.brandPrimary,
      iconSurface: BlueColors.selectedSurface(),
    );
  }
}

/// A retryable failure state (network/server error) - never shows raw
/// backend/exception text, only a human message plus a Retry action.
class ErrorStateView extends StatelessWidget {
  const ErrorStateView({
    super.key,
    this.title = 'Something went wrong',
    this.message = "We couldn't load this. Please try again.",
    required this.onRetry,
    this.icon = Icons.wifi_off_rounded,
  });

  final String title;
  final String message;
  final VoidCallback onRetry;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return _CenteredStateView(
      icon: icon,
      title: title,
      message: message,
      actionLabel: 'Try again',
      onAction: onRetry,
      iconColor: BlueColors.error,
      iconSurface: BlueColors.errorSurface,
    );
  }
}

/// An inline (non-full-screen) loading row - used inside a screen that
/// already has visible chrome while one section refreshes.
class InlineLoadingRow extends StatelessWidget {
  const InlineLoadingRow({super.key, this.label});

  final String? label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: BlueSpacing.space24),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(
              width: 24,
              height: 24,
              child: CircularProgressIndicator(strokeWidth: 2.4),
            ),
            if (label != null) ...[
              const SizedBox(height: BlueSpacing.space12),
              Text(label!, style: BlueTypography.supporting),
            ],
          ],
        ),
      ),
    );
  }
}

/// Shows the app's standard snackbar - always text + optional action,
/// never relies on color alone (an icon communicates tone too).
void showBlueSnackBar(
  BuildContext context, {
  required String message,
  BlueSnackBarTone tone = BlueSnackBarTone.neutral,
  String? actionLabel,
  VoidCallback? onAction,
}) {
  final messenger = ScaffoldMessenger.of(context);
  messenger.hideCurrentSnackBar();
  messenger.showSnackBar(
    SnackBar(
      content: Row(
        children: [
          Icon(tone._icon, color: tone._color, size: BlueSizes.iconMedium),
          const SizedBox(width: BlueSpacing.space12),
          Expanded(child: Text(message)),
        ],
      ),
      action: actionLabel == null
          ? null
          : SnackBarAction(label: actionLabel, onPressed: onAction ?? () {}),
      duration:
          BlueMotion.resolve(context, const Duration(seconds: 4)) ==
              Duration.zero
          ? const Duration(seconds: 6)
          : const Duration(seconds: 4),
    ),
  );
}

enum BlueSnackBarTone {
  neutral,
  success,
  error;

  IconData get _icon => switch (this) {
    BlueSnackBarTone.neutral => Icons.info_outline_rounded,
    BlueSnackBarTone.success => Icons.check_circle_outline_rounded,
    BlueSnackBarTone.error => Icons.error_outline_rounded,
  };

  Color get _color => switch (this) {
    BlueSnackBarTone.neutral => BlueColors.textInverseSecondary,
    BlueSnackBarTone.success => const Color(0xFF7BE0B1),
    BlueSnackBarTone.error => const Color(0xFFF3A9B2),
  };
}

/// A standard confirmation dialog for a reversible or destructive action.
/// [isDestructive] renders the confirm action in [DestructiveButton]
/// styling so a delete/cancel confirmation is visually unmistakable from
/// a routine confirmation.
Future<bool> showBlueConfirmationDialog(
  BuildContext context, {
  required String title,
  required String message,
  String confirmLabel = 'Confirm',
  String cancelLabel = 'Cancel',
  bool isDestructive = false,
}) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Text(message),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: Text(cancelLabel),
        ),
        if (isDestructive)
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: BlueColors.error),
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(confirmLabel),
          )
        else
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(confirmLabel),
          ),
      ],
    ),
  );
  return result ?? false;
}

/// Presents [child] in the app's standard modal bottom sheet chrome
/// (drag handle, rounded top, safe-area aware).
Future<T?> showBlueModalSheet<T>(
  BuildContext context, {
  required WidgetBuilder builder,
  bool isScrollControlled = true,
}) {
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: isScrollControlled,
    useSafeArea: true,
    builder: (context) => Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: builder(context),
    ),
  );
}
