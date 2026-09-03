import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../auth/presentation/widgets/blue_sheet.dart';
import '../../data/catalog_service.dart';
import '../../data/service_category.dart';
import '../../../services/presentation/widgets/service_detail_widgets.dart';
import 'home_icons.dart';

class HomeSearchFilterBar extends StatelessWidget {
  const HomeSearchFilterBar({
    super.key,
    required this.onSearch,
    required this.onFilter,
  });

  final VoidCallback onSearch;
  final VoidCallback onFilter;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: BluePressable(
            onPressed: onSearch,
            scale: 0.985,
            child: Container(
              height: 50,
              padding: const EdgeInsets.symmetric(horizontal: 14),
              decoration: BoxDecoration(
                color: BlueColors.white,
                borderRadius: BorderRadius.circular(25),
                border: Border.all(color: BlueColors.border),
              ),
              child: const Row(
                children: [
                  BlueGlyphIcon(
                    BlueGlyph.search,
                    size: 17,
                    color: BlueColors.chevron,
                    strokeWidth: 2.2,
                  ),
                  SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'Search for a service',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 14.5,
                        fontWeight: FontWeight.w500,
                        color: BlueColors.placeholder,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        const SizedBox(width: 8),
        BluePressable(
          onPressed: onFilter,
          scale: 0.96,
          child: Container(
            height: 50,
            padding: const EdgeInsets.symmetric(horizontal: 14),
            decoration: BoxDecoration(
              color: BlueColors.ink,
              borderRadius: BorderRadius.circular(25),
            ),
            child: const Row(
              children: [
                BlueGlyphIcon(
                  BlueGlyph.sliders,
                  size: 16,
                  color: BlueColors.white,
                  strokeWidth: 1.9,
                ),
                SizedBox(width: 7),
                Text(
                  'Filter',
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 14.5,
                    fontWeight: FontWeight.w700,
                    color: BlueColors.white,
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class HomeFilterChips extends StatelessWidget {
  const HomeFilterChips({
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
      height: 40,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        physics: const BouncingScrollPhysics(),
        itemCount: categories.length + 1,
        separatorBuilder: (_, _) => const SizedBox(width: 8),
        itemBuilder: (context, index) {
          if (index == 0) {
            return _Chip(
              label: 'All',
              selected: selected == null,
              onPressed: () => onSelect(null),
            );
          }
          final category = categories[index - 1];
          return _Chip(
            label: BlueGlyphIcon.shortCategoryName(
              category.code,
              category.name,
            ),
            selected: selected?.id == category.id,
            onPressed: () => onSelect(category),
          );
        },
      ),
    );
  }
}

class _Chip extends StatelessWidget {
  const _Chip({
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
        height: 40,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: selected ? BlueColors.ink : BlueColors.white,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(
            color: selected ? BlueColors.ink : BlueColors.border,
          ),
        ),
        child: Stack(
          alignment: Alignment.bottomCenter,
          children: [
            Padding(
              padding: const EdgeInsets.only(bottom: 3),
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
            if (selected)
              Positioned(
                bottom: 0,
                child: Container(
                  width: 18,
                  height: 3,
                  decoration: BoxDecoration(
                    color: BlueColors.gold,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class HomeFilterPick {
  const HomeFilterPick(this.category);

  final ServiceCategory? category;
}

Future<HomeFilterPick?> showHomeFilterSheet({
  required BuildContext context,
  required List<ServiceCategory> categories,
  required ServiceCategory? selected,
}) {
  return showBlueSheet<HomeFilterPick>(
    context: context,
    builder: (context) {
      return BlueSheetPanel(
        title: 'Filter services',
        onClose: () => Navigator.of(context).pop(),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(8, 4, 8, 20),
          children: [
            _FilterRow(
              label: 'All services',
              selected: selected == null,
              onPressed: () =>
                  Navigator.of(context).pop(const HomeFilterPick(null)),
            ),
            for (final category in categories)
              _FilterRow(
                label: category.name,
                selected: selected?.id == category.id,
                onPressed: () =>
                    Navigator.of(context).pop(HomeFilterPick(category)),
              ),
          ],
        ),
      );
    },
  );
}

class _FilterRow extends StatelessWidget {
  const _FilterRow({
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
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
        child: Row(
          children: [
            Expanded(
              child: Text(
                label,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 15.5,
                  fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                  color: BlueColors.ink,
                ),
              ),
            ),
            if (selected)
              const BlueGlyphIcon(BlueGlyph.check, size: 18, strokeWidth: 2.2),
          ],
        ),
      ),
    );
  }
}

class HomeSectionHeader extends StatelessWidget {
  const HomeSectionHeader({
    super.key,
    required this.title,
    required this.onViewAll,
  });

  final String title;
  final VoidCallback onViewAll;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            title,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 18,
              height: 1.2,
              fontWeight: FontWeight.w800,
              letterSpacing: 18 * -0.02,
              color: BlueColors.ink,
            ),
          ),
        ),
        BluePressable(
          onPressed: onViewAll,
          scale: 0.96,
          child: const Row(
            children: [
              Text(
                'View all',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 13.5,
                  fontWeight: FontWeight.w700,
                  color: BlueColors.ink,
                ),
              ),
              SizedBox(width: 2),
              BlueGlyphIcon(
                BlueGlyph.chevronRight,
                size: 13,
                color: BlueColors.gold,
                strokeWidth: 2.4,
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class HomeCategoryCard extends StatelessWidget {
  const HomeCategoryCard({
    super.key,
    required this.category,
    required this.onPressed,
  });

  final ServiceCategory category;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final label = BlueGlyphIcon.shortCategoryName(category.code, category.name);
    return BluePressable(
      onPressed: onPressed,
      scale: 0.97,
      child: Container(
        width: 96,
        padding: const EdgeInsets.fromLTRB(10, 14, 10, 12),
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: BlueColors.border),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            BlueGlyphIcon(
              BlueGlyphIcon.forCategory(category.code),
              size: 28,
              strokeWidth: 1.7,
            ),
            const SizedBox(height: 10),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                color: BlueColors.ink,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class HomeCategoryStrip extends StatelessWidget {
  const HomeCategoryStrip({
    super.key,
    required this.categories,
    required this.onCategory,
  });

  final List<ServiceCategory> categories;
  final ValueChanged<ServiceCategory> onCategory;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 108,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        clipBehavior: Clip.none,
        physics: const BouncingScrollPhysics(),
        itemCount: categories.length,
        separatorBuilder: (_, _) => const SizedBox(width: 10),
        itemBuilder: (context, index) {
          final category = categories[index];
          return HomeCategoryCard(
            category: category,
            onPressed: () => onCategory(category),
          );
        },
      ),
    );
  }
}

class HomeServiceCard extends StatelessWidget {
  const HomeServiceCard({
    super.key,
    required this.service,
    required this.onOpen,
    required this.onAdd,
  });

  final CatalogService service;
  final VoidCallback onOpen;
  final VoidCallback onAdd;

  static const _thumbs = [
    BlueColors.thumbCool,
    BlueColors.thumbMint,
    BlueColors.thumbLilac,
  ];

  @override
  Widget build(BuildContext context) {
    final imageUrl = service.image?.networkUrl;
    final fill = _thumbs[service.name.hashCode.abs() % _thumbs.length];
    return BluePressable(
      onPressed: onOpen,
      scale: 0.98,
      child: Container(
        width: 176,
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(22),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ClipRRect(
              borderRadius: const BorderRadius.vertical(
                top: Radius.circular(22),
              ),
              child: SizedBox(
                height: 112,
                width: double.infinity,
                child: Stack(
                  children: [
                    Positioned.fill(
                      child: ColoredBox(
                        color: fill,
                        child: imageUrl == null
                            ? const Center(
                                child: BlueGlyphIcon(
                                  BlueGlyph.photo,
                                  size: 26,
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
                                      size: 26,
                                      color: BlueColors.glyph,
                                      strokeWidth: 1.55,
                                    ),
                                  );
                                },
                              ),
                      ),
                    ),
                    if (service.isBestseller)
                      const Positioned(
                        top: 8,
                        left: 8,
                        child: BestSellerChip(compact: true),
                      ),
                  ],
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    service.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15,
                      height: 1.2,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 15 * -0.015,
                      color: BlueColors.ink,
                    ),
                  ),
                  const SizedBox(height: 5),
                  Text(
                    service.shortDescription.isEmpty
                        ? "Tap to see what's included."
                        : service.shortDescription,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 12,
                      height: 1.35,
                      fontWeight: FontWeight.w500,
                      color: BlueColors.muted,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          service.pricing.homePrice,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 14.5,
                            fontWeight: FontWeight.w800,
                            color: BlueColors.ink,
                          ),
                        ),
                      ),
                      BluePressable(
                        onPressed: onAdd,
                        scale: 0.92,
                        child: Container(
                          width: 32,
                          height: 32,
                          decoration: BoxDecoration(
                            color: BlueColors.plusFill,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: BlueColors.plusLine),
                          ),
                          alignment: Alignment.center,
                          child: const BlueGlyphIcon(
                            BlueGlyph.plus,
                            size: 14,
                            strokeWidth: 2.3,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class HomeServiceStrip extends StatelessWidget {
  const HomeServiceStrip({
    super.key,
    required this.services,
    required this.onOpen,
    required this.onAdd,
  });

  final List<CatalogService> services;
  final ValueChanged<CatalogService> onOpen;
  final ValueChanged<CatalogService> onAdd;

  @override
  Widget build(BuildContext context) {
    if (services.isEmpty) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 18),
        child: Text(
          'No services in this category yet.',
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13.5,
            fontWeight: FontWeight.w500,
            color: BlueColors.muted,
          ),
        ),
      );
    }
    return SizedBox(
      height: 248,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        physics: const BouncingScrollPhysics(),
        itemCount: services.length,
        separatorBuilder: (_, _) => const SizedBox(width: 12),
        itemBuilder: (context, index) {
          final service = services[index];
          return HomeServiceCard(
            service: service,
            onOpen: () => onOpen(service),
            onAdd: () => onAdd(service),
          );
        },
      ),
    );
  }
}
