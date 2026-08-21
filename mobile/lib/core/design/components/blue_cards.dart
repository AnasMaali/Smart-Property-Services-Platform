import 'package:flutter/material.dart';

import '../../money/money.dart';
import '../tokens/blue_colors.dart';
import '../tokens/blue_radii.dart';
import '../tokens/blue_spacing.dart';
import '../tokens/blue_typography.dart';

/// The base tappable/non-tappable card shell every domain card (service,
/// category, cart item, property, booking, contract...) builds on, so
/// padding/radius/border/tap-feedback stay identical everywhere.
class BlueCard extends StatelessWidget {
  const BlueCard({
    super.key,
    required this.child,
    this.onTap,
    this.padding = const EdgeInsets.all(BlueSpacing.cardPadding),
    this.selected = false,
  });

  final Widget child;
  final VoidCallback? onTap;
  final EdgeInsets padding;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final content = Container(
      padding: padding,
      decoration: BoxDecoration(
        color: BlueColors.surface,
        borderRadius: BlueRadii.largeRadius,
        border: Border.all(
          color: selected ? BlueColors.brandPrimary : BlueColors.borderSubtle,
          width: selected ? 1.5 : 1,
        ),
      ),
      child: child,
    );

    if (onTap == null) return content;

    return Material(
      color: Colors.transparent,
      borderRadius: BlueRadii.largeRadius,
      child: InkWell(
        onTap: onTap,
        borderRadius: BlueRadii.largeRadius,
        child: content,
      ),
    );
  }
}

/// A label/value row for a pricing breakdown line
/// (e.g. "Base price" ... "AED 120.00"). [emphasized] renders both sides
/// bolder/larger for a grand-total row.
class MoneyRow extends StatelessWidget {
  const MoneyRow({
    super.key,
    required this.label,
    required this.amount,
    this.emphasized = false,
  });

  final String label;
  final Money amount;
  final bool emphasized;

  @override
  Widget build(BuildContext context) {
    final labelStyle = emphasized
        ? BlueTypography.bodyStrong
        : BlueTypography.body;
    final amountStyle = emphasized
        ? BlueTypography.money
        : BlueTypography.moneyInline;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: labelStyle.copyWith(
                color: emphasized
                    ? BlueColors.textPrimary
                    : BlueColors.textSecondary,
              ),
            ),
          ),
          Text(amount.display, style: amountStyle),
        ],
      ),
    );
  }
}

/// A rounded thumbnail placeholder for a service/category image slot.
/// Renders a neutral icon placeholder when no image is available, rather
/// than an empty gray box, so the layout always reads as "media area" not
/// "broken image."
class MediaThumbnail extends StatelessWidget {
  const MediaThumbnail({
    super.key,
    this.imageProvider,
    this.size = 56,
    this.icon = Icons.home_repair_service_rounded,
    this.borderRadius = BlueRadii.mediumRadius,
  });

  final ImageProvider? imageProvider;
  final double size;
  final IconData icon;
  final BorderRadius borderRadius;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: BlueColors.surfaceBrandTint,
        borderRadius: borderRadius,
        image: imageProvider == null
            ? null
            : DecorationImage(image: imageProvider!, fit: BoxFit.cover),
      ),
      child: imageProvider == null
          ? Icon(icon, color: BlueColors.brandAccentStrong, size: size * 0.42)
          : null,
    );
  }
}
