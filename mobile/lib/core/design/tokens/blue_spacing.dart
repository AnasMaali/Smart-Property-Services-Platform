/// The BLUE spacing scale. Every gap, padding, and margin in the product
/// should resolve to one of these tokens rather than an arbitrary
/// `SizedBox(height: 13)` sprinkled ad hoc through screen code.
///
/// Base unit is 4px, which lines up cleanly with both iOS's 8pt-friendly
/// layout grid and Android's 4dp/8dp Material grid.
abstract final class BlueSpacing {
  static const double space2 = 2;
  static const double space4 = 4;
  static const double space8 = 8;
  static const double space12 = 12;
  static const double space16 = 16;
  static const double space20 = 20;
  static const double space24 = 24;
  static const double space32 = 32;
  static const double space40 = 40;
  static const double space48 = 48;

  /// Horizontal gutter for page content, applied consistently by
  /// `AppScaffold`/`PageContainer` so no screen re-derives it.
  static const double pageGutter = space16;

  /// Vertical gap between distinct sections on a screen.
  static const double sectionGap = space24;

  /// Internal padding for a standard card/container.
  static const double cardPadding = space16;

  /// Vertical gap between rows inside a list/section.
  static const double rowGap = space12;

  /// Gap between a control and its adjacent label/helper text.
  static const double controlSpacing = space8;

  /// Gap above a primary CTA when it follows body content.
  static const double ctaSpacing = space24;

  /// Extra bottom padding reserved for safe-area clearance beneath a
  /// bottom-pinned CTA or the last item in a scroll view, on top of
  /// [MediaQuery] safe-area insets.
  static const double bottomSafeSpacing = space16;
}
