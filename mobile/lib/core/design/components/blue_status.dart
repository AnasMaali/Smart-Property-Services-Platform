import 'package:flutter/material.dart';

import '../tokens/blue_colors.dart';
import '../tokens/blue_radii.dart';
import '../tokens/blue_sizes.dart';
import '../tokens/blue_spacing.dart';
import '../tokens/blue_typography.dart';

/// The closed set of semantic tones used for every status/badge/panel in
/// the app. Every backend status enum (Booking, Payment, Contract,
/// billing...) maps into one of these tones via a feature-local mapper -
/// never a raw color chosen ad hoc per screen.
enum BlueTone { neutral, brand, info, success, warning, error }

class _ToneColors {
  const _ToneColors(this.foreground, this.surface);
  final Color foreground;
  final Color surface;
}

_ToneColors _colorsFor(BlueTone tone) {
  switch (tone) {
    case BlueTone.neutral:
      return const _ToneColors(
        BlueColors.textSecondary,
        BlueColors.surfaceMuted,
      );
    case BlueTone.brand:
      return _ToneColors(BlueColors.brandPrimary, BlueColors.selectedSurface());
    case BlueTone.info:
      return const _ToneColors(BlueColors.info, BlueColors.infoSurface);
    case BlueTone.success:
      return const _ToneColors(BlueColors.success, BlueColors.successSurface);
    case BlueTone.warning:
      return const _ToneColors(BlueColors.warning, BlueColors.warningSurface);
    case BlueTone.error:
      return const _ToneColors(BlueColors.error, BlueColors.errorSurface);
  }
}

/// A small pill communicating status. Always pairs color with text (and
/// optionally an icon) - status is never conveyed by color alone, so this
/// remains meaningful without color for low-vision/colorblind users.
class StatusBadge extends StatelessWidget {
  const StatusBadge({
    super.key,
    required this.label,
    required this.tone,
    this.icon,
  });

  final String label;
  final BlueTone tone;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final colors = _colorsFor(tone);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: colors.surface,
        borderRadius: BlueRadii.pillRadius,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 14, color: colors.foreground),
            const SizedBox(width: 4),
          ],
          Text(
            label,
            style: BlueTypography.label.copyWith(color: colors.foreground),
          ),
        ],
      ),
    );
  }
}

/// A full-width banner panel for a distinct, important message that isn't
/// tied to a single field - e.g. "payment issue, please update your
/// billing", "your account is scheduled for deletion", checkout-readiness
/// explanations.
class BlueBannerPanel extends StatelessWidget {
  const BlueBannerPanel({
    super.key,
    required this.tone,
    required this.message,
    this.title,
    this.icon,
    this.action,
  });

  final BlueTone tone;
  final String message;
  final String? title;
  final IconData? icon;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final colors = _colorsFor(tone);
    final resolvedIcon = icon ?? _defaultIconFor(tone);

    return Container(
      padding: const EdgeInsets.all(BlueSpacing.space16),
      decoration: BoxDecoration(
        color: colors.surface,
        borderRadius: BlueRadii.mediumRadius,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            resolvedIcon,
            color: colors.foreground,
            size: BlueSizes.iconLarge,
          ),
          const SizedBox(width: BlueSpacing.space12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (title != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Text(
                      title!,
                      style: BlueTypography.bodyStrong.copyWith(
                        color: BlueColors.textPrimary,
                      ),
                    ),
                  ),
                Text(
                  message,
                  style: BlueTypography.supporting.copyWith(
                    color: BlueColors.textSecondary,
                  ),
                ),
                if (action != null) ...[
                  const SizedBox(height: BlueSpacing.space8),
                  action!,
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  static IconData _defaultIconFor(BlueTone tone) {
    switch (tone) {
      case BlueTone.neutral:
        return Icons.info_outline_rounded;
      case BlueTone.brand:
        return Icons.stars_rounded;
      case BlueTone.info:
        return Icons.info_outline_rounded;
      case BlueTone.success:
        return Icons.check_circle_outline_rounded;
      case BlueTone.warning:
        return Icons.error_outline_rounded;
      case BlueTone.error:
        return Icons.cancel_outlined;
    }
  }
}

/// One step in a [BlueTimeline] - used for Booking item progress and
/// Contract lifecycle waiting-states.
class TimelineStep {
  const TimelineStep({required this.label, this.caption, required this.state});

  final String label;
  final String? caption;
  final TimelineStepState state;
}

enum TimelineStepState { done, current, upcoming }

/// A vertical status timeline - "Waiting for review", "Approved",
/// "Awaiting your acceptance"... or a Booking item's
/// assignment/in-progress/completed steps.
class BlueTimeline extends StatelessWidget {
  const BlueTimeline({super.key, required this.steps});

  final List<TimelineStep> steps;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        for (var i = 0; i < steps.length; i++)
          _TimelineRow(step: steps[i], isLast: i == steps.length - 1),
      ],
    );
  }
}

class _TimelineRow extends StatelessWidget {
  const _TimelineRow({required this.step, required this.isLast});

  final TimelineStep step;
  final bool isLast;

  @override
  Widget build(BuildContext context) {
    final Color dotColor;
    final Color lineColor;
    switch (step.state) {
      case TimelineStepState.done:
        dotColor = BlueColors.success;
        lineColor = BlueColors.success;
      case TimelineStepState.current:
        dotColor = BlueColors.brandPrimary;
        lineColor = BlueColors.borderSubtle;
      case TimelineStepState.upcoming:
        dotColor = BlueColors.borderStrong;
        lineColor = BlueColors.borderSubtle;
    }

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Column(
            children: [
              Container(
                width: 12,
                height: 12,
                margin: const EdgeInsets.only(top: 4),
                decoration: BoxDecoration(
                  color: step.state == TimelineStepState.upcoming
                      ? BlueColors.surface
                      : dotColor,
                  shape: BoxShape.circle,
                  border: Border.all(color: dotColor, width: 2),
                ),
              ),
              if (!isLast)
                Expanded(child: Container(width: 2, color: lineColor)),
            ],
          ),
          const SizedBox(width: BlueSpacing.space12),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: BlueSpacing.space20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    step.label,
                    style: step.state == TimelineStepState.upcoming
                        ? BlueTypography.body.copyWith(
                            color: BlueColors.textTertiary,
                          )
                        : BlueTypography.bodyStrong,
                  ),
                  if (step.caption != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(
                        step.caption!,
                        style: BlueTypography.supporting,
                      ),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
