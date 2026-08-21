import 'package:flutter/material.dart';

/// Restrained, purposeful motion tokens. Animation in BLUE always exists
/// to explain a state change (selection, expansion, cart update, loading,
/// success) - never gratuitous. Every duration/curve in the app should
/// come from here.
abstract final class BlueMotion {
  static const Duration quick = Duration(milliseconds: 120);
  static const Duration standard = Duration(milliseconds: 220);
  static const Duration slow = Duration(milliseconds: 360);

  static const Curve enter = Curves.easeOutCubic;
  static const Curve exit = Curves.easeInCubic;
  static const Curve emphasize = Curves.easeInOutCubic;

  /// Resolves an effective duration honoring the platform's "reduce
  /// motion" accessibility setting - returns [Duration.zero] when the
  /// user has requested reduced motion, per HIG/Material accessibility
  /// guidance. Widgets should route their animation durations through
  /// this rather than reading [MediaQuery.disableAnimations] themselves.
  static Duration resolve(BuildContext context, Duration duration) {
    final disableAnimations =
        MediaQuery.maybeOf(context)?.disableAnimations ?? false;
    return disableAnimations ? Duration.zero : duration;
  }
}
