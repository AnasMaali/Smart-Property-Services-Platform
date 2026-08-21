import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';

import '../../money/money.dart';
import '../components/blue_buttons.dart';
import '../components/blue_cards.dart';
import '../components/blue_feedback.dart';
import '../components/blue_fields.dart';
import '../components/blue_layout.dart';
import '../components/blue_selection.dart';
import '../components/blue_status.dart';
import '../tokens/blue_colors.dart';
import '../tokens/blue_radii.dart';
import '../tokens/blue_spacing.dart';
import '../tokens/blue_typography.dart';

/// A living reference of every design-system foundation and component,
/// used during development to visually verify tokens/components in
/// isolation before they're composed into real screens. Not a
/// customer-facing route.
class DesignSystemGalleryScreen extends StatefulWidget {
  const DesignSystemGalleryScreen({super.key});

  @override
  State<DesignSystemGalleryScreen> createState() =>
      _DesignSystemGalleryScreenState();
}

class _DesignSystemGalleryScreenState extends State<DesignSystemGalleryScreen> {
  bool _switchValue = true;
  bool _checkValue = true;
  int _quantity = 2;
  int _segment = 0;
  String? _fieldError;

  @override
  Widget build(BuildContext context) {
    return AppScaffold(
      appBar: AppBar(title: const Text('BLUE Design System')),
      body: ListView(
        padding: const EdgeInsets.symmetric(
          horizontal: BlueSpacing.pageGutter,
          vertical: BlueSpacing.space16,
        ),
        children: [
          _GallerySection(
            title: 'Brand palette',
            child: Column(
              children: [
                Row(
                  children: [
                    _Swatch('Deepest Indigo', BlueColors.brandPrimaryDark),
                    _Swatch('Brand Indigo', BlueColors.brandPrimaryStrong),
                    _Swatch('Primary Indigo', BlueColors.brandPrimary),
                    _Swatch('Soft Violet', BlueColors.brandPrimarySoft),
                  ],
                ),
                const SizedBox(height: BlueSpacing.space8),
                Row(
                  children: [
                    _Swatch('Deep Blue', BlueColors.brandAccentStrong),
                    _Swatch('Brand Blue', BlueColors.brandAccent),
                    _Swatch('Bright Blue', BlueColors.brandAccentBright),
                    _Swatch(
                      'Soft Blue',
                      BlueColors.brandAccentSoft,
                      dark: false,
                    ),
                  ],
                ),
                const SizedBox(height: BlueSpacing.space8),
                Row(
                  children: [
                    _Swatch('Success', BlueColors.success),
                    _Swatch('Warning', BlueColors.warning),
                    _Swatch('Error', BlueColors.error),
                    _Swatch('Info', BlueColors.info),
                  ],
                ),
              ],
            ),
          ),
          _GallerySection(
            title: 'Gradients',
            child: Column(
              children: [
                Container(
                  height: 64,
                  decoration: BoxDecoration(
                    gradient: BlueColors.brandGoldGradient,
                    borderRadius: BlueRadii.mediumRadius,
                  ),
                  alignment: Alignment.center,
                  child: const Text(
                    'brandGoldGradient',
                    style: TextStyle(
                      color: BlueColors.textPrimary,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
                const SizedBox(height: BlueSpacing.space8),
                Container(
                  height: 64,
                  decoration: BoxDecoration(
                    gradient: BlueColors.brandPrimaryGradient,
                    borderRadius: BlueRadii.mediumRadius,
                  ),
                  alignment: Alignment.center,
                  child: const Text(
                    'brandPrimaryGradient',
                    style: TextStyle(
                      color: BlueColors.textInverse,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ],
            ),
          ),
          _GallerySection(
            title: 'Typography',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: const [
                Text('Display', style: BlueTypography.display),
                Text('Page title', style: BlueTypography.pageTitle),
                Text('Section title', style: BlueTypography.sectionTitle),
                Text('Card title', style: BlueTypography.cardTitle),
                Text(
                  'Body copy for standard reading text.',
                  style: BlueTypography.body,
                ),
                Text('Body strong emphasis.', style: BlueTypography.bodyStrong),
                Text(
                  'Supporting / secondary text.',
                  style: BlueTypography.supporting,
                ),
                Text('EYEBROW LABEL', style: BlueTypography.label),
                Text('AED 120.00', style: BlueTypography.money),
                Text('Error helper text.', style: BlueTypography.error),
              ],
            ),
          ),
          _GallerySection(
            title: 'Buttons',
            child: Column(
              children: [
                PrimaryButton(label: 'Primary action', onPressed: () {}),
                const SizedBox(height: BlueSpacing.space8),
                const PrimaryButton(
                  label: 'Loading',
                  onPressed: null,
                  isLoading: true,
                ),
                const SizedBox(height: BlueSpacing.space8),
                const PrimaryButton(label: 'Disabled', onPressed: null),
                const SizedBox(height: BlueSpacing.space8),
                SecondaryButton(label: 'Secondary action', onPressed: () {}),
                const SizedBox(height: BlueSpacing.space8),
                DestructiveButton(label: 'Delete account', onPressed: () {}),
                const SizedBox(height: BlueSpacing.space8),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    TertiaryButton(label: 'Skip', onPressed: () {}),
                    DestructiveTextButton(label: 'Remove', onPressed: () {}),
                    BlueIconButton(
                      icon: Icons.favorite_border_rounded,
                      semanticLabel: 'Save',
                      onPressed: () {},
                    ),
                  ],
                ),
              ],
            ),
          ),
          _GallerySection(
            title: 'Fields',
            child: Column(
              children: [
                const BlueTextField(label: 'Full name', hint: 'Layla Hassan'),
                const SizedBox(height: BlueSpacing.space16),
                BlueTextField(
                  label: 'Phone number',
                  hint: '+971 5X XXX XXXX',
                  keyboardType: TextInputType.phone,
                  prefixIcon: Icons.call_outlined,
                  errorText: _fieldError,
                  onChanged: (v) => setState(
                    () => _fieldError = v.isEmpty
                        ? 'Phone number is required'
                        : null,
                  ),
                ),
                const SizedBox(height: BlueSpacing.space16),
                const BlueTextField(label: 'Password', isPassword: true),
                const SizedBox(height: BlueSpacing.space16),
                BlueTextField.search(hint: 'Search services'),
                const SizedBox(height: BlueSpacing.space16),
                OtpCodeField(onCompleted: (_) {}),
              ],
            ),
          ),
          _GallerySection(
            title: 'Selection',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: BlueSpacing.space8,
                  children: [
                    BlueChoiceChip(label: 'AC', selected: true, onTap: () {}),
                    BlueChoiceChip(
                      label: 'Cleaning',
                      selected: false,
                      onTap: () {},
                    ),
                    BlueChoiceChip(
                      label: 'Pest control',
                      selected: false,
                      onTap: () {},
                    ),
                  ],
                ),
                const SizedBox(height: BlueSpacing.space16),
                SingleSelectOptionCard(
                  title: 'Standard hours',
                  subtitle: '8:00 AM - 6:00 PM',
                  selected: true,
                  onTap: () {},
                ),
                const SizedBox(height: BlueSpacing.space8),
                MultiSelectOptionCard(
                  title: 'Include balcony',
                  selected: _checkValue,
                  onTap: () => setState(() => _checkValue = !_checkValue),
                ),
                const SizedBox(height: BlueSpacing.space16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    QuantitySelector(
                      quantity: _quantity,
                      onChanged: (v) => setState(() => _quantity = v),
                    ),
                    Switch(
                      value: _switchValue,
                      onChanged: (v) => setState(() => _switchValue = v),
                    ),
                  ],
                ),
                const SizedBox(height: BlueSpacing.space16),
                BlueSegmentedControl<int>(
                  segments: const [
                    (value: 0, label: 'Active'),
                    (value: 1, label: 'Archived'),
                    (value: 2, label: 'All'),
                  ],
                  selected: _segment,
                  onChanged: (v) => setState(() => _segment = v),
                ),
              ],
            ),
          ),
          _GallerySection(
            title: 'Cards',
            child: BlueCard(
              child: Row(
                children: [
                  const MediaThumbnail(),
                  const SizedBox(width: BlueSpacing.space12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: const [
                        Text(
                          'AC Deep Cleaning',
                          style: BlueTypography.cardTitle,
                        ),
                        SizedBox(height: 4),
                        Text(
                          'Split unit · from',
                          style: BlueTypography.supporting,
                        ),
                      ],
                    ),
                  ),
                  Text('AED 120.00', style: BlueTypography.moneyInline),
                ],
              ),
            ),
          ),
          _GallerySection(
            title: 'Money',
            child: BlueCard(
              child: Column(
                children: [
                  MoneyRow(
                    label: 'Base price',
                    amount: const Money('120.000000', Currency.aed),
                  ),
                  MoneyRow(
                    label: 'Weekend surcharge',
                    amount: const Money('15.000000', Currency.aed),
                  ),
                  const BlueDivider(),
                  MoneyRow(
                    label: 'Total',
                    amount: const Money('135.000000', Currency.aed),
                    emphasized: true,
                  ),
                ],
              ),
            ),
          ),
          _GallerySection(
            title: 'Status',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: BlueSpacing.space8,
                  runSpacing: BlueSpacing.space8,
                  children: const [
                    StatusBadge(
                      label: 'Active',
                      tone: BlueTone.success,
                      icon: Icons.check_circle,
                    ),
                    StatusBadge(
                      label: 'Waiting for review',
                      tone: BlueTone.neutral,
                    ),
                    StatusBadge(
                      label: 'Payment issue',
                      tone: BlueTone.warning,
                      icon: Icons.error_outline,
                    ),
                    StatusBadge(label: 'Cancelled', tone: BlueTone.error),
                    StatusBadge(label: 'Info', tone: BlueTone.info),
                  ],
                ),
                const SizedBox(height: BlueSpacing.space16),
                const BlueBannerPanel(
                  tone: BlueTone.warning,
                  title: 'Payment issue',
                  message: 'Your last recurring charge failed. Update billing to keep booking covered visits.',
                ),
                const SizedBox(height: BlueSpacing.space16),
                const BlueTimeline(
                  steps: [
                    TimelineStep(
                      label: 'Requested',
                      state: TimelineStepState.done,
                    ),
                    TimelineStep(
                      label: 'Approved',
                      state: TimelineStepState.done,
                    ),
                    TimelineStep(
                      label: 'Awaiting your acceptance',
                      caption: 'Review the terms and accept to continue',
                      state: TimelineStepState.current,
                    ),
                    TimelineStep(
                      label: 'Active',
                      state: TimelineStepState.upcoming,
                    ),
                  ],
                ),
              ],
            ),
          ),
          _GallerySection(
            title: 'Loading',
            child: const Column(
              children: [
                SkeletonListTile(),
                SizedBox(height: BlueSpacing.space8),
                InlineLoadingRow(label: 'Loading services...'),
              ],
            ),
          ),
          _GallerySection(
            title: 'Empty & error states',
            child: SizedBox(
              height: 220,
              child: EmptyStateView(
                icon: Icons.shopping_bag_outlined,
                title: 'Your cart is empty',
                message: 'Browse services to get started.',
                actionLabel: 'Browse services',
                onAction: () {},
              ),
            ),
          ),
          _GallerySection(
            title: 'Dialogs & sheets',
            child: Row(
              children: [
                Expanded(
                  child: SecondaryButton(
                    label: 'Show dialog',
                    onPressed: () => showBlueConfirmationDialog(
                      context,
                      title: 'Cancel booking?',
                      message: 'This cannot be undone.',
                      confirmLabel: 'Cancel booking',
                      isDestructive: true,
                    ),
                  ),
                ),
                const SizedBox(width: BlueSpacing.space12),
                Expanded(
                  child: SecondaryButton(
                    label: 'Show sheet',
                    onPressed: () => showBlueModalSheet<void>(
                      context,
                      builder: (context) => const Padding(
                        padding: EdgeInsets.all(BlueSpacing.space24),
                        child: Text(
                          'Modal sheet content',
                          style: BlueTypography.body,
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: BlueSpacing.space32),
        ],
      ),
    );
  }
}

class _GallerySection extends StatelessWidget {
  const _GallerySection({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: BlueSpacing.sectionGap),
      child: SectionContainer(title: title, child: child),
    );
  }
}

class _Swatch extends StatelessWidget {
  const _Swatch(this.label, this.color, {this.dark = true});

  final String label;
  final Color color;
  final bool dark;

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 2),
        child: Column(
          children: [
            Container(
              height: 40,
              decoration: BoxDecoration(
                color: color,
                borderRadius: BlueRadii.smallRadius,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              label,
              style: BlueTypography.caption,
              textAlign: TextAlign.center,
              maxLines: 2,
            ),
          ],
        ),
      ),
    );
  }
}

@Preview(
  name: 'Design System Gallery',
  group: 'Design System',
  size: Size(390, 844),
)
Widget designSystemGalleryPreview() {
  return const DesignSystemGalleryScreen();
}
