import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/components/blue_cards.dart';
import '../../../core/design/components/blue_layout.dart';
import '../../../core/design/tokens/blue_colors.dart';
import '../../../core/design/tokens/blue_sizes.dart';
import '../../../core/design/tokens/blue_spacing.dart';
import '../../../core/design/tokens/blue_typography.dart';
import '../../catalog/data/catalog_fixtures.dart';
import '../../catalog/data/catalog_models.dart';

/// `GET /v1/service-categories` (blueprint §5/§6). Home's only job is to
/// get the customer to the right category in one tap - no invented
/// promotions, ratings, or recommendations, since none of that exists in
/// the backend today.
class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key, this.customerFirstName = 'Layla'});

  final String customerFirstName;

  @override
  Widget build(BuildContext context) {
    return AppScaffold(
      body: ListView(
        padding: const EdgeInsets.symmetric(
          horizontal: BlueSpacing.pageGutter,
          vertical: BlueSpacing.space16,
        ),
        children: [
          Text('Good day, $customerFirstName', style: BlueTypography.pageTitle),
          const SizedBox(height: BlueSpacing.space4),
          Text(
            'What does your home need today?',
            style: BlueTypography.supporting,
          ),
          const SizedBox(height: BlueSpacing.sectionGap),
          Text('Browse services', style: BlueTypography.sectionTitle),
          const SizedBox(height: BlueSpacing.space12),
          for (final category in PreviewCatalog.categories) ...[
            _CategoryRow(
              category: category,
              onTap: () => context.pushNamed(
                'categoryServices',
                pathParameters: {'categoryId': '${category.id}'},
              ),
            ),
            const SizedBox(height: BlueSpacing.space12),
          ],
        ],
      ),
    );
  }
}

class _CategoryRow extends StatelessWidget {
  const _CategoryRow({required this.category, required this.onTap});

  final ServiceCategory category;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return BlueCard(
      onTap: onTap,
      child: Row(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: const BoxDecoration(
              color: BlueColors.surfaceBrandTint,
              shape: BoxShape.circle,
            ),
            child: Icon(
              category.icon,
              color: BlueColors.brandAccentStrong,
              size: BlueSizes.iconLarge,
            ),
          ),
          const SizedBox(width: BlueSpacing.space12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(category.name, style: BlueTypography.cardTitle),
                const SizedBox(height: 2),
                Text(
                  category.description,
                  style: BlueTypography.supporting,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
          const Icon(
            Icons.chevron_right_rounded,
            color: BlueColors.textTertiary,
          ),
        ],
      ),
    );
  }
}

@Preview(name: 'Home', group: 'Home', size: Size(390, 844))
Widget homeScreenPreview() {
  return const HomeScreen();
}

@Preview(name: 'Home - large phone', group: 'Home', size: Size(430, 932))
Widget homeScreenLargePreview() {
  return const HomeScreen();
}

@Preview(name: 'Home - compact phone', group: 'Home', size: Size(360, 780))
Widget homeScreenCompactPreview() {
  return const HomeScreen();
}
