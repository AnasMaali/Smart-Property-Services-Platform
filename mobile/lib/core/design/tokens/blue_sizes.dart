/// Icon and control size tokens. Touch targets follow the larger of
/// Apple HIG (44x44pt) and Material (48x48dp) minimums so both platforms
/// clear their own accessibility bar with the same values.
abstract final class BlueSizes {
  // Icon sizes.
  static const double iconSmall = 16;
  static const double iconMedium = 20;
  static const double iconLarge = 24;
  static const double iconXL = 32;

  // Minimum interactive touch target (applies to icon buttons, checkboxes,
  // small chips-with-close-affordance, etc.).
  static const double minTouchTarget = 48;

  // Standard control heights.
  static const double controlHeightSmall = 36;
  static const double controlHeightMedium = 44;
  static const double controlHeightLarge = 52;

  // Bottom navigation bar content height (excludes safe-area inset, which
  // is added on top by the scaffold).
  static const double bottomNavHeight = 64;

  // App bar height.
  static const double appBarHeight = 56;
}
