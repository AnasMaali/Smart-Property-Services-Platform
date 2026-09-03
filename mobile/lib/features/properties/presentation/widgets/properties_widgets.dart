import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../auth/presentation/widgets/blue_sheet.dart';
import '../../../checkout/presentation/widgets/checkout_location_widgets.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../../services/presentation/widgets/services_widgets.dart';
import '../../data/property_models.dart';

const propertiesReveal = Duration(milliseconds: 220);
const propertiesAreaFade = Duration(milliseconds: 200);

const _goldLine = LinearGradient(
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

class PropertiesBackButton extends StatelessWidget {
  const PropertiesBackButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(-11, 0),
      child: ServicesBackButton(onPressed: onPressed),
    );
  }
}

class PropertiesAddAction extends StatelessWidget {
  const PropertiesAddAction({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.96,
      duration: const Duration(milliseconds: 140),
      child: const SizedBox(
        height: 44,
        width: 72,
        child: Align(
          alignment: Alignment.centerRight,
          child: Text(
            '+ Add',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 15.5,
              height: 1,
              fontWeight: FontWeight.w700,
              letterSpacing: 15.5 * -0.01,
              color: BlueColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}

class PropertiesTitle extends StatelessWidget {
  const PropertiesTitle({super.key, required this.title, this.subtitle = ''});

  final String title;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Flexible(
              child: Text(
                title,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: BlueDimens.checkoutTitle,
                  height: 1.18,
                  fontWeight: FontWeight.w700,
                  letterSpacing: BlueDimens.checkoutTitle * -0.022,
                  color: BlueColors.ink,
                ),
              ),
            ),
            const SizedBox(width: 10),
            const Padding(
              padding: EdgeInsets.only(bottom: 7),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: _goldLine,
                  borderRadius: BorderRadius.all(Radius.circular(1)),
                ),
                child: SizedBox(
                  width: BlueDimens.checkoutGoldWidth,
                  height: BlueDimens.checkoutGoldHeight,
                ),
              ),
            ),
          ],
        ),
        if (subtitle.isNotEmpty) ...[
          const SizedBox(height: 7),
          Text(
            subtitle,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13.5,
              height: 1.45,
              fontWeight: FontWeight.w500,
              color: BlueColors.muted,
            ),
          ),
        ],
      ],
    );
  }
}

class PropertiesSectionHead extends StatelessWidget {
  const PropertiesSectionHead({
    super.key,
    required this.title,
    this.optional = false,
  });

