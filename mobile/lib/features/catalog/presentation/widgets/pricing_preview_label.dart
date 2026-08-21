import 'package:flutter/material.dart';

import '../../../../core/design/tokens/blue_colors.dart';
import '../../../../core/design/tokens/blue_typography.dart';
import '../../data/catalog_models.dart';

/// Renders a [PricingPreview] the way every service/category card should -
/// a real amount when [PricingStatus.priced], and an honest,
/// non-alarming label for every other state. Never shown as an error even
/// though [PricingStatus.missingContext]/[PricingStatus.unavailable] sound
/// negative - at the browse stage these are normal (blueprint §6: "render
/// as depends on your location/appointment, never as an error").
class PricingPreviewLabel extends StatelessWidget {
  const PricingPreviewLabel({super.key, required this.preview, this.style});

  final PricingPreview preview;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    switch (preview.status) {
      case PricingStatus.priced:
        return Text(
          'From ${preview.amount!.display}',
          style: style ?? BlueTypography.moneyInline,
        );
      case PricingStatus.quoteRequired:
        return Text(
          'Get a quote',
          style: (style ?? BlueTypography.bodyStrong).copyWith(
            color: BlueColors.brandAccent,
          ),
        );
      case PricingStatus.missingContext:
        return Text(
          'Depends on your location',
          style: (style ?? BlueTypography.supporting).copyWith(
            color: BlueColors.textTertiary,
          ),
        );
      case PricingStatus.unavailable:
        return Text(
          'Pricing unavailable',
          style: (style ?? BlueTypography.supporting).copyWith(
            color: BlueColors.textTertiary,
          ),
        );
    }
  }
}
