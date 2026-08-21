import 'package:flutter/material.dart';

import 'blue_colors.dart';

/// Depth is used sparingly in BLUE - surface contrast, borders, and
/// spacing come first; shadow is the last resort, and never a giant
/// floating-card blur. Three tiers cover every real elevation need in the
/// product.
abstract final class BlueElevation {
  /// Resting cards/rows that sit on [BlueColors.background] and are
  /// already separated by a border - no shadow needed.
  static const List<BoxShadow> none = [];

  /// A card that needs to visually lift slightly off the page (e.g. a
  /// pricing summary card, a selected option card).
  static List<BoxShadow> get low => [
    BoxShadow(
      color: BlueColors.textPrimary.withValues(alpha: 0.06),
      blurRadius: 8,
      offset: const Offset(0, 2),
    ),
  ];

  /// Sheets, dialogs, and floating action affordances.
  static List<BoxShadow> get high => [
    BoxShadow(
      color: BlueColors.textPrimary.withValues(alpha: 0.1),
      blurRadius: 20,
      offset: const Offset(0, 8),
    ),
  ];
}