  final String title;
  final bool optional;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            title,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 15,
              height: 1.3,
              fontWeight: FontWeight.w700,
              letterSpacing: 15 * -0.01,
              color: BlueColors.ink,
            ),
          ),
        ),
        if (optional)
          DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(6),
              border: Border.all(color: BlueColors.badgeBorder),
              color: BlueColors.white,
            ),
            child: const Padding(
              padding: EdgeInsets.symmetric(horizontal: 7, vertical: 3),
              child: Text(
                'OPTIONAL',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 10,
                  height: 1,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 10 * 0.08,
                  color: BlueColors.placeholder,
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class PropertyRow extends StatefulWidget {
  const PropertyRow({
    super.key,
    required this.property,
    required this.onPressed,
    this.fadeIn = false,
  });

  final SavedProperty property;
  final VoidCallback onPressed;
  final bool fadeIn;

  @override
  State<PropertyRow> createState() => _PropertyRowState();
}

class _PropertyRowState extends State<PropertyRow> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    final property = widget.property;
    final detail = property.detailLine;
    final row = Semantics(
      button: true,
      label: property.a11y,
      excludeSemantics: true,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: (_) => setState(() => _down = true),
        onTapUp: (_) => setState(() => _down = false),
        onTapCancel: () => setState(() => _down = false),
        onTap: () {
          BlueMotion.tap();
          widget.onPressed();
        },
        child: AnimatedContainer(
          duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
          curve: BlueMotion.curve,
          constraints: const BoxConstraints(minHeight: BlueDimens.propertyRow),
          width: double.infinity,
          padding: const EdgeInsets.fromLTRB(
            BlueDimens.homeGutter,
            14,
            BlueDimens.homeGutter,
            14,
          ),
          decoration: BoxDecoration(
            color: _down ? BlueColors.press : Colors.transparent,
            border: const Border(top: BorderSide(color: BlueColors.navLine)),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              PropertyTypeTile(glyph: property.glyph),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      property.identity,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 15.5,
                        height: 1.28,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 15.5 * -0.01,
                        color: BlueColors.ink,
                      ),
                    ),
                    if (property.placeLine.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(
                        property.placeLine,
                        style: const TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 13.5,
                          height: 1.35,
                          fontWeight: FontWeight.w500,
                          color: BlueColors.ink,
                        ),
                      ),
                    ],
                    if (detail.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(
                        detail,
                        style: const TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 12.5,
                          height: 1.35,
                          fontWeight: FontWeight.w400,
                          color: BlueColors.ink,
                        ),
                      ),
                    ],
                    if (property.relationshipLabel.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(
                        property.relationshipLabel,
                        style: const TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 12.5,
                          height: 1.3,
                          fontWeight: FontWeight.w600,
                          color: BlueColors.muted,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const Padding(
                padding: EdgeInsets.only(top: 10),
                child: BlueGlyphIcon(
                  BlueGlyph.chevronRight,
                  size: 14,
                  color: BlueColors.rowChevron,
                  strokeWidth: 1.9,
                ),
              ),
            ],
          ),
        ),
      ),
    );
    if (!widget.fadeIn || MediaQuery.disableAnimationsOf(context)) return row;
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: BlueMotion.of(context, const Duration(milliseconds: 200)),
      curve: BlueMotion.curve,
      builder: (context, t, child) {
        return Opacity(opacity: t, child: child);
      },
      child: row,
    );
  }
}

class PropertyTypeTile extends StatelessWidget {
  const PropertyTypeTile({super.key, required this.glyph});

  final BlueGlyph glyph;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: BlueDimens.propertyIcon,
      height: BlueDimens.propertyIcon,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: BlueColors.chipSurface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: BlueColors.skeleton),
      ),
      child: BlueGlyphIcon(
        glyph,
        size: 18,
        color: BlueColors.ink,
        strokeWidth: 1.8,
      ),
    );
  }
}

