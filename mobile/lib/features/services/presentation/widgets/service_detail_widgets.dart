import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../data/service_detail.dart';

class DetailHeaderButton extends StatelessWidget {
  const DetailHeaderButton({
    super.key,
    required this.onPressed,
    required this.label,
    required this.child,
  });

  final VoidCallback onPressed;
  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: label,
      child: BluePressable(
        onPressed: onPressed,
        scale: 0.92,
        duration: const Duration(milliseconds: 140),
        child: SizedBox(width: 44, height: 44, child: Center(child: child)),
      ),
    );
  }
}

class DetailCartButton extends StatelessWidget {
  const DetailCartButton({
    super.key,
    required this.count,
    required this.onPressed,
  });

  final int count;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final label = count > 0 ? 'Cart, $count items' : 'Cart, empty';
    return DetailHeaderButton(
      onPressed: onPressed,
      label: label,
      child: SizedBox(
        width: 44,
        height: 44,
        child: Stack(
          clipBehavior: Clip.none,
          alignment: Alignment.center,
          children: [
            const BlueGlyphIcon(BlueGlyph.cart, size: 22, strokeWidth: 1.9),
            if (count > 0)
              Positioned(
                top: 4,
                right: 3,
                child: Container(
                  constraints: const BoxConstraints(
                    minWidth: 18,
                    minHeight: 18,
                  ),
                  padding: const EdgeInsets.symmetric(horizontal: 4),
                  decoration: BoxDecoration(
                    color: BlueColors.ink,
                    borderRadius: BorderRadius.circular(9),
                    border: Border.all(color: BlueColors.canvas, width: 2),
                  ),
                  alignment: Alignment.center,
                  child: Text(
                    count > 99 ? '99+' : '$count',
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 10.5,
                      height: 14 / 10.5,
                      fontWeight: FontWeight.w700,
                      color: BlueColors.white,
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class DetailGallery extends StatelessWidget {
  const DetailGallery({
    super.key,
    required this.media,
    required this.page,
    required this.controller,
    required this.onPage,
    required this.fallbackName,
  });

  final List<ServiceMedia> media;
  final int page;
  final PageController controller;
  final ValueChanged<int> onPage;
  final String fallbackName;

  @override
  Widget build(BuildContext context) {
    if (media.isEmpty) {
      return Semantics(
        image: true,
        label: 'No photo available for this service',
        child: Container(
          height: 196,
          decoration: BoxDecoration(
            color: BlueColors.mediaFill,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: BlueColors.mediaLine),
          ),
          alignment: Alignment.center,
          child: const BlueGlyphIcon(
            BlueGlyph.home,
            size: 34,
            color: BlueColors.photoMute,
            strokeWidth: 1.6,
          ),
        ),
      );
    }

    return SizedBox(
      height: 196,
      child: Stack(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(18),
            child: PageView.builder(
              controller: controller,
              onPageChanged: onPage,
              itemCount: media.length,
              itemBuilder: (context, index) {
                final item = media[index];
                final url = item.networkUrl;
                return Semantics(
                  image: true,
                  label: item.altText.isNotEmpty
                      ? item.altText
                      : '$fallbackName, photo ${index + 1} of ${media.length}',
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: BlueColors.mediaFill,
                      border: Border.all(color: BlueColors.mediaLine),
                    ),
                    child: url == null
                        ? const Center(
                            child: BlueGlyphIcon(
                              BlueGlyph.photo,
                              size: 34,
                              color: BlueColors.photoMute,
                              strokeWidth: 1.6,
                            ),
                          )
                        : Image.network(
                            url,
                            fit: BoxFit.cover,
                            width: double.infinity,
                            height: 196,
                            errorBuilder: (_, _, _) {
                              return const Center(
                                child: BlueGlyphIcon(
                                  BlueGlyph.photo,
                                  size: 34,
                                  color: BlueColors.photoMute,
                                  strokeWidth: 1.6,
                                ),
                              );
                            },
                          ),
                  ),
                );
              },
            ),
          ),
          if (media.length > 1)
            Positioned(
              left: 0,
              right: 0,
              bottom: 12,
              child: IgnorePointer(
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    for (var i = 0; i < media.length; i++) ...[
                      if (i > 0) const SizedBox(width: 5),
                      AnimatedContainer(
                        duration: BlueMotion.of(context, BlueMotion.snap),
                        curve: BlueMotion.curve,
                        width: i == page ? 16 : 5,
                        height: 5,
                        decoration: BoxDecoration(
                          color: i == page
                              ? BlueColors.white
                              : const Color(0x8CFFFFFF),
                          borderRadius: BorderRadius.circular(3),
                          boxShadow: const [
                            BoxShadow(
                              color: Color(0x40140050),
                              blurRadius: 3,
                              offset: Offset(0, 1),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class DetailTitle extends StatelessWidget {
  const DetailTitle({
    super.key,
    required this.category,
    required this.name,
    required this.tagline,
    this.bestseller = false,
  });

  final String category;
  final String name;
  final String tagline;
  final bool bestseller;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (category.isNotEmpty)
          Text(
            category,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              height: 1.3,
              fontWeight: FontWeight.w600,
              letterSpacing: 12.5 * 0.005,
              color: BlueColors.muted,
            ),
          ),
        const SizedBox(height: 6),
        Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Flexible(
              child: Text(
                name,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 24,
                  height: 1.2,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 24 * -0.022,
                  color: BlueColors.ink,
                ),
              ),
            ),
            const SizedBox(width: 10),
            Padding(
              padding: const EdgeInsets.only(bottom: 7),
              child: Container(
                width: 15,
                height: 2,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(1),
                  gradient: const LinearGradient(
                    colors: [
                      BlueColors.goldDeep,
                      BlueColors.gold,
                      BlueColors.goldMid,
                      BlueColors.goldWarm,
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
        if (tagline.isNotEmpty) ...[
          const SizedBox(height: 7),
          Text(
            tagline,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13.5,
              height: 1.45,
              fontWeight: FontWeight.w500,
              color: BlueColors.muted,
            ),
          ),
        ],
        if (bestseller) ...[const SizedBox(height: 10), const BestSellerChip()],
      ],
    );
  }
}

class DetailPriceBlock extends StatelessWidget {
  const DetailPriceBlock({
    super.key,
    required this.priced,
    required this.priceText,
    required this.priceKey,
    required this.moved,
    required this.note,
    this.chipText,
    this.chipColor = BlueColors.chipInk,
    this.chipFill = BlueColors.chipSurface,
    this.chipBorder = BlueColors.border,
    this.altLink,
    this.onAltLink,
  });

  final bool priced;
  final String priceText;
  final String priceKey;
  final bool moved;
  final String note;
  final String? chipText;
  final Color chipColor;
  final Color chipFill;
  final Color chipBorder;
  final String? altLink;
  final VoidCallback? onAltLink;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (priced)
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              AnimatedSwitcher(
                duration: BlueMotion.of(
                  context,
                  const Duration(milliseconds: 220),
                ),
                switchInCurve: BlueMotion.curve,
                child: Text(
                  priceText,
                  key: ValueKey(priceKey),
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 21,
                    height: 1.15,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 21 * -0.02,
                    color: BlueColors.ink,
                  ),
                ),
              ),
              if (moved) ...[
                const SizedBox(width: 8),
                FadeTransition(
                  opacity: const AlwaysStoppedAnimation(1),
                  child: Container(
                    padding: const EdgeInsets.fromLTRB(7, 2, 7, 2),
                    decoration: BoxDecoration(
                      color: BlueColors.chipSurface,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: BlueColors.border),
                    ),
                    child: const Text(
                      'Price updated',
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 11.5,
                        height: 1.3,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 11.5 * 0.005,
                        color: BlueColors.chipInk,
                      ),
                    ),
                  ),
                ),
              ],
            ],
          )
        else if (chipText != null)
          Container(
            padding: const EdgeInsets.fromLTRB(9, 4, 9, 4),
            decoration: BoxDecoration(
              color: chipFill,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: chipBorder),
            ),
            child: Text(
              chipText!,
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.3,
                fontWeight: FontWeight.w600,
                letterSpacing: 12.5 * 0.005,
                color: chipColor,
              ),
            ),
          ),
        const SizedBox(height: 7),
        Semantics(
          liveRegion: true,
          child: Text(
            note,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              height: 1.5,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
        ),
        if (altLink != null && onAltLink != null)
          Padding(
            padding: const EdgeInsets.only(top: 10),
            child: BluePressable(
              onPressed: onAltLink,
              child: ConstrainedBox(
                constraints: const BoxConstraints(minHeight: 44),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      altLink!,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        color: BlueColors.ink,
                      ),
                    ),
                    const SizedBox(width: 6),
                    const BlueGlyphIcon(
                      BlueGlyph.chevronRight,
                      size: 14,
                      strokeWidth: 2.3,
                    ),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class DetailHairline extends StatelessWidget {
  const DetailHairline({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.fromLTRB(0, 22, 0, 0),
      child: ColoredBox(
        color: BlueColors.navLine,
        child: SizedBox(height: 1, width: double.infinity),
      ),
    );
  }
}

class DetailAbout extends StatelessWidget {
  const DetailAbout({
    super.key,
    required this.description,
    required this.expanded,
    required this.onToggle,
  });

  final String description;
  final bool expanded;
  final VoidCallback onToggle;

  bool get _long => description.length > 190;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'About this service',
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 15,
            height: 1.3,
            fontWeight: FontWeight.w700,
            letterSpacing: 15 * -0.01,
            color: BlueColors.ink,
          ),
        ),
        const SizedBox(height: 9),
        AnimatedSize(
          duration: BlueMotion.of(context, const Duration(milliseconds: 220)),
          curve: BlueMotion.curve,
          alignment: Alignment.topLeft,
          child: Text(
            description,
            maxLines: !_long || expanded ? 40 : 3,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14,
              height: 1.58,
              fontWeight: FontWeight.w400,
              color: BlueColors.body,
            ),
          ),
        ),
        if (_long)
          BluePressable(
            onPressed: onToggle,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 44),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    expanded ? 'Show less' : 'Read more',
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 13.5,
                      fontWeight: FontWeight.w700,
                      color: BlueColors.ink,
                    ),
                  ),
                  const SizedBox(width: 6),
                  AnimatedRotation(
                    turns: expanded ? 0.5 : 0,
                    duration: BlueMotion.of(context, BlueMotion.snap),
                    curve: BlueMotion.curve,
                    child: const BlueGlyphIcon(
                      BlueGlyph.chevronDown,
                      size: 13,
                      strokeWidth: 2.4,
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

class DetailSectionHead extends StatelessWidget {
  const DetailSectionHead({
    super.key,
    required this.title,
    this.needed,
    this.neededAlert = false,
    this.note,
  });

  final String title;
  final String? needed;
  final bool neededAlert;
  final String? note;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.baseline,
          textBaseline: TextBaseline.alphabetic,
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
            if (needed != null)
              Semantics(
                liveRegion: true,
                child: Text(
                  needed!,
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: neededAlert ? BlueColors.error : BlueColors.muted,
                  ),
                ),
              ),
          ],
        ),
        if (note != null) ...[
          const SizedBox(height: 6),
          Text(
            note!,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              height: 1.5,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
        ],
      ],
    );
  }
}

class DetailOptionLabel extends StatelessWidget {
  const DetailOptionLabel({
    super.key,
    required this.label,
    required this.requiredLabel,
    required this.alert,
    this.help,
  });

  final String label;
  final String requiredLabel;
  final bool alert;
  final String? help;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          crossAxisAlignment: WrapCrossAlignment.center,
          spacing: 9,
          children: [
            Text(
              label,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.3,
                fontWeight: FontWeight.w700,
                letterSpacing: 14 * -0.005,
                color: BlueColors.ink,
              ),
            ),
            Text(
              requiredLabel,
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 11.5,
                height: 1.3,
                fontWeight: FontWeight.w600,
                letterSpacing: 11.5 * 0.02,
                color: alert ? BlueColors.error : BlueColors.placeholder,
              ),
            ),
          ],
        ),
        if (help != null && help!.isNotEmpty) ...[
          const SizedBox(height: 5),
          Text(
            help!,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              height: 1.45,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
        ],
      ],
    );
  }
}

