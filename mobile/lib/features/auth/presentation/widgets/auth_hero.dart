import 'package:flutter/material.dart';

import '../../../../core/design/tokens/blue_colors.dart';
import '../../../../core/design/tokens/blue_spacing.dart';
import '../../../../core/design/tokens/blue_typography.dart';

/// The one branded gradient hero moment reused by Splash and Welcome -
/// deliberately not repeated on every auth screen (Login/Register/OTP/etc.
/// stay on a plain light background) so the brand gradient keeps its
/// impact. Renders the temporary "BLUE" text wordmark - the final logo
/// mark isn't decided yet, and this widget is the only place that needs
/// to change once it is.
class AuthHero extends StatelessWidget {
  const AuthHero({
    super.key,
    this.tagline,
    this.height = 260,
    this.compact = false,
  });

  final String? tagline;
  final double height;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      height: height,
      decoration: const BoxDecoration(
        gradient: BlueColors.brandPrimaryGradient,
      ),
      child: Stack(
        children: [
          Positioned(
            right: -40,
            top: -40,
            child: _GeometricAccent(size: 160, opacity: 0.10),
          ),
          Positioned(
            left: -30,
            bottom: -50,
            child: _GeometricAccent(size: 140, opacity: 0.08),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: BlueSpacing.space32,
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(
                  'BLUE',
                  style: TextStyle(
                    color: BlueColors.textInverse,
                    fontSize: compact ? 32 : 44,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 6,
                  ),
                ),
                if (tagline != null) ...[
                  const SizedBox(height: BlueSpacing.space12),
                  Text(
                    tagline!,
                    textAlign: TextAlign.center,
                    style: BlueTypography.body.copyWith(
                      color: BlueColors.textInverseSecondary,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// A restrained geometric decoration - a soft rotated square - echoing the
/// brand's geometric character without depending on the current logo's
/// exact shape.
class _GeometricAccent extends StatelessWidget {
  const _GeometricAccent({required this.size, required this.opacity});

  final double size;
  final double opacity;

  @override
  Widget build(BuildContext context) {
    return Transform.rotate(
      angle: 0.6,
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          color: BlueColors.textInverse.withValues(alpha: opacity),
          borderRadius: BorderRadius.circular(size * 0.28),
        ),
      ),
    );
  }
}
