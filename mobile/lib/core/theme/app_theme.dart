import 'package:flutter/material.dart';

/// A modest, deliberately replaceable Material 3 theme foundation
/// (blueprint §16 target - no dark theme, no typography scale, no design
/// tokens yet).
///
/// No official BLUE brand color/logo/style guide exists anywhere in the
/// repository as of F1 (confirmed by a repo-wide search) - the seed color
/// below is a provisional placeholder only, expected to be replaced once an
/// official brand guide exists. Do not build on top of this as if it were a
/// final design system.
class AppTheme {
  AppTheme._();

  static const _placeholderSeedColor = Color(0xFF1565C0);

  static ThemeData get light {
    return ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(seedColor: _placeholderSeedColor),
    );
  }
}