class DetailChoiceTile extends StatelessWidget {
  const DetailChoiceTile({
    super.key,
    required this.label,
    required this.selected,
    required this.onPressed,
    this.delta,
    this.error = false,
  });

  final String label;
  final String? delta;
  final bool selected;
  final bool error;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final border = selected
        ? BlueColors.ink
        : (error ? BlueColors.choiceError : BlueColors.border);
    return BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      duration: const Duration(milliseconds: 140),
      child: AnimatedContainer(
        duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
        curve: BlueMotion.curve,
        constraints: const BoxConstraints(minHeight: 64),
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
        decoration: BoxDecoration(
          color: selected ? BlueColors.ink : BlueColors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: border, width: 1.5),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(
              label,
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.2,
                fontWeight: FontWeight.w700,
                letterSpacing: 13.5 * -0.005,
                color: selected ? BlueColors.white : BlueColors.ink,
              ),
            ),
            if (delta != null && delta!.isNotEmpty) ...[
              const SizedBox(height: 3),
              Text(
                delta!,
                textAlign: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 11.5,
                  height: 1.2,
                  fontWeight: FontWeight.w500,
                  color: selected
                      ? const Color(0xC7FFFFFF)
                      : BlueColors.muted,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class DetailCheckRow extends StatelessWidget {
  const DetailCheckRow({
    super.key,
    required this.label,
    required this.selected,
    required this.onPressed,
    this.delta,
    this.error = false,
  });

  final String label;
  final String? delta;
  final bool selected;
  final bool error;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final border = selected
        ? BlueColors.ink
        : (error ? BlueColors.choiceError : BlueColors.border);
    return BluePressable(
      onPressed: onPressed,
      scale: 0.99,
      duration: const Duration(milliseconds: 140),
      child: AnimatedContainer(
        duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
        curve: BlueMotion.curve,
        constraints: const BoxConstraints(minHeight: 56),
        padding: const EdgeInsets.symmetric(horizontal: 14),
        decoration: BoxDecoration(
          color: selected ? BlueColors.ink : BlueColors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: border, width: 1.5),
        ),
        child: Row(
          children: [
            AnimatedContainer(
              duration: BlueMotion.of(
                context,
                const Duration(milliseconds: 160),
              ),
              width: 22,
              height: 22,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: selected ? BlueColors.ink : BlueColors.white,
                borderRadius: BorderRadius.circular(7),
                border: Border.all(
                  color: selected ? BlueColors.ink : BlueColors.checkLine,
                  width: 1.5,
                ),
              ),
              child: Opacity(
                opacity: selected ? 1 : 0,
                child: const BlueGlyphIcon(
                  BlueGlyph.check,
                  size: 13,
                  color: BlueColors.white,
                  strokeWidth: 3,
                ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                label,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 13.5,
                  height: 1.3,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 13.5 * -0.005,
                  color: selected ? BlueColors.white : BlueColors.ink,
                ),
              ),
            ),
            if (delta != null && delta!.isNotEmpty)
              Text(
                delta!,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: selected ? BlueColors.white : BlueColors.body,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class DetailBoolPair extends StatelessWidget {
  const DetailBoolPair({
    super.key,
    required this.value,
    required this.onChanged,
    this.error = false,
  });

  final bool? value;
  final bool error;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        _BoolChip(
          label: 'Yes',
          selected: value == true,
          error: error,
          onPressed: () => onChanged(true),
        ),
        const SizedBox(width: 9),
        _BoolChip(
          label: 'No',
          selected: value == false,
          error: error,
          onPressed: () => onChanged(false),
        ),
      ],
    );
  }
}

class _BoolChip extends StatelessWidget {
  const _BoolChip({
    required this.label,
    required this.selected,
    required this.error,
    required this.onPressed,
  });

  final String label;
  final bool selected;
  final bool error;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final border = selected
        ? BlueColors.ink
        : (error ? BlueColors.choiceError : BlueColors.border);
    return Expanded(
      child: BluePressable(
        onPressed: onPressed,
        scale: 0.985,
        duration: const Duration(milliseconds: 140),
        child: AnimatedContainer(
          duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
          curve: BlueMotion.curve,
          height: 48,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: selected ? BlueColors.ink : BlueColors.white,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: border, width: 1.5),
          ),
          child: Text(
            label,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14,
              fontWeight: FontWeight.w700,
              letterSpacing: 14 * -0.005,
              color: selected ? BlueColors.white : BlueColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}

class DetailFieldShell extends StatelessWidget {
  const DetailFieldShell({
    super.key,
    required this.focused,
    required this.error,
    required this.child,
    this.minHeight = 52,
  });

  final bool focused;
  final bool error;
  final double minHeight;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final ring = focused
        ? BlueColors.ink
        : (error ? BlueColors.fieldError : Colors.transparent);
    return Stack(
      children: [
        AnimatedContainer(
          duration: BlueMotion.of(context, const Duration(milliseconds: 180)),
          curve: BlueMotion.curve,
          constraints: BoxConstraints(minHeight: minHeight),
          decoration: BoxDecoration(
            color: BlueColors.white,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(
              color: error ? BlueColors.fieldError : BlueColors.border,
            ),
          ),
          child: child,
        ),
        Positioned.fill(
          child: IgnorePointer(
            child: AnimatedContainer(
              duration: BlueMotion.of(
                context,
                const Duration(milliseconds: 180),
              ),
              curve: BlueMotion.curve,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(17),
                border: Border.all(color: ring, width: 2),
                boxShadow: focused
                    ? const [
                        BoxShadow(color: BlueColors.glowInk, spreadRadius: 4),
                      ]
                    : null,
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class DetailOptionError extends StatelessWidget {
  const DetailOptionError({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 9),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.only(top: 2),
            child: BlueGlyphIcon(
              BlueGlyph.info,
              size: 14,
              color: BlueColors.error,
              strokeWidth: 2.1,
            ),
          ),
          const SizedBox(width: 7),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.45,
                fontWeight: FontWeight.w600,
                color: BlueColors.error,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class DetailStickyBar extends StatelessWidget {
  const DetailStickyBar({
    super.key,
    required this.price,
    required this.caption,
    required this.cta,
    required this.enabled,
    required this.priceKey,
    required this.unavailable,
    required this.onCta,
  });

  final String price;
  final String caption;
  final String cta;
  final bool enabled;
  final String priceKey;
  final bool unavailable;
  final VoidCallback onCta;

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: Color(0xF8FFFFFF),
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(22, 12, 22, bottom > 0 ? bottom + 8 : 18),
        child: Row(
          children: [
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 150),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  AnimatedSwitcher(
                    duration: BlueMotion.of(
                      context,
                      const Duration(milliseconds: 220),
                    ),
                    switchInCurve: BlueMotion.curve,
                    child: Text(
                      price,
                      key: ValueKey(priceKey),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 18,
                        height: 1.2,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 18 * -0.018,
                        color: unavailable
                            ? BlueColors.unavailableInk
                            : BlueColors.ink,
                      ),
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    caption,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 11.5,
                      height: 1.35,
                      fontWeight: FontWeight.w500,
                      color: BlueColors.placeholder,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: BluePressable(
                enabled: true,
                onPressed: onCta,
                scale: 0.99,
                duration: const Duration(milliseconds: 140),
                child: AnimatedContainer(
                  duration: BlueMotion.of(
                    context,
                    const Duration(milliseconds: 160),
                  ),
                  curve: BlueMotion.curve,
                  height: 52,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: enabled ? BlueColors.ink : BlueColors.ctaDisabled,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Text(
                    cta,
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15.5,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 15.5 * -0.005,
                      color: enabled
                          ? BlueColors.white
                          : BlueColors.ctaDisabledText,
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class DetailToast extends StatelessWidget {
  const DetailToast({super.key, required this.onViewCart});

  final VoidCallback onViewCart;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: Container(
        constraints: const BoxConstraints(minHeight: 54),
        padding: const EdgeInsets.fromLTRB(16, 0, 8, 0),
        decoration: BoxDecoration(
          color: BlueColors.ink,
          borderRadius: BorderRadius.circular(16),
          boxShadow: const [
            BoxShadow(
              color: Color(0x8C140050),
              blurRadius: 34,
              offset: Offset(0, 14),
              spreadRadius: -18,
            ),
          ],
        ),
        child: Row(
          children: [
            const BlueGlyphIcon(
              BlueGlyph.check,
              size: 17,
              color: BlueColors.white,
              strokeWidth: 2.4,
            ),
            const SizedBox(width: 12),
            const Expanded(
              child: Text(
                'Added to cart',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: BlueColors.white,
                ),
              ),
            ),
            BluePressable(
              onPressed: onViewCart,
              child: Container(
                height: 44,
                padding: const EdgeInsets.symmetric(horizontal: 14),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: const Color(0x1FFFFFFF),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Text(
                  'View cart',
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: BlueColors.white,
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class DetailSkeleton extends StatefulWidget {
  const DetailSkeleton({super.key});

  @override
  State<DetailSkeleton> createState() => _DetailSkeletonState();
}

class _DetailSkeletonState extends State<DetailSkeleton>
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

  Widget _bar(
    double width,
    double height, {
    Color color = BlueColors.skeleton,
  }) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(height > 16 ? 14 : 5),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final body = SingleChildScrollView(
      physics: const NeverScrollableScrollPhysics(),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: BlueDimens.homeGutter),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _bar(double.infinity, 196, color: BlueColors.skeleton),
            const SizedBox(height: 20),
            _bar(88, 11, color: const Color(0xFFEDEEF4)),
            const SizedBox(height: 12),
            _bar(212, 22),
            const SizedBox(height: 12),
            _bar(264, 12, color: const Color(0xFFEDEEF4)),
            const SizedBox(height: 26),
            _bar(132, 20),
            const SizedBox(height: 26),
            const ColoredBox(
              color: BlueColors.navLine,
              child: SizedBox(height: 1, width: double.infinity),
            ),
            const SizedBox(height: 24),
            _bar(double.infinity, 12, color: const Color(0xFFEDEEF4)),
            const SizedBox(height: 10),
            _bar(double.infinity, 12, color: const Color(0xFFEDEEF4)),
            const SizedBox(height: 10),
            _bar(220, 12, color: const Color(0xFFEDEEF4)),
            const SizedBox(height: 26),
            const ColoredBox(
              color: BlueColors.navLine,
              child: SizedBox(height: 1, width: double.infinity),
            ),
            const SizedBox(height: 24),
            _bar(104, 13),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(child: _bar(double.infinity, 64)),
                const SizedBox(width: 9),
                Expanded(
                  child: _bar(
                    double.infinity,
                    64,
                    color: const Color(0xFFEDEEF4),
                  ),
                ),
                const SizedBox(width: 9),
                Expanded(
                  child: _bar(
                    double.infinity,
                    64,
                    color: const Color(0xFFEDEEF4),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 26),
            _bar(118, 13),
            const SizedBox(height: 12),
            _bar(double.infinity, 52, color: const Color(0xFFEDEEF4)),
          ],
        ),
      ),
    );

    if (MediaQuery.disableAnimationsOf(context)) return body;
    return FadeTransition(
      opacity: Tween(
        begin: 0.55,
        end: 1.0,
      ).animate(CurvedAnimation(parent: _pulse, curve: Curves.easeInOut)),
      child: body,
    );
  }
}

class DetailFail extends StatelessWidget {
  const DetailFail({
    super.key,
    required this.notFound,
    required this.onRetry,
    required this.onBack,
  });

  final bool notFound;
  final VoidCallback onRetry;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(22, 52, 22, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          BlueGlyphIcon(
            BlueGlyph.warning,
            size: 26,
            color: notFound ? BlueColors.chevron : BlueColors.error,
            strokeWidth: 1.9,
          ),
          const SizedBox(height: 14),
          Text(
            notFound
                ? "This service isn't available"
                : "We couldn't load this service",
            style: const TextStyle(
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
            constraints: const BoxConstraints(maxWidth: 290),
            child: Text(
              notFound
                  ? 'It may have been renamed or withdrawn in your area. Everything else in the catalogue is unaffected.'
                  : 'Check your connection and try again. Nothing in your cart or bookings is affected.',
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 18),
          Row(
            children: [
              BluePressable(
                onPressed: notFound ? onBack : onRetry,
                scale: 0.985,
                duration: const Duration(milliseconds: 140),
                child: Container(
                  height: 48,
                  padding: const EdgeInsets.symmetric(horizontal: 24),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: BlueColors.ink,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Text(
                    notFound ? 'Browse services' : 'Try again',
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15,
                      fontWeight: FontWeight.w700,
                      color: BlueColors.white,
                    ),
                  ),
                ),
              ),
              if (!notFound) ...[
                const SizedBox(width: 10),
                BluePressable(
                  onPressed: onBack,
                  child: Container(
                    height: 48,
                    padding: const EdgeInsets.symmetric(horizontal: 20),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: BlueColors.white,
                      borderRadius: BorderRadius.circular(16),
                      border: Border.all(color: BlueColors.border),
                    ),
                    child: const Text(
                      'Back to services',
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 15,
                        fontWeight: FontWeight.w700,
                        color: BlueColors.ink,
                      ),
                    ),
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class BestSellerChip extends StatelessWidget {
  const BestSellerChip({super.key, this.compact = false});

  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 8 : 10,
        vertical: compact ? 3 : 5,
      ),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF6D9),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: const Color(0xFFE8C547)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            Icons.star_rounded,
            size: compact ? 13 : 15,
            color: BlueColors.goldDeep,
          ),
          const SizedBox(width: 4),
          Text(
            compact ? 'Best seller' : 'Best seller · most requested',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: compact ? 11 : 12,
              height: 1.2,
              fontWeight: FontWeight.w700,
              color: BlueColors.ink,
            ),
          ),
        ],
      ),
    );
  }
}

class DetailQuantityStepper extends StatelessWidget {
  const DetailQuantityStepper({
    super.key,
    required this.value,
    required this.min,
    required this.max,
    required this.unitLabel,
    required this.onChanged,
    this.error = false,
    this.lockIncrement = false,
  });

  final int value;
  final int min;
  final int max;
  final String unitLabel;
  final ValueChanged<int> onChanged;
  final bool error;
  final bool lockIncrement;

  @override
  Widget build(BuildContext context) {
    final canMinus = value > min;
    final canPlus = !lockIncrement && value < max;
    return Container(
      height: 56,
      decoration: BoxDecoration(
        color: BlueColors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: error ? BlueColors.fieldError : BlueColors.border,
          width: 1.5,
        ),
      ),
      child: Row(
        children: [
          _QtyHit(
            icon: Icons.remove_rounded,
            enabled: canMinus,
            onPressed: () => onChanged(value - 1),
          ),
          Expanded(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(
                  '$value',
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 20,
                    height: 1,
                    fontWeight: FontWeight.w800,
                    color: BlueColors.ink,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  unitLabel,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 11.5,
                    height: 1,
                    fontWeight: FontWeight.w600,
                    color: BlueColors.muted,
                  ),
                ),
              ],
            ),
          ),
          _QtyHit(
            icon: Icons.add_rounded,
            enabled: canPlus,
            onPressed: () => onChanged(value + 1),
          ),
        ],
      ),
    );
  }
}

class _QtyHit extends StatelessWidget {
  const _QtyHit({
    required this.icon,
    required this.enabled,
    required this.onPressed,
  });

  final IconData icon;
  final bool enabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: enabled ? onPressed : null,
      scale: 0.92,
      child: SizedBox(
        width: 56,
        height: 56,
        child: Icon(
          icon,
          size: 22,
          color: enabled ? BlueColors.ink : BlueColors.placeholder,
        ),
      ),
    );
  }
}

class DetailPackageCard extends StatelessWidget {
  const DetailPackageCard({
    super.key,
    required this.choice,
    required this.selected,
    required this.onPressed,
    this.error = false,
  });

  final OptionChoice choice;
  final bool selected;
  final VoidCallback onPressed;
  final bool error;

  @override
  Widget build(BuildContext context) {
    final price = choice.displayedPrice;
    final duration = choice.durationMinutes;
    final oil = [
      if ((choice.oilType ?? '').isNotEmpty) choice.oilType,
      if ((choice.oilGrade ?? '').isNotEmpty) choice.oilGrade,
    ].join(' · ');
    return BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        width: double.infinity,
        padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
        decoration: BoxDecoration(
          color: selected ? const Color(0xFFF7F4FF) : BlueColors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(
            color: error
                ? BlueColors.choiceError
                : (selected ? BlueColors.ink : BlueColors.border),
            width: selected ? 1.7 : 1.4,
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    choice.name,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 16,
                      height: 1.2,
                      fontWeight: FontWeight.w800,
                      color: BlueColors.ink,
                    ),
                  ),
                  if ((choice.description ?? '').isNotEmpty) ...[
                    const SizedBox(height: 6),
                    Text(
                      choice.description!,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 13,
                        height: 1.4,
                        fontWeight: FontWeight.w500,
                        color: BlueColors.muted,
                      ),
                    ),
                  ],
                  if (oil.isNotEmpty || duration != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      [
                        if (oil.isNotEmpty) oil,
                        if (duration != null) '$duration min',
                      ].join('  ·  '),
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 12,
                        height: 1.3,
                        fontWeight: FontWeight.w600,
                        color: BlueColors.chipInk,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(width: 10),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                if (price != null)
                  Text(
                    'AED $price',
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15.5,
                      fontWeight: FontWeight.w800,
                      color: BlueColors.ink,
                    ),
                  ),
                const SizedBox(height: 8),
                Container(
                  width: 28,
                  height: 28,
                  decoration: BoxDecoration(
                    color: selected ? BlueColors.ink : BlueColors.plusFill,
                    borderRadius: BorderRadius.circular(9),
                    border: Border.all(
                      color: selected ? BlueColors.ink : BlueColors.plusLine,
                    ),
                  ),
                  alignment: Alignment.center,
                  child: Icon(
                    selected ? Icons.check_rounded : Icons.add_rounded,
                    size: 16,
                    color: selected ? BlueColors.white : BlueColors.ink,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class DetailInspectionBanner extends StatelessWidget {
  const DetailInspectionBanner({
    super.key,
    required this.title,
    required this.body,
  });

  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
      decoration: BoxDecoration(
        color: const Color(0xFFF4F1FF),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: const Color(0xFFD9D2F2)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14.5,
              height: 1.3,
              fontWeight: FontWeight.w800,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            body,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13.5,
              height: 1.5,
              fontWeight: FontWeight.w500,
              color: BlueColors.body,
            ),
          ),
        ],
      ),
    );
  }
}

class DetailOverviewStats extends StatelessWidget {
  const DetailOverviewStats({super.key, required this.stats});

  final List<ServiceContentSection> stats;

  @override
  Widget build(BuildContext context) {
    if (stats.isEmpty) return const SizedBox.shrink();
    return Row(
      children: [
        for (var i = 0; i < stats.length; i++) ...[
          if (i > 0) const SizedBox(width: 10),
          Expanded(
            child: Container(
              padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
              decoration: BoxDecoration(
                color: BlueColors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: BlueColors.border),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    stats[i].title,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 11.5,
                      height: 1.3,
                      fontWeight: FontWeight.w600,
                      color: BlueColors.muted,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    stats[i].statValue ?? '',
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 18,
                      height: 1.15,
                      fontWeight: FontWeight.w800,
                      color: BlueColors.ink,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class DetailInfoSection extends StatelessWidget {
  const DetailInfoSection({super.key, required this.title, required this.body});

  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 16,
            height: 1.25,
            fontWeight: FontWeight.w800,
            color: BlueColors.ink,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          body,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13.5,
            height: 1.5,
            fontWeight: FontWeight.w500,
            color: BlueColors.body,
          ),
        ),
      ],
    );
  }
}

class DetailCheckpointList extends StatelessWidget {
  const DetailCheckpointList({super.key, required this.categories});

  final List<ServiceCheckpointCategory> categories;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'Service checkpoints',
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 16,
            height: 1.25,
            fontWeight: FontWeight.w800,
            color: BlueColors.ink,
          ),
        ),
        const SizedBox(height: 12),
        for (var i = 0; i < categories.length; i++) ...[
          if (i > 0) const SizedBox(height: 10),
          _CheckpointGroup(category: categories[i]),
        ],
      ],
    );
  }
}

class _CheckpointGroup extends StatelessWidget {
  const _CheckpointGroup({required this.category});

  final ServiceCheckpointCategory category;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        color: BlueColors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: BlueColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  category.name,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 14,
                    fontWeight: FontWeight.w800,
                    color: BlueColors.ink,
                  ),
                ),
              ),
              Text(
                category.countLabel,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  fontWeight: FontWeight.w700,
                  color: BlueColors.muted,
                ),
              ),
            ],
          ),
          if (category.items.isNotEmpty) ...[
            const SizedBox(height: 8),
            for (final item in category.items)
              Padding(
                padding: const EdgeInsets.only(top: 5),
                child: Text(
                  item.actionLabel == null || item.actionLabel!.isEmpty
                      ? item.name
                      : '${item.name} — ${item.actionLabel}',
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 13,
                    height: 1.35,
                    fontWeight: FontWeight.w500,
                    color: BlueColors.body,
                  ),
                ),
              ),
          ],
        ],
      ),
    );
  }
}
