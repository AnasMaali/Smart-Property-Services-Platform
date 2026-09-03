import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../../core/input/latin_digits.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../home/data/catalog_service.dart';
import '../../../home/data/service_category.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../../home/presentation/widgets/home_sections.dart';
import 'service_detail_widgets.dart';

class ServicesBackButton extends StatelessWidget {
  const ServicesBackButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.92,
      child: const SizedBox(
        width: 44,
        height: 44,
        child: Align(
          alignment: Alignment.centerLeft,
          child: CustomPaint(
            size: Size(20, 20),
            painter: _ServicesBackPainter(),
          ),
        ),
      ),
    );
  }
}

class _ServicesBackPainter extends CustomPainter {
  const _ServicesBackPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.ink
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.05
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawLine(Offset(19 * sx, 12 * sy), Offset(5 * sx, 12 * sy), paint);
    final path = Path()
      ..moveTo(11 * sx, 18 * sy)
      ..lineTo(5 * sx, 12 * sy)
      ..lineTo(11 * sx, 6 * sy);
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class ServicesTitle extends StatelessWidget {
  const ServicesTitle({super.key, required this.title, required this.subtitle});

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
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 30,
                  height: 1.05,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 30 * -0.03,
                  color: BlueColors.ink,
                ),
              ),
            ),
            const SizedBox(width: 9),
            Padding(
              padding: const EdgeInsets.only(bottom: 7),
              child: Container(
                width: 24,
                height: 4,
                decoration: BoxDecoration(
                  color: BlueColors.gold,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 8),
        Text(
          subtitle,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 14.5,
            height: 1.4,
            fontWeight: FontWeight.w500,
            color: BlueColors.muted,
          ),
        ),
      ],
    );
  }
}

class ServicesSearchField extends StatefulWidget {
  const ServicesSearchField({
    super.key,
    required this.controller,
    required this.focusNode,
    required this.onChanged,
    required this.onClear,
    required this.onSubmitted,
  });

  final TextEditingController controller;
  final FocusNode focusNode;
  final ValueChanged<String> onChanged;
  final VoidCallback onClear;
  final ValueChanged<String> onSubmitted;

  @override
  State<ServicesSearchField> createState() => _ServicesSearchFieldState();
}

class _ServicesSearchFieldState extends State<ServicesSearchField> {
  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_onTick);
    widget.focusNode.addListener(_onTick);
  }

  @override
  void didUpdateWidget(covariant ServicesSearchField oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.controller != widget.controller) {
      oldWidget.controller.removeListener(_onTick);
      widget.controller.addListener(_onTick);
    }
    if (oldWidget.focusNode != widget.focusNode) {
      oldWidget.focusNode.removeListener(_onTick);
      widget.focusNode.addListener(_onTick);
    }
  }

  @override
  void dispose() {
    widget.controller.removeListener(_onTick);
    widget.focusNode.removeListener(_onTick);
    super.dispose();
  }

  void _onTick() => setState(() {});

  @override
  Widget build(BuildContext context) {
    final focused = widget.focusNode.hasFocus;
    final filled = widget.controller.text.isNotEmpty;
    return AnimatedContainer(
      duration: BlueMotion.of(context, BlueMotion.snap),
      curve: BlueMotion.curve,
      height: BlueDimens.searchFieldHeight,
      padding: const EdgeInsets.symmetric(horizontal: 14),
      decoration: BoxDecoration(
        color: BlueColors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: focused ? BlueColors.ink : BlueColors.border,
          width: focused ? 1.6 : 1,
        ),
      ),
      child: Row(
        children: [
          BlueGlyphIcon(
            BlueGlyph.search,
            size: 17,
            color: focused ? BlueColors.ink : BlueColors.chevron,
            strokeWidth: 2.15,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: TextField(
              controller: widget.controller,
              focusNode: widget.focusNode,
              textInputAction: TextInputAction.search,
              inputFormatters: const [LatinDigits.formatter],
              cursorColor: BlueColors.ink,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 15.5,
                fontWeight: FontWeight.w500,
                color: BlueColors.ink,
              ),
              decoration: const InputDecoration(
                isCollapsed: true,
                border: InputBorder.none,
                hintText: 'Search services',
                hintStyle: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 15.5,
                  fontWeight: FontWeight.w500,
                  color: BlueColors.placeholder,
                ),
              ),
              onChanged: widget.onChanged,
              onSubmitted: widget.onSubmitted,
            ),
          ),
          if (filled)
            BluePressable(
              onPressed: widget.onClear,
              scale: 0.9,
              child: const SizedBox(
                width: 28,
                height: 28,
                child: Center(
                  child: BlueGlyphIcon(
                    BlueGlyph.close,
                    size: 13,
                    color: BlueColors.chevron,
                    strokeWidth: 2.2,
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class CategoryChipBar extends StatelessWidget {
  const CategoryChipBar({
    super.key,
    required this.categories,
    required this.selected,
    required this.onSelect,
  });

  final List<ServiceCategory> categories;
  final ServiceCategory? selected;
  final ValueChanged<ServiceCategory?> onSelect;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: BlueDimens.categoryChipHeight,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        physics: const BouncingScrollPhysics(),
        padding: const EdgeInsets.symmetric(horizontal: BlueDimens.homeGutter),
        itemCount: categories.length + 1,
        separatorBuilder: (_, _) => const SizedBox(width: 8),
        itemBuilder: (context, index) {
          if (index == 0) {
            return _CategoryChip(
              label: 'All',
              selected: selected == null,
              onPressed: () => onSelect(null),
            );
          }
          final category = categories[index - 1];
          return _CategoryChip(
            label: category.name,
            selected: selected?.id == category.id,
            onPressed: () => onSelect(category),
          );
        },
      ),
    );
  }
}

class _CategoryChip extends StatelessWidget {
  const _CategoryChip({
    required this.label,
    required this.selected,
    required this.onPressed,
  });

  final String label;
  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.97,
      child: AnimatedContainer(
        duration: BlueMotion.of(context, BlueMotion.snap),
        curve: BlueMotion.curve,
        height: BlueDimens.categoryChipHeight,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: selected ? BlueColors.ink : BlueColors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: selected ? BlueColors.ink : BlueColors.border,
          ),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13.5,
            fontWeight: FontWeight.w600,
            color: selected ? BlueColors.white : BlueColors.ink,
          ),
        ),
      ),
    );
  }
}

