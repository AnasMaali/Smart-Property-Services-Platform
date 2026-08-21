import 'package:flutter/material.dart';

/// The BLUE corner-radius scale. A small, coherent system so the app never
/// accumulates one-off radii across buttons/cards/sheets.
abstract final class BlueRadii {
  static const double small = 8;
  static const double medium = 12;
  static const double large = 16;
  static const double xl = 24;

  /// Fully round (pill) - chips, badges, small circular controls.
  static const double pill = 999;

  static const BorderRadius smallRadius = BorderRadius.all(
    Radius.circular(small),
  );
  static const BorderRadius mediumRadius = BorderRadius.all(
    Radius.circular(medium),
  );
  static const BorderRadius largeRadius = BorderRadius.all(
    Radius.circular(large),
  );
  static const BorderRadius xlRadius = BorderRadius.all(Radius.circular(xl));
  static const BorderRadius pillRadius = BorderRadius.all(
    Radius.circular(pill),
  );

  /// Top-only large radius, for bottom/modal sheets.
  static const BorderRadius sheetTopRadius = BorderRadius.vertical(
    top: Radius.circular(xl),
  );
}
