import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/components/blue_cards.dart';
import '../../../core/design/components/blue_feedback.dart';
import '../../../core/design/components/blue_layout.dart';
import '../../../core/design/tokens/blue_colors.dart';
import '../../../core/design/tokens/blue_spacing.dart';
import '../../../core/design/tokens/blue_typography.dart';
import '../data/catalog_fixtures.dart';
import '../data/catalog_models.dart';
import 'widgets/pricing_preview_label.dart';

/// `GET /v1/service-categories/{category}/services` (blueprint §5/§6). A
/// 404 here (category no longer exists/inactive) pops back to Home rather
/// than showing a dead-end error screen, per the blueprint's guidance.
class CategoryServicesScreen extends StatelessWidget {
  const CategoryServicesScreen({super.key, required this.categoryId});

  final int categoryId;

  @override
  Widget build(BuildContext context) {
    ServiceCategory? category;
    for (final candidate in PreviewCatalog.categories) {
      if (candidate.id == categoryId) {
        category = candidate;
        break;
      }
    }

    if (category == null) {
      return AppScaffold(
        appBar: AppBar(),
        body: ErrorStateView(
          title: 'Category not found',
          message: 'This category may no longer be available.',
          icon: Icons.category_outlined,
          onRetry: () => context.pop(),
        ),
      );
    }

    final services = PreviewCatalog.servicesByCategory[categoryId] ?? const [];

    return AppScaffold(
      appBar: AppBar(title: Text(category.name)),
      body: services.isEmpty
          ? EmptyStateView(
              icon: category.icon,
              title: 'No services yet',
              message:
                  'There are no ${category.name.toLowerCase()} services available right now.',
            )
          : ListView(
              padding: const EdgeInsets.symmetric(
                horizontal: BlueSpacing.pageGutter,
                vertical: BlueSpacing.space16,
              ),
              children: [
                Text(category.description, style: BlueTypography.supporting),
                const SizedBox(height: BlueSpacing.sectionGap),
                for (final service in services) ...[
                  _ServiceRow(
                    service: service,
                    onTap: () => context.pushNamed(
                      'serviceDetail',
                      pathParameters: {'slug': service.slug},
                    ),
                  ),
                  const SizedBox(height: BlueSpacing.space12),
                ],
              ],
            ),
    );
  }
}

class _ServiceRow extends StatelessWidget {
  const _ServiceRow({required this.service, required this.onTap});

  final ServiceSummary service;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return BlueCard(
      onTap: onTap,
      child: Row(
        children: [
          const MediaThumbnail(),
          const SizedBox(width: BlueSpacing.space12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(service.name, style: BlueTypography.cardTitle),
                const SizedBox(height: 2),
                Text(
                  service.shortDescription,
                  style: BlueTypography.supporting,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: BlueSpacing.space8),
                PricingPreviewLabel(preview: service.pricingPreview),
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

@Preview(
  name: 'Category Services - AC',
  group: 'Services',
  size: Size(390, 844),
)
Widget categoryServicesScreenPreview() {
  return const CategoryServicesScreen(categoryId: 1);
}

@Preview(
  name: 'Category Services - empty',
  group: 'Services',
  size: Size(390, 844),
)
Widget categoryServicesScreenEmptyPreview() {
  return const CategoryServicesScreen(categoryId: 999);
}