class PropertiesEmptyState extends StatelessWidget {
  const PropertiesEmptyState({super.key, required this.onAdd});

  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        72,
        BlueDimens.homeGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const BlueGlyphIcon(
            BlueGlyph.building,
            size: 26,
            color: BlueColors.ink,
            strokeWidth: 1.8,
          ),
          const SizedBox(height: 16),
          const Text(
            'No properties saved',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 20,
              height: 1.3,
              fontWeight: FontWeight.w700,
              letterSpacing: 20 * -0.02,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 8),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 300),
            child: const Text(
              "Save the places you book services for, so you don't have to describe them again every time.",
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 20),
          LocationSaveButton(
            label: 'Add property',
            busy: false,
            onPressed: onAdd,
          ),
          const SizedBox(height: 12),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 300),
            child: const Text(
              "Saving a property doesn't book anything, and it won't change bookings you've already made.",
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.45,
                fontWeight: FontWeight.w500,
                color: BlueColors.muted,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class PropertiesErrorState extends StatelessWidget {
  const PropertiesErrorState({super.key, required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        72,
        BlueDimens.homeGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const BlueGlyphIcon(
            BlueGlyph.warning,
            size: 26,
            color: BlueColors.error,
            strokeWidth: 1.9,
          ),
          const SizedBox(height: 14),
          const Text(
            "We couldn't load your properties",
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 19,
              height: 1.3,
              fontWeight: FontWeight.w700,
              letterSpacing: 19 * -0.018,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 7),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 300),
            child: const Text(
              "Nothing has been lost — this is only a problem loading the page. Check your connection and try again.",
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 18),
          Align(
            alignment: Alignment.centerLeft,
            child: BluePressable(
              onPressed: onRetry,
              scale: 0.985,
              duration: const Duration(milliseconds: 140),
              child: Container(
                height: 48,
                padding: const EdgeInsets.symmetric(horizontal: 26),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: BlueColors.ink,
                  borderRadius: BorderRadius.circular(16),
                ),
                child: const Text(
                  'Try again',
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    color: BlueColors.white,
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class PropertiesSkeleton extends StatefulWidget {
  const PropertiesSkeleton({super.key});

  @override
  State<PropertiesSkeleton> createState() => _PropertiesSkeletonState();
}

class _PropertiesSkeletonState extends State<PropertiesSkeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(vsync: this, duration: BlueMotion.shimmer)
      ..repeat(reverse: true);
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _pulse,
      builder: (context, _) {
        final t = 0.55 + (_pulse.value * 0.45);
        return Opacity(
          opacity: t,
          child: const Column(
            children: [
              _SkeletonRow(first: 168, second: 196, third: 148),
              _SkeletonRow(first: 120, second: 172, third: 132),
              _SkeletonRow(first: 142, second: 184, third: 156),
              _SkeletonRow(first: 110, second: 160, third: 140),
            ],
          ),
        );
      },
    );
  }
}

class _SkeletonRow extends StatelessWidget {
  const _SkeletonRow({
    required this.first,
    required this.second,
    required this.third,
  });

  final double first;
  final double second;
  final double third;

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: const BoxConstraints(minHeight: BlueDimens.propertyRow),
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        14,
        BlueDimens.homeGutter,
        14,
      ),
      decoration: const BoxDecoration(
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: BlueDimens.propertyIcon,
            height: BlueDimens.propertyIcon,
            decoration: BoxDecoration(
              color: BlueColors.skeleton,
              borderRadius: BorderRadius.circular(14),
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _Bone(width: first, height: 14),
                const SizedBox(height: 8),
                _Bone(width: second, height: 11),
                const SizedBox(height: 7),
                _Bone(width: third, height: 10),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Bone extends StatelessWidget {
  const _Bone({required this.width, required this.height});

  final double width;
  final double height;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: BlueColors.skeleton,
        borderRadius: BorderRadius.circular(6),
      ),
    );
  }
}

class PropertiesFootNote extends StatelessWidget {
  const PropertiesFootNote({super.key, required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        16,
        BlueDimens.homeGutter,
        28,
      ),
      child: Text(
        text,
        style: const TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: 12.5,
          height: 1.45,
          fontWeight: FontWeight.w500,
          color: BlueColors.muted,
        ),
      ),
    );
  }
}

class PropertiesRemoveLink extends StatelessWidget {
  const PropertiesRemoveLink({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        BluePressable(
          onPressed: onPressed,
          scale: 0.99,
          duration: const Duration(milliseconds: 140),
          child: const Padding(
            padding: EdgeInsets.symmetric(vertical: 4),
            child: Text(
              'Remove this property',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14.5,
                height: 1.3,
                fontWeight: FontWeight.w700,
                color: BlueColors.error,
              ),
            ),
          ),
        ),
        const SizedBox(height: 6),
        const Text(
          "Removing a property doesn't change bookings or contracts you've already made.",
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 12.5,
            height: 1.45,
            fontWeight: FontWeight.w500,
            color: BlueColors.muted,
          ),
        ),
      ],
    );
  }
}

class PropertiesSheetAction extends StatelessWidget {
  const PropertiesSheetAction({
    super.key,
    required this.label,
    required this.onPressed,
    this.filled = true,
    this.destructive = false,
  });

  final String label;
  final VoidCallback onPressed;
  final bool filled;
  final bool destructive;

