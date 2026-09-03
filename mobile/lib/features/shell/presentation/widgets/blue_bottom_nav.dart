import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../home/presentation/widgets/home_icons.dart';

enum BlueTab { home, cart, bookings, contracts, account }

class BlueBottomNav extends StatelessWidget {
  const BlueBottomNav({
    super.key,
    required this.current,
    required this.onSelect,
    this.cartCount = 0,
  });

  final BlueTab current;
  final ValueChanged<BlueTab> onSelect;
  final int cartCount;

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: BlueColors.navBar,
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(6, 9, 6, bottom > 0 ? bottom : 12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (final tab in BlueTab.values)
              Expanded(
                child: _NavItem(
                  tab: tab,
                  selected: tab == current,
                  badge: tab == BlueTab.cart && cartCount > 0
                      ? (cartCount > 9 ? '9+' : '$cartCount')
                      : null,
                  onPressed: () => onSelect(tab),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.tab,
    required this.selected,
    required this.onPressed,
    this.badge,
  });

  final BlueTab tab;
  final bool selected;
  final VoidCallback onPressed;
  final String? badge;

  String get _label => switch (tab) {
    BlueTab.home => 'Home',
    BlueTab.cart => 'Cart',
    BlueTab.bookings => 'Bookings',
    BlueTab.contracts => 'Contracts',
    BlueTab.account => 'Account',
  };

  BlueGlyph get _glyph => switch (tab) {
    BlueTab.home => BlueGlyph.home,
    BlueTab.cart => BlueGlyph.cart,
    BlueTab.bookings => BlueGlyph.bookings,
    BlueTab.contracts => BlueGlyph.contracts,
    BlueTab.account => BlueGlyph.account,
  };

  String get _semantic {
    if (tab == BlueTab.cart && badge != null) {
      return selected ? 'Cart, $badge items, selected' : 'Cart, $badge items';
    }
    return selected ? '$_label, selected' : _label;
  }

  @override
  Widget build(BuildContext context) {
    final color = selected ? BlueColors.ink : BlueColors.placeholder;
    final stroke = selected ? 2.15 : 1.8;
    return Semantics(
      button: true,
      selected: selected,
      label: _semantic,
      excludeSemantics: true,
      onTap: onPressed,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: () {
          if (!selected) BlueMotion.tap();
          onPressed();
        },
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 52),
          child: Padding(
            padding: const EdgeInsets.only(top: 5),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                SizedBox(
                  width: 26,
                  height: 22,
                  child: Stack(
                    clipBehavior: Clip.none,
                    alignment: Alignment.center,
                    children: [
                      TweenAnimationBuilder<Color?>(
                        tween: ColorTween(
                          begin: BlueColors.placeholder,
                          end: color,
                        ),
                        duration: BlueMotion.of(context, BlueMotion.snap),
                        curve: BlueMotion.curve,
                        builder: (context, animatedColor, _) {
                          return TweenAnimationBuilder<double>(
                            tween: Tween(begin: 1.8, end: stroke),
                            duration: BlueMotion.of(context, BlueMotion.snap),
                            curve: BlueMotion.curve,
                            builder: (context, value, _) {
                              return BlueGlyphIcon(
                                _glyph,
                                size: 22,
                                color: animatedColor ?? color,
                                strokeWidth: value,
                              );
                            },
                          );
                        },
                      ),
                      if (badge != null)
                        Positioned(
                          top: -4,
                          right: -6,
                          child: _CartBadge(label: badge!),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 5),
                AnimatedDefaultTextStyle(
                  duration: BlueMotion.of(context, BlueMotion.snap),
                  curve: BlueMotion.curve,
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 10.5,
                    fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                    letterSpacing: 10.5 * 0.005,
                    height: 1,
                    color: color,
                  ),
                  child: Text(_label),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _CartBadge extends StatelessWidget {
  const _CartBadge({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0.7, end: 1),
      duration: BlueMotion.of(context, BlueMotion.tile),
      curve: BlueMotion.curve,
      builder: (context, value, child) {
        return Transform.scale(scale: value, child: child);
      },
      child: Container(
        constraints: const BoxConstraints(minWidth: 17, minHeight: 17),
        padding: const EdgeInsets.symmetric(horizontal: 4),
        decoration: BoxDecoration(
          color: BlueColors.ink,
          borderRadius: BorderRadius.circular(9),
          border: Border.all(color: BlueColors.white, width: 2),
        ),
        alignment: Alignment.center,
        child: Text(
          label,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 10.5,
            fontWeight: FontWeight.w800,
            height: 1,
            color: BlueColors.white,
          ),
        ),
      ),
    );
  }
}
