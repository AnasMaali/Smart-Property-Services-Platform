import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/components/blue_feedback.dart';
import '../../../core/design/components/blue_fields.dart';
import '../../../core/design/components/blue_layout.dart';
import '../../../core/design/components/blue_selection.dart';
import '../../../core/design/tokens/blue_colors.dart';
import '../../../core/design/tokens/blue_spacing.dart';
import '../../../core/design/tokens/blue_typography.dart';
import '../data/catalog_fixtures.dart';
import '../data/catalog_models.dart';
import 'widgets/pricing_preview_label.dart';

/// `GET /v1/services/{service}` (blueprint §5/§6) - full detail, option
/// configuration, and Add to Cart. Every option type from the contract
/// (TEXT/NUMBER/BOOLEAN/SINGLE_SELECT/MULTI_SELECT) is rendered here;
/// required options block Add to Cart until filled, matching the
/// server-side validation `POST /v1/cart/items` will also enforce.
class ServiceDetailScreen extends StatefulWidget {
  const ServiceDetailScreen({super.key, required this.slug});

  final String slug;

  @override
  State<ServiceDetailScreen> createState() => _ServiceDetailScreenState();
}

class _ServiceDetailScreenState extends State<ServiceDetailScreen> {
  final Map<String, Object?> _values = {};

  @override
  Widget build(BuildContext context) {
    final detail = PreviewCatalog.serviceDetails[widget.slug];

    if (detail == null) {
      return AppScaffold(
        appBar: AppBar(),
        body: ErrorStateView(
          title: 'Service not found',
          message: 'This service may no longer be available.',
          icon: Icons.home_repair_service_outlined,
          onRetry: () => context.pop(),
        ),
      );
    }

    final missingRequired = detail.options
        .where((o) => o.isRequired)
        .where((o) => _values[o.uuid] == null)
        .toList();
    final canAdd = missingRequired.isEmpty;

    return AppScaffold(
      appBar: AppBar(title: Text(detail.name)),
      bottomBar: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('From', style: BlueTypography.caption),
                PricingPreviewLabel(
                  preview: detail.pricingPreview,
                  style: BlueTypography.money,
                ),
              ],
            ),
          ),
          const SizedBox(width: BlueSpacing.space16),
          Expanded(
            flex: 2,
            child: FilledButton(
              onPressed: canAdd
                  ? () {
                      showBlueSnackBar(
                        context,
                        message: '${detail.name} added to cart.',
                        tone: BlueSnackBarTone.success,
                      );
                    }
                  : null,
              child: const Text('Add to cart'),
            ),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.symmetric(
          horizontal: BlueSpacing.pageGutter,
          vertical: BlueSpacing.space16,
        ),
        children: [
          Container(
            height: 160,
            decoration: BoxDecoration(
              color: BlueColors.surfaceBrandTint,
              borderRadius: BorderRadius.circular(20),
            ),
            alignment: Alignment.center,
            child: const Icon(
              Icons.home_repair_service_rounded,
              size: 56,
              color: BlueColors.brandAccentStrong,
            ),
          ),
          const SizedBox(height: BlueSpacing.space20),
          Text(detail.category.name, style: BlueTypography.label),
          const SizedBox(height: 4),
          Text(detail.name, style: BlueTypography.pageTitle),
          const SizedBox(height: BlueSpacing.space8),
          Text(detail.description, style: BlueTypography.body),
          const SizedBox(height: BlueSpacing.sectionGap),
          if (detail.options.isNotEmpty) ...[
            Text('Configure your service', style: BlueTypography.sectionTitle),
            const SizedBox(height: BlueSpacing.space12),
            for (final option in detail.options) ...[
              _OptionField(
                option: option,
                value: _values[option.uuid],
                onChanged: (value) =>
                    setState(() => _values[option.uuid] = value),
              ),
              const SizedBox(height: BlueSpacing.space20),
            ],
          ],
        ],
      ),
    );
  }
}

class _OptionField extends StatelessWidget {
  const _OptionField({
    required this.option,
    required this.value,
    required this.onChanged,
  });

  final ServiceOption option;
  final Object? value;
  final ValueChanged<Object?> onChanged;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _OptionLabel(option: option),
        const SizedBox(height: BlueSpacing.space8),
        _buildControl(context),
      ],
    );
  }

  Widget _buildControl(BuildContext context) {
    switch (option.type) {
      case ServiceOptionType.text:
        return BlueTextField(
          hint: option.description,
          isMultiline: true,
          onChanged: (v) => onChanged(v.isEmpty ? null : v),
        );
      case ServiceOptionType.number:
        final rule = option.numericRule!;
        final current = (value as int?) ?? rule.defaultValue.toInt();
        return QuantitySelector(
          quantity: current,
          min: rule.minValue.toInt(),
          max: rule.maxValue.toInt(),
          onChanged: (v) => onChanged(v),
        );
      case ServiceOptionType.boolean:
        final selected = (value as bool?) ?? false;
        return MultiSelectOptionCard(
          title: option.name,
          subtitle: option.description,
          selected: selected,
          onTap: () => onChanged(!selected),
        );
      case ServiceOptionType.singleSelect:
        return Column(
          children: [
            for (final choice in option.choices) ...[
              SingleSelectOptionCard(
                title: choice.name,
                selected: value == choice.id,
                onTap: () => onChanged(choice.id),
              ),
              if (choice != option.choices.last)
                const SizedBox(height: BlueSpacing.space8),
            ],
          ],
        );
      case ServiceOptionType.multiSelect:
        final selected = (value as Set<int>?) ?? <int>{};
        return Column(
          children: [
            for (final choice in option.choices) ...[
              MultiSelectOptionCard(
                title: choice.name,
                selected: selected.contains(choice.id),
                onTap: () {
                  final next = Set<int>.from(selected);
                  selected.contains(choice.id)
                      ? next.remove(choice.id)
                      : next.add(choice.id);
                  onChanged(next);
                },
              ),
              if (choice != option.choices.last)
                const SizedBox(height: BlueSpacing.space8),
            ],
          ],
        );
    }
  }
}

class _OptionLabel extends StatelessWidget {
  const _OptionLabel({required this.option});

  final ServiceOption option;

  @override
  Widget build(BuildContext context) {
    return RichText(
      text: TextSpan(
        style: BlueTypography.bodyStrong,
        children: [
          TextSpan(text: option.name),
          if (option.isRequired)
            const TextSpan(
              text: ' *',
              style: TextStyle(color: BlueColors.error),
            ),
          if (!option.isRequired)
            TextSpan(
              text: '  (optional)',
              style: BlueTypography.caption.copyWith(
                color: BlueColors.textTertiary,
              ),
            ),
        ],
      ),
    );
  }
}

@Preview(name: 'Service Detail', group: 'Services', size: Size(390, 844))
Widget serviceDetailScreenPreview() {
  return const ServiceDetailScreen(slug: 'ac-deep-clean');
}

@Preview(
  name: 'Service Detail - large phone',
  group: 'Services',
  size: Size(430, 932),
)
Widget serviceDetailScreenLargePreview() {
  return const ServiceDetailScreen(slug: 'ac-deep-clean');
}

@Preview(
  name: 'Service Detail - not found',
  group: 'Services',
  size: Size(390, 844),
)
Widget serviceDetailScreenNotFoundPreview() {
  return const ServiceDetailScreen(slug: 'unknown-slug');
}