  @override
  Widget build(BuildContext context) {
    final background = filled
        ? (destructive ? BlueColors.error : BlueColors.ink)
        : BlueColors.white;
    final foreground = filled ? BlueColors.white : BlueColors.ink;
    return BluePressable(
      onPressed: onPressed,
      scale: 0.99,
      duration: const Duration(milliseconds: 140),
      child: Container(
        height: BlueDimens.checkoutCtaHeight,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: background,
          borderRadius: BorderRadius.circular(16),
          border: filled ? null : Border.all(color: BlueColors.border),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 15.5,
            fontWeight: FontWeight.w700,
            color: foreground,
          ),
        ),
      ),
    );
  }
}

Future<bool> confirmPropertyRemove(BuildContext context) async {
  final result = await showBlueSheet<bool>(
    context: context,
    builder: (context) {
      return Align(
        alignment: Alignment.bottomCenter,
        child: Material(
          color: Colors.transparent,
          child: DecoratedBox(
            decoration: const BoxDecoration(
              color: BlueColors.white,
              borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
            ),
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                22,
                22,
                22,
                MediaQuery.paddingOf(context).bottom < 18
                    ? 22
                    : MediaQuery.paddingOf(context).bottom,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Remove this property?',
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 20,
                      height: 1.25,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 20 * -0.02,
                      color: BlueColors.ink,
                    ),
                  ),
                  const SizedBox(height: 10),
                  const Text(
                    "It will be removed from your saved properties. Bookings and contracts you've already made keep their own address and are not affected.",
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 14,
                      height: 1.5,
                      fontWeight: FontWeight.w500,
                      color: BlueColors.muted,
                    ),
                  ),
                  const SizedBox(height: 22),
                  PropertiesSheetAction(
                    label: 'Remove property',
                    destructive: true,
                    onPressed: () => Navigator.pop(context, true),
                  ),
                  const SizedBox(height: 10),
                  PropertiesSheetAction(
                    label: 'Keep it',
                    filled: false,
                    onPressed: () => Navigator.pop(context, false),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    },
  );
  return result == true;
}

Future<void> showPropertyRemoveBlocked(
  BuildContext context, {
  String reason = 'A running contract or upcoming booking uses this property.',
}) {
  return showBlueSheet<void>(
    context: context,
    builder: (context) {
      return Align(
        alignment: Alignment.bottomCenter,
        child: Material(
          color: Colors.transparent,
          child: DecoratedBox(
            decoration: const BoxDecoration(
              color: BlueColors.white,
              borderRadius: BorderRadius.vertical(top: Radius.circular(26)),
            ),
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                22,
                18,
                22,
                MediaQuery.paddingOf(context).bottom < 18
                    ? 22
                    : MediaQuery.paddingOf(context).bottom,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  LocationFailStrip(message: reason),
                  const SizedBox(height: 18),
                  const Text(
                    'This property is in use',
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 20,
                      height: 1.25,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 20 * -0.02,
                      color: BlueColors.ink,
                    ),
                  ),
                  const SizedBox(height: 10),
                  const Text(
                    "You can't remove it while work is still scheduled against it. You can still edit its details, or remove it once everything has finished.",
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 14,
                      height: 1.5,
                      fontWeight: FontWeight.w500,
                      color: BlueColors.muted,
                    ),
                  ),
                  const SizedBox(height: 22),
                  PropertiesSheetAction(
                    label: 'Got it',
                    filled: false,
                    onPressed: () => Navigator.pop(context),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    },
  );
}

Future<T?> showPropertyChoiceSheet<T>({
  required BuildContext context,
  required String title,
  required List<T> items,
  required String Function(T item) labelOf,
  required bool Function(T item) selected,
}) {
  return showBlueSheet<T>(
    context: context,
    builder: (context) {
      return BlueSheetPanel(
        title: title,
        onClose: () => Navigator.pop(context),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
          shrinkWrap: true,
          children: [
            for (var i = 0; i < items.length; i++)
              BlueSheetRow(
                index: i,
                label: labelOf(items[i]),
                selected: selected(items[i]),
                onPressed: () => Navigator.pop(context, items[i]),
              ),
          ],
        ),
      );
    },
  );
}