class ServiceResultCount extends StatelessWidget {
  const ServiceResultCount({super.key, required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    return Text(
      count == 1 ? '1 service' : '$count services',
      style: const TextStyle(
        fontFamily: BlueFonts.jakarta,
        fontSize: 13,
        fontWeight: FontWeight.w500,
        color: BlueColors.muted,
      ),
    );
  }
}

class ServiceRow extends StatelessWidget {
  const ServiceRow({super.key, required this.service, required this.onPressed});

  final CatalogService service;
  final VoidCallback onPressed;

  static const _thumbs = [
    BlueColors.thumbCool,
    BlueColors.thumbMint,
    BlueColors.thumbLilac,
  ];

  @override
  Widget build(BuildContext context) {
    final enabled = service.enabled;
    final imageUrl = service.image?.networkUrl;
    final fill = _thumbs[service.name.hashCode.abs() % _thumbs.length];

    return Opacity(
      opacity: enabled ? 1 : 0.62,
      child: BluePressable(
        onPressed: onPressed,
        scale: 0.985,
        child: SizedBox(
          height: BlueDimens.serviceRowHeight,
          child: DecoratedBox(
            decoration: const BoxDecoration(
              border: Border(
                bottom: BorderSide(color: BlueColors.sheetHairline),
              ),
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 13),
              child: Row(
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(
                      BlueDimens.serviceThumbRadius,
                    ),
                    child: SizedBox(
                      width: BlueDimens.serviceThumbWidth,
                      height: BlueDimens.serviceThumbHeight,
                      child: ColoredBox(
                        color: fill,
                        child: imageUrl == null
                            ? const Center(
                                child: BlueGlyphIcon(
                                  BlueGlyph.photo,
                                  size: 22,
                                  color: BlueColors.glyph,
                                  strokeWidth: 1.55,
                                ),
                              )
                            : Image.network(
                                imageUrl,
                                fit: BoxFit.cover,
                                errorBuilder: (_, _, _) {
                                  return const Center(
                                    child: BlueGlyphIcon(
                                      BlueGlyph.photo,
                                      size: 22,
                                      color: BlueColors.glyph,
                                      strokeWidth: 1.55,
                                    ),
                                  );
                                },
                              ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          service.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 15.5,
                            height: 1.25,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 15.5 * -0.01,
                            color: BlueColors.ink,
                          ),
                        ),
                        if (service.isBestseller) ...[
                          const SizedBox(height: 4),
                          const BestSellerChip(compact: true),
                        ],
                        if (service.shortDescription.isNotEmpty) ...[
                          const SizedBox(height: 3),
                          Text(
                            service.shortDescription,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontFamily: BlueFonts.jakarta,
                              fontSize: 12.5,
                              height: 1.3,
                              fontWeight: FontWeight.w500,
                              color: BlueColors.muted,
                            ),
                          ),
                        ],
                        const SizedBox(height: 7),
                        _PricingMark(preview: service.pricing),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  const BlueGlyphIcon(
                    BlueGlyph.chevronRight,
                    size: 14,
                    color: BlueColors.chevron,
                    strokeWidth: 2.1,
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _PricingMark extends StatelessWidget {
  const _PricingMark({required this.preview});

  final CatalogPricingPreview preview;

  @override
  Widget build(BuildContext context) {
    if (preview.showAsSentence) {
      return Text(
        preview.label,
        style: const TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: 13.5,
          height: 1.2,
          fontWeight: FontWeight.w700,
          color: BlueColors.ink,
        ),
      );
    }

    final unavailable =
        preview.isUnavailable ||
        (preview.status == CatalogPricingStatus.priced &&
            !preview.showAsSentence);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3.5),
      decoration: BoxDecoration(
        color: unavailable ? BlueColors.unavailableFill : BlueColors.chipFill,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        preview.label,
        style: TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: 12,
          height: 1.15,
          fontWeight: FontWeight.w600,
          color: unavailable ? BlueColors.unavailableText : BlueColors.ink,
        ),
      ),
    );
  }
}

class ServicesSkeleton extends StatefulWidget {
  const ServicesSkeleton({super.key});

  @override
  State<ServicesSkeleton> createState() => _ServicesSkeletonState();
}

class _ServicesSkeletonState extends State<ServicesSkeleton>
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
    final grid = Column(
      children: [
        for (var i = 0; i < 5; i++)
          SizedBox(
            height: BlueDimens.serviceRowHeight,
            child: DecoratedBox(
              decoration: const BoxDecoration(
                border: Border(
                  bottom: BorderSide(color: BlueColors.sheetHairline),
                ),
              ),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 13),
                child: Row(
                  children: [
                    Container(
                      width: BlueDimens.serviceThumbWidth,
                      height: BlueDimens.serviceThumbHeight,
                      decoration: BoxDecoration(
                        color: BlueColors.skeleton,
                        borderRadius: BorderRadius.circular(
                          BlueDimens.serviceThumbRadius,
                        ),
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Container(
                            width: i.isEven ? 148 : 118,
                            height: 13,
                            decoration: BoxDecoration(
                              color: BlueColors.skeleton,
                              borderRadius: BorderRadius.circular(6),
                            ),
                          ),
                          const SizedBox(height: 9),
                          Container(
                            width: i.isEven ? 96 : 78,
                            height: 10,
                            decoration: BoxDecoration(
                              color: BlueColors.skeleton,
                              borderRadius: BorderRadius.circular(5),
                            ),
                          ),
                          const SizedBox(height: 11),
                          Container(
                            width: 72,
                            height: 10,
                            decoration: BoxDecoration(
                              color: BlueColors.skeleton,
                              borderRadius: BorderRadius.circular(5),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
      ],
    );

    if (MediaQuery.disableAnimationsOf(context)) {
      return SingleChildScrollView(
        physics: const NeverScrollableScrollPhysics(),
        child: grid,
      );
    }
    return FadeTransition(
      opacity: Tween(
        begin: 0.55,
        end: 1.0,
      ).animate(CurvedAnimation(parent: _pulse, curve: Curves.easeInOut)),
      child: SingleChildScrollView(
        physics: const NeverScrollableScrollPhysics(),
        child: grid,
      ),
    );
  }
}

class ServicesMessage extends StatelessWidget {
  const ServicesMessage({
    super.key,
    required this.icon,
    required this.iconColor,
    required this.title,
    required this.body,
    required this.action,
    required this.onAction,
  });

  final BlueGlyph icon;
  final Color iconColor;
  final String title;
  final String body;
  final String action;
  final VoidCallback onAction;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(8, 36, 8, 24),
      child: Column(
        children: [
          BlueGlyphIcon(icon, size: 36, color: iconColor, strokeWidth: 1.7),
          const SizedBox(height: 18),
          Text(
            title,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 20,
              height: 1.25,
              fontWeight: FontWeight.w700,
              letterSpacing: 20 * -0.02,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            body,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14.5,
              height: 1.5,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
          const SizedBox(height: 22),
          HomeInkButton(
            label: action,
            expanded: true,
            height: 52,
            onPressed: onAction,
          ),
        ],
      ),
    );
  }
}
