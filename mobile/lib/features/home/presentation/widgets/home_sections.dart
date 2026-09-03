import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../profile/data/customer_profile.dart';
import '../../data/service_category.dart';
import 'home_icons.dart';

class HomeHeader extends StatelessWidget {
  const HomeHeader({super.key, required this.hasAlerts, required this.onBell});

  final bool hasAlerts;
  final VoidCallback onBell;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Image.asset(
          'assets/brand/penguin.png',
          width: 22,
          height: 36,
          fit: BoxFit.contain,
          filterQuality: FilterQuality.high,
        ),
        const SizedBox(width: 9),
        const Padding(
          padding: EdgeInsets.only(top: 2),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'blue',
                style: TextStyle(
                  fontFamily: BlueFonts.poppins,
                  fontWeight: FontWeight.w800,
                  fontSize: 22,
                  height: 1,
                  letterSpacing: 22 * -0.04,
                  color: BlueColors.ink,
                ),
              ),
              SizedBox(height: 3),
              Text(
                'Property Services',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontWeight: FontWeight.w500,
                  fontSize: 11.5,
                  height: 1,
                  color: BlueColors.placeholder,
                ),
              ),
            ],
          ),
        ),
        const Spacer(),
        _BellButton(hasAlerts: hasAlerts, onPressed: onBell),
      ],
    );
  }
}

class _BellButton extends StatefulWidget {
  const _BellButton({required this.hasAlerts, required this.onPressed});

  final bool hasAlerts;
  final VoidCallback onPressed;

  @override
  State<_BellButton> createState() => _BellButtonState();
}

class _BellButtonState extends State<_BellButton> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: (_) => setState(() => _down = true),
      onTapUp: (_) => setState(() => _down = false),
      onTapCancel: () => setState(() => _down = false),
      onTap: () {
        BlueMotion.tap();
        widget.onPressed();
      },
      child: AnimatedScale(
        scale: _down ? 0.94 : 1,
        duration: BlueMotion.of(context, const Duration(milliseconds: 140)),
        curve: Curves.easeOut,
        child: AnimatedContainer(
          duration: BlueMotion.of(context, const Duration(milliseconds: 140)),
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: _down ? BlueColors.press : BlueColors.white,
            borderRadius: BorderRadius.circular(14),
            boxShadow: const [
              BoxShadow(
                color: BlueColors.cardShadow,
                blurRadius: 16,
                offset: Offset(0, 6),
              ),
            ],
          ),
          child: Stack(
            alignment: Alignment.center,
            children: [
              const BlueGlyphIcon(BlueGlyph.cart, size: 20, strokeWidth: 1.85),
              if (widget.hasAlerts)
                const Positioned(
                  top: 10,
                  right: 11,
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: BlueColors.alert,
                      shape: BoxShape.circle,
                      boxShadow: [
                        BoxShadow(color: BlueColors.white, spreadRadius: 2),
                      ],
                    ),
                    child: SizedBox(width: 8, height: 8),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class HomeGreeting extends StatelessWidget {
  const HomeGreeting({
    super.key,
    required this.name,
    this.place = '',
    this.compact = false,
  });

  final String name;
  final String place;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(height: compact ? 18 : 16),
        Text(
          blueGreetingPart(),
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: compact ? 15 : 16,
            height: 1.2,
            fontWeight: FontWeight.w500,
            color: BlueColors.muted,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          name,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: compact ? 26 : 32,
            height: 1.1,
            fontWeight: FontWeight.w800,
            letterSpacing: (compact ? 26 : 32) * -0.03,
            color: BlueColors.ink,
          ),
        ),
        const SizedBox(height: 8),
        Container(
          width: 36,
          height: 4,
          decoration: BoxDecoration(
            color: BlueColors.gold,
            borderRadius: BorderRadius.circular(2),
          ),
        ),
        if (place.isNotEmpty) ...[
          const SizedBox(height: 10),
          Row(
            children: [
              const BlueGlyphIcon(
                BlueGlyph.pin,
                size: 13,
                color: BlueColors.chevron,
                strokeWidth: 2,
              ),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  place,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 13.5,
                    fontWeight: FontWeight.w500,
                    color: BlueColors.muted,
                  ),
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }
}

class HomeSearchButton extends StatelessWidget {
  const HomeSearchButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 18),
      child: _SearchShell(onPressed: onPressed),
    );
  }
}

