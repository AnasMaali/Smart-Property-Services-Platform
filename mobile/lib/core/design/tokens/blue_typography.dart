import 'package:flutter/material.dart';

import 'blue_colors.dart';

/// The BLUE mobile type scale.
///
/// Deliberately uses the platform-default system font (no bundled custom
/// typeface) - this keeps the binary small, renders natively on both iOS
/// and Android without a licensing question, and - most importantly -
/// respects the OS-level Dynamic Type (iOS) / font-scale (Android)
/// accessibility setting automatically, since every size below is a base
/// size that Flutter's `MediaQuery.textScaler` still scales at render
/// time. Never wrap these in a fixed-size `Text` that disables scaling.
///
/// Layouts consuming these styles must tolerate at least a 130% scale
/// factor without clipping or overlapping - verified as part of the
/// accessibility pass, not assumed here.
abstract final class BlueTypography {
  /// Rare hero/display moments (e.g. a branded empty state headline).
  static const TextStyle display = TextStyle(
    fontSize: 32,
    height: 40 / 32,
    fontWeight: FontWeight.w700,
    letterSpacing: -0.4,
    color: BlueColors.textPrimary,
  );

  /// The title of a full screen (app bar large title / page heading).
  static const TextStyle pageTitle = TextStyle(
    fontSize: 24,
    height: 32 / 24,
    fontWeight: FontWeight.w700,
    letterSpacing: -0.2,
    color: BlueColors.textPrimary,
  );

  /// A section heading within a screen.
  static const TextStyle sectionTitle = TextStyle(
    fontSize: 18,
    height: 24 / 18,
    fontWeight: FontWeight.w700,
    color: BlueColors.textPrimary,
  );

  /// A card's own title (service name, booking number, contract name).
  static const TextStyle cardTitle = TextStyle(
    fontSize: 16,
    height: 22 / 16,
    fontWeight: FontWeight.w600,
    color: BlueColors.textPrimary,
  );

  /// Default body copy.
  static const TextStyle body = TextStyle(
    fontSize: 15,
    height: 22 / 15,
    fontWeight: FontWeight.w400,
    color: BlueColors.textPrimary,
  );

  /// Body copy with more emphasis than [body] but lighter than a title.
  static const TextStyle bodyStrong = TextStyle(
    fontSize: 15,
    height: 22 / 15,
    fontWeight: FontWeight.w600,
    color: BlueColors.textPrimary,
  );

  /// Secondary/supporting copy under a title or beside a primary value.
  static const TextStyle supporting = TextStyle(
    fontSize: 13,
    height: 18 / 13,
    fontWeight: FontWeight.w400,
    color: BlueColors.textSecondary,
  );

  /// Small uppercase-tracking eyebrow/status label.
  static const TextStyle label = TextStyle(
    fontSize: 12,
    height: 16 / 12,
    fontWeight: FontWeight.w600,
    letterSpacing: 0.4,
    color: BlueColors.textSecondary,
  );

  /// Button label text.
  static const TextStyle button = TextStyle(
    fontSize: 15,
    height: 20 / 15,
    fontWeight: FontWeight.w600,
    letterSpacing: 0.1,
  );

  /// Prominent monetary value (order totals, contract price).
  static const TextStyle money = TextStyle(
    fontSize: 22,
    height: 28 / 22,
    fontWeight: FontWeight.w700,
    color: BlueColors.textPrimary,
    fontFeatures: [FontFeature.tabularFigures()],
  );

  /// Inline monetary value (line items, list rows).
  static const TextStyle moneyInline = TextStyle(
    fontSize: 15,
    height: 20 / 15,
    fontWeight: FontWeight.w600,
    color: BlueColors.textPrimary,
    fontFeatures: [FontFeature.tabularFigures()],
  );

  /// Field helper/error text.
  static const TextStyle caption = TextStyle(
    fontSize: 12,
    height: 16 / 12,
    fontWeight: FontWeight.w500,
    color: BlueColors.textSecondary,
  );

  static const TextStyle error = TextStyle(
    fontSize: 12,
    height: 16 / 12,
    fontWeight: FontWeight.w500,
    color: BlueColors.error,
  );

  /// Builds a full [TextTheme] so standard Material widgets (AppBar,
  /// ListTile, default `Text` inheritance) also resolve to this scale
  /// rather than Material's stock defaults.
  static TextTheme get textTheme => const TextTheme(
    displayLarge: display,
    headlineMedium: pageTitle,
    titleLarge: sectionTitle,
    titleMedium: cardTitle,
    bodyLarge: body,
    bodyMedium: body,
    bodySmall: supporting,
    labelLarge: button,
    labelMedium: label,
    labelSmall: caption,
  );
}