class _SearchShell extends StatefulWidget {
  const _SearchShell({required this.onPressed});

  final VoidCallback onPressed;

  @override
  State<_SearchShell> createState() => _SearchShellState();
}

class _SearchShellState extends State<_SearchShell> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTapDown: (_) => setState(() => _down = true),
      onTapUp: (_) => setState(() => _down = false),
      onTapCancel: () => setState(() => _down = false),
      onTap: () {
        BlueMotion.tap();
        widget.onPressed();
      },
      child: AnimatedContainer(
        duration: BlueMotion.of(context, const Duration(milliseconds: 140)),
        curve: Curves.easeOut,
        height: 52,
        padding: const EdgeInsets.symmetric(horizontal: 15),
        decoration: BoxDecoration(
          color: _down ? BlueColors.selectPress : BlueColors.white,
          borderRadius: BorderRadius.circular(16),
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
            SizedBox(width: 11),
            Text(
              'Search services',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 15.5,
                fontWeight: FontWeight.w500,
                color: BlueColors.placeholder,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class HomeTextAction extends StatelessWidget {
  const HomeTextAction({
    super.key,
    required this.label,
    required this.onPressed,
  });

  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.96,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Text(
          label,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13.5,
            fontWeight: FontWeight.w700,
            color: BlueColors.ink,
          ),
        ),
      ),
    );
  }
}

class ServiceTile extends StatelessWidget {
  const ServiceTile({
    super.key,
    required this.category,
    required this.onPressed,
  });

  final ServiceCategory category;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.975,
      duration: BlueMotion.tile,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            height: BlueDimens.tilePhoto,
            width: double.infinity,
            decoration: BoxDecoration(
              color: BlueColors.tileFill,
              borderRadius: BorderRadius.circular(BlueDimens.tileRadius),
              border: Border.all(color: BlueColors.border),
            ),
            alignment: Alignment.center,
            child: const BlueGlyphIcon(
              BlueGlyph.photo,
              size: 26,
              color: BlueColors.glyph,
              strokeWidth: 1.6,
            ),
          ),
          const SizedBox(height: 9),
          Padding(
            padding: const EdgeInsets.only(left: 2),
            child: Text(
              category.name,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14.5,
                height: 1.3,
                fontWeight: FontWeight.w600,
                letterSpacing: 14.5 * -0.005,
                color: BlueColors.ink,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class CatalogGrid extends StatelessWidget {
  const CatalogGrid({
    super.key,
    required this.categories,
    required this.expanded,
    required this.onSeeAll,
    required this.onCategory,
  });

  final List<ServiceCategory> categories;
  final bool expanded;
  final VoidCallback onSeeAll;
  final ValueChanged<ServiceCategory> onCategory;

  @override
  Widget build(BuildContext context) {
    final visible = expanded || categories.length <= 6
        ? categories
        : categories.take(6).toList();
    return Padding(
      padding: const EdgeInsets.only(top: 26),
      child: Column(
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              const Expanded(
                child: Text(
                  'What can we help with?',
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 19,
                    height: 1.25,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 19 * -0.02,
                    color: BlueColors.ink,
                  ),
                ),
              ),
              HomeTextAction(label: 'See all', onPressed: onSeeAll),
            ],
          ),
          const SizedBox(height: 14),
          AnimatedSize(
            duration: BlueMotion.of(context, BlueMotion.page),
            curve: BlueMotion.curve,
            alignment: Alignment.topCenter,
            child: Column(
              children: [
                for (var i = 0; i < visible.length; i += 2)
                  Padding(
                    padding: EdgeInsets.only(
                      bottom: i + 2 < visible.length ? 14 : 0,
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Expanded(
                          child: BlueListReveal(
                            index: i,
                            child: ServiceTile(
                              category: visible[i],
                              onPressed: () => onCategory(visible[i]),
                            ),
                          ),
                        ),
                        const SizedBox(width: 13),
                        Expanded(
                          child: i + 1 < visible.length
                              ? BlueListReveal(
                                  index: i + 1,
                                  child: ServiceTile(
                                    category: visible[i + 1],
                                    onPressed: () => onCategory(visible[i + 1]),
                                  ),
                                )
                              : const SizedBox.shrink(),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class HomeSkeleton extends StatefulWidget {
  const HomeSkeleton({super.key});

  @override
  State<HomeSkeleton> createState() => _HomeSkeletonState();
}

class _HomeSkeletonState extends State<HomeSkeleton>
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
    if (MediaQuery.disableAnimationsOf(context)) {
      return _grid();
    }
    return FadeTransition(
      opacity: Tween(
        begin: 0.55,
        end: 1.0,
      ).animate(CurvedAnimation(parent: _pulse, curve: Curves.easeInOut)),
      child: _grid(),
    );
  }

  Widget _grid() {
    return Padding(
      padding: const EdgeInsets.only(top: 22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 148,
                height: 16,
                decoration: BoxDecoration(
                  color: BlueColors.skeleton,
                  borderRadius: BorderRadius.circular(6),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          SizedBox(
            height: 96,
            child: Row(
              children: [
                for (var i = 0; i < 3; i++) ...[
                  if (i > 0) const SizedBox(width: 10),
                  Container(
                    width: 96,
                    decoration: BoxDecoration(
                      color: BlueColors.skeleton,
                      borderRadius: BorderRadius.circular(18),
                    ),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 24),
          Container(
            width: 168,
            height: 16,
            decoration: BoxDecoration(
              color: BlueColors.skeleton,
              borderRadius: BorderRadius.circular(6),
            ),
          ),
          const SizedBox(height: 14),
          SizedBox(
            height: 228,
            child: Row(
              children: [
                Container(
                  width: 176,
                  decoration: BoxDecoration(
                    color: BlueColors.skeleton,
                    borderRadius: BorderRadius.circular(22),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Container(
                    decoration: BoxDecoration(
                      color: BlueColors.skeleton,
                      borderRadius: BorderRadius.circular(22),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class HomeErrorCard extends StatelessWidget {
  const HomeErrorCard({super.key, required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 24),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: BlueColors.border),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 24, 20, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const BlueGlyphIcon(
                BlueGlyph.warning,
                size: 26,
                color: BlueColors.error,
                strokeWidth: 1.9,
              ),
              const SizedBox(height: 13),
              const Text(
                "We couldn't load your services",
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 18,
                  height: 1.3,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 18 * -0.015,
                  color: BlueColors.ink,
                ),
              ),
              const SizedBox(height: 7),
              const Text(
                'Check your connection and try again. Your bookings and cart are safe.',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 14,
                  height: 1.5,
                  fontWeight: FontWeight.w400,
                  color: BlueColors.muted,
                ),
              ),
              const SizedBox(height: 17),
              HomeInkButton(label: 'Try again', height: 48, onPressed: onRetry),
            ],
          ),
        ),
      ),
    );
  }
}

class HomeInkButton extends StatelessWidget {
  const HomeInkButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.height = 50,
    this.expanded = false,
  });

  final String label;
  final VoidCallback onPressed;
  final double height;
  final bool expanded;

  @override
  Widget build(BuildContext context) {
    final button = BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      duration: const Duration(milliseconds: 140),
      child: Container(
        height: height,
        padding: expanded
            ? EdgeInsets.zero
            : const EdgeInsets.symmetric(horizontal: 26),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: BlueColors.ink,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: expanded ? 15.5 : 15,
            fontWeight: FontWeight.w700,
            color: BlueColors.white,
          ),
        ),
      ),
    );
    if (expanded) return SizedBox(width: double.infinity, child: button);
    return button;
  }
}

class SlotHoldCard extends StatelessWidget {
  const SlotHoldCard({
    super.key,
    required this.service,
    required this.when,
    required this.clock,
    required this.progress,
    required this.onContinue,
  });

  final String service;
  final String when;
  final String clock;
  final double progress;
  final VoidCallback onContinue;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 22),
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: BlueColors.border),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 15),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Row(
                children: [
                  _StatusDot(
                    colors: [BlueColors.gold, BlueColors.goldDeep],
                    size: 7,
                  ),
                  SizedBox(width: 8),
                  Text(
                    'SLOT RESERVED',
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 12.5,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 12.5 * 0.04,
                      color: BlueColors.ink,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 9),
              Text(
                service,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 17,
                  height: 1.3,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 17 * -0.015,
                  color: BlueColors.ink,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                when,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 13.5,
                  height: 1.45,
                  fontWeight: FontWeight.w500,
                  color: BlueColors.muted,
                ),
              ),
              const SizedBox(height: 13),
              Row(
                children: [
                  Expanded(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(2),
                      child: SizedBox(
                        height: 4,
                        child: Stack(
                          children: [
                            const ColoredBox(
                              color: BlueColors.sheetHairline,
                              child: SizedBox.expand(),
                            ),
                            FractionallySizedBox(
                              widthFactor: progress.clamp(0, 1),
                              child: const ColoredBox(
                                color: BlueColors.ink,
                                child: SizedBox.expand(),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 9),
                  Text(
                    clock,
                    style: const TextStyle(
                      fontFamily: BlueFonts.mono,
                      fontSize: 13,
                      fontWeight: FontWeight.w500,
                      color: BlueColors.ink,
                      fontFeatures: [FontFeature.tabularFigures()],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              const Text(
                "We're holding this time while you finish.",
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12,
                  height: 1.4,
                  fontWeight: FontWeight.w400,
                  color: BlueColors.muted,
                ),
              ),
              const SizedBox(height: 13),
              HomeInkButton(
                label: 'Continue checkout',
                expanded: true,
                onPressed: onContinue,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class NextVisitCard extends StatelessWidget {
  const NextVisitCard({
    super.key,
    required this.service,
    required this.when,
    required this.status,
    required this.onView,
  });

  final String service;
  final String when;
  final String status;
  final VoidCallback onView;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 22),
      child: Column(
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              const Expanded(
                child: Text(
                  'Next visit',
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 17,
                    height: 1.25,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 17 * -0.015,
                    color: BlueColors.ink,
                  ),
                ),
              ),
              HomeTextAction(label: 'View booking', onPressed: onView),
            ],
          ),
          const SizedBox(height: 11),
          DecoratedBox(
            decoration: BoxDecoration(
              color: BlueColors.white,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: BlueColors.border),
            ),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(
                children: [
                  Container(
                    width: 60,
                    height: 60,
                    decoration: BoxDecoration(
                      color: BlueColors.press,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    alignment: Alignment.center,
                    child: const Opacity(
                      opacity: 0.45,
                      child: BlueGlyphIcon(
                        BlueGlyph.photo,
                        size: 22,
                        strokeWidth: 1.6,
                      ),
                    ),
                  ),
                  const SizedBox(width: 13),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          service,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 15.5,
                            height: 1.3,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 15.5 * -0.01,
                            color: BlueColors.ink,
                          ),
                        ),
                        const SizedBox(height: 3),
                        Text(
                          when,
                          style: const TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 13,
                            height: 1.4,
                            fontWeight: FontWeight.w500,
                            color: BlueColors.muted,
                          ),
                        ),
                        const SizedBox(height: 6),
                        Row(
                          children: [
                            const _StatusDot(
                              colors: [BlueColors.verified],
                              size: 6,
                            ),
                            const SizedBox(width: 6),
                            Text(
                              status,
                              style: const TextStyle(
                                fontFamily: BlueFonts.jakarta,
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                                letterSpacing: 12 * 0.01,
                                color: BlueColors.verified,
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
          ),
        ],
      ),
    );
  }
}

class _StatusDot extends StatelessWidget {
  const _StatusDot({required this.colors, required this.size});

  final List<Color> colors;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: colors.length == 1 ? colors.first : null,
        gradient: colors.length > 1
            ? LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: colors,
              )
            : null,
      ),
    );
  }
}

class HomeBannerReveal extends StatelessWidget {
  const HomeBannerReveal({
    super.key,
    required this.visible,
    required this.child,
  });

  final bool visible;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return AnimatedSize(
      duration: BlueMotion.of(context, BlueMotion.page),
      curve: BlueMotion.curve,
      alignment: Alignment.topCenter,
      child: AnimatedOpacity(
        duration: BlueMotion.of(context, BlueMotion.page),
        curve: BlueMotion.curve,
        opacity: visible ? 1 : 0,
        child: visible
            ? child
            : const SizedBox(width: double.infinity, height: 0),
      ),
    );
  }
}
