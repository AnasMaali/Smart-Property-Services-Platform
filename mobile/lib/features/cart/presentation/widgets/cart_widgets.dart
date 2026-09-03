import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../../home/presentation/widgets/home_sections.dart';
import '../../../services/data/service_detail.dart';
import '../../../services/presentation/widgets/services_widgets.dart';
import '../../data/cart_models.dart';

const _crossFade = Duration(milliseconds: 220);
const _press = Duration(milliseconds: 160);

class CartHeader extends StatelessWidget {
  const CartHeader({super.key, required this.subtitle});

  final String subtitle;

  @override
  Widget build(BuildContext context) {
    return ServicesTitle(title: 'Cart', subtitle: subtitle);
  }
}

class CartEmptyState extends StatelessWidget {
  const CartEmptyState({super.key, required this.onBrowse});

  final VoidCallback onBrowse;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(28, 0, 28, 24),
      child: Column(
        children: [
          const Spacer(),
          const BlueGlyphIcon(
            BlueGlyph.cart,
            size: 56,
            color: BlueColors.glyph,
            strokeWidth: 1.55,
          ),
          const SizedBox(height: 22),
          const Text(
            'Your cart is empty',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 22,
              height: 1.2,
              fontWeight: FontWeight.w700,
              letterSpacing: 22 * -0.02,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 10),
          const Text(
            "Choose a service and we'll keep your configuration here until you're ready to book.",
            textAlign: TextAlign.center,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14.5,
              height: 1.5,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
          const SizedBox(height: 24),
          HomeInkButton(label: 'Browse services', onPressed: onBrowse),
          const Spacer(flex: 2),
        ],
      ),
    );
  }
}

class CartErrorState extends StatelessWidget {
  const CartErrorState({super.key, required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(28, 0, 28, 24),
      child: Column(
        children: [
          const Spacer(),
          const BlueGlyphIcon(
            BlueGlyph.warning,
            size: 36,
            color: BlueColors.error,
            strokeWidth: 1.85,
          ),
          const SizedBox(height: 18),
          const Text(
            "We couldn't load your cart",
            textAlign: TextAlign.center,
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
            'Your items are saved on your account — nothing is lost. Check your connection and try again.',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14.5,
              height: 1.5,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
          const SizedBox(height: 22),
          HomeInkButton(label: 'Try again', onPressed: onRetry),
          const Spacer(flex: 2),
        ],
      ),
    );
  }
}

class CartSkeleton extends StatefulWidget {
  const CartSkeleton({super.key});

  @override
  State<CartSkeleton> createState() => _CartSkeletonState();
}

class _CartSkeletonState extends State<CartSkeleton>
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
          child: ListView(
            physics: const NeverScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(
              BlueDimens.homeGutter,
              8,
              BlueDimens.homeGutter,
              24,
            ),
            children: [for (var i = 0; i < 3; i++) _row(i)],
          ),
        );
      },
    );
  }

  Widget _row(int i) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: BlueColors.sheetHairline)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 18),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: BlueDimens.cartThumbWidth,
              height: BlueDimens.cartThumbHeight,
              decoration: BoxDecoration(
                color: BlueColors.skeleton,
                borderRadius: BorderRadius.circular(BlueDimens.cartThumbRadius),
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: i.isEven ? 148 : 120,
                    height: 14,
                    decoration: BoxDecoration(
                      color: BlueColors.skeleton,
                      borderRadius: BorderRadius.circular(7),
                    ),
                  ),
                  const SizedBox(height: 10),
                  Container(
                    width: i.isEven ? 118 : 96,
                    height: 10,
                    decoration: BoxDecoration(
                      color: const Color(0xFFEDEEF4),
                      borderRadius: BorderRadius.circular(5),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Container(
                    width: BlueDimens.cartStepperWidth,
                    height: BlueDimens.cartStepperHeight,
                    decoration: BoxDecoration(
                      color: BlueColors.white,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: BlueColors.border, width: 1.5),
                    ),
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

class CartLineItem extends StatelessWidget {
  const CartLineItem({
    super.key,
    required this.item,
    required this.currencyCode,
    required this.decimalPlaces,
    required this.expanded,
    required this.detail,
    required this.onIncrement,
    required this.onDecrement,
    required this.onRemove,
    required this.onToggleConfig,
    required this.onChangeDetails,
  });

  final CartItem item;
  final String currencyCode;
  final int decimalPlaces;
  final bool expanded;
  final ServiceDetail? detail;
  final VoidCallback onIncrement;
  final VoidCallback onDecrement;
  final VoidCallback onRemove;
  final VoidCallback onToggleConfig;
  final VoidCallback onChangeDetails;

  static const _thumbs = [
    BlueColors.thumbCool,
    BlueColors.thumbMint,
    BlueColors.thumbLilac,
  ];

  @override
  Widget build(BuildContext context) {
    final unavailable = item.pricing.isUnavailable;
    final config = cartConfigLines(item, detail);
    final fill = _thumbs[item.service.name.hashCode.abs() % _thumbs.length];
    final imageUrl = item.service.image?.networkUrl;

    return Opacity(
      opacity: unavailable ? 0.78 : 1,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 18),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _Thumb(fill: fill, imageUrl: imageUrl),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Padding(
                          padding: const EdgeInsets.only(top: 1, right: 8),
                          child: Text(
                            item.service.name,
                            maxLines: 2,
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
                        ),
                      ),
                      _RemoveButton(onPressed: onRemove),
                    ],
                  ),
                  if (config.all.isNotEmpty) ...[
                    const SizedBox(height: 5),
                    _ConfigBlock(
                      config: config,
                      expanded: expanded,
                      onToggle: onToggleConfig,
                    ),
                  ],
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      CartStepper(
                        quantity: item.quantity,
                        enabled: !unavailable,
                        incrementEnabled: !item.quantityLocked,
                        onIncrement: onIncrement,
                        onDecrement: onDecrement,
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Align(
                          alignment: Alignment.centerRight,
                          child: _LinePrice(
                            item: item,
                            currencyCode: currencyCode,
                            decimalPlaces: decimalPlaces,
                          ),
                        ),
                      ),
                    ],
                  ),
                  if (unavailable) ...[
                    const SizedBox(height: 10),
                    const _Note(
                      icon: BlueGlyph.warning,
                      color: BlueColors.unavailableText,
                      text:
                          "This service isn't available with the current details. Remove it to continue, or open the service to change the configuration.",
                    ),
                  ] else if (item.pricing.isMissingContext) ...[
                    const SizedBox(height: 10),
                    const _Note(
                      icon: BlueGlyph.info,
                      color: BlueColors.muted,
                      text:
                          "We'll confirm this price once you add the booking details at checkout.",
                    ),
                  ],
                  const SizedBox(height: 10),
                  _ChangeDetails(onPressed: onChangeDetails),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Thumb extends StatelessWidget {
  const _Thumb({required this.fill, this.imageUrl});

  final Color fill;
  final String? imageUrl;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(BlueDimens.cartThumbRadius),
      child: SizedBox(
        width: BlueDimens.cartThumbWidth,
        height: BlueDimens.cartThumbHeight,
        child: ColoredBox(
          color: fill,
          child: imageUrl == null
              ? const Center(
                  child: BlueGlyphIcon(
                    BlueGlyph.photo,
                    size: 20,
                    color: BlueColors.glyph,
                    strokeWidth: 1.5,
                  ),
                )
              : Image.network(
                  imageUrl!,
                  fit: BoxFit.cover,
                  errorBuilder: (_, _, _) {
                    return const Center(
                      child: BlueGlyphIcon(
                        BlueGlyph.photo,
                        size: 20,
                        color: BlueColors.glyph,
                        strokeWidth: 1.5,
                      ),
                    );
                  },
                ),
        ),
      ),
    );
  }
}

class _RemoveButton extends StatelessWidget {
  const _RemoveButton({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.88,
      duration: _press,
      child: const SizedBox(
        width: 44,
        height: 44,
        child: Align(
          alignment: Alignment.topRight,
          child: Padding(
            padding: EdgeInsets.only(top: 2),
            child: BlueGlyphIcon(
              BlueGlyph.close,
              size: 14,
              color: BlueColors.chevron,
              strokeWidth: 1.9,
            ),
          ),
        ),
      ),
    );
  }
}

class _ConfigBlock extends StatelessWidget {
  const _ConfigBlock({
    required this.config,
    required this.expanded,
    required this.onToggle,
  });

  final CartConfigLine config;
  final bool expanded;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    final shown = expanded ? config.all : config.visible;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          shown.join(' · '),
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13,
            height: 1.4,
            fontWeight: FontWeight.w500,
            color: BlueColors.muted,
          ),
        ),
        if (config.extra > 0) ...[
          const SizedBox(height: 3),
          BluePressable(
            onPressed: onToggle,
            scale: 0.98,
            duration: _press,
            child: Text(
              expanded ? 'Show less' : '+ ${config.extra} more',
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13,
                height: 1.3,
                fontWeight: FontWeight.w700,
                color: BlueColors.ink,
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class _ChangeDetails extends StatelessWidget {
  const _ChangeDetails({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.98,
      duration: _press,
      child: const Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            'Change details',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13.5,
              height: 1.2,
              fontWeight: FontWeight.w700,
              color: BlueColors.ink,
            ),
          ),
          SizedBox(width: 4),
          BlueGlyphIcon(
            BlueGlyph.chevronRight,
            size: 12,
            color: BlueColors.ink,
            strokeWidth: 2.2,
          ),
        ],
      ),
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.icon, required this.color, required this.text});

  final BlueGlyph icon;
  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 2),
          child: BlueGlyphIcon(icon, size: 14, color: color, strokeWidth: 1.8),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            text,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              height: 1.45,
              fontWeight: FontWeight.w500,
              color: color,
            ),
          ),
        ),
      ],
    );
  }
}

class _LinePrice extends StatelessWidget {
  const _LinePrice({
    required this.item,
    required this.currencyCode,
    required this.decimalPlaces,
  });

  final CartItem item;
  final String currencyCode;
  final int decimalPlaces;

  @override
  Widget build(BuildContext context) {
    final pricing = item.pricing;
    if (pricing.isQuote) {
      return const _StatusChip(
        label: 'Quote required',
        fill: BlueColors.chipSurface,
        text: BlueColors.chipInk,
      );
    }
    if (pricing.isMissingContext) {
      return const _StatusChip(
        label: 'Price after details',
        fill: BlueColors.chipFill,
        text: BlueColors.body,
      );
    }
    if (pricing.isUnavailable) {
      return const _StatusChip(
        label: 'Unavailable',
        fill: BlueColors.unavailableFill,
        text: BlueColors.unavailableText,
      );
    }
    final total = cartMoneyLabel(
      pricing.lineTotal,
      code: currencyCode,
      decimalPlaces: decimalPlaces,
    );
    final each = item.quantity > 1
        ? '${cartMoneyLabel(pricing.unitTotal, code: currencyCode, decimalPlaces: decimalPlaces)} each'
        : null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        _MoneyText(value: total, size: 15.5),
        if (each != null) ...[
          const SizedBox(height: 2),
          Text(
            each,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 11.5,
              height: 1.2,
              fontWeight: FontWeight.w500,
              color: BlueColors.muted,
            ),
          ),
        ],
      ],
    );
  }
}

class _StatusChip extends StatelessWidget {
  const _StatusChip({
    required this.label,
    required this.fill,
    required this.text,
  });

  final String label;
  final Color fill;
  final Color text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: fill,
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: 12,
          height: 1.2,
          fontWeight: FontWeight.w600,
          color: text,
        ),
      ),
    );
  }
}

class CartStepper extends StatelessWidget {
  const CartStepper({
    super.key,
    required this.quantity,
    required this.enabled,
    required this.onIncrement,
    required this.onDecrement,
    this.incrementEnabled = true,
  });

  final int quantity;
  final bool enabled;
  final bool incrementEnabled;
  final VoidCallback onIncrement;
  final VoidCallback onDecrement;

  @override
  Widget build(BuildContext context) {
    final canMinus = enabled && quantity > 1;
    return Opacity(
      opacity: enabled ? 1 : 0.45,
      child: Container(
        width: BlueDimens.cartStepperWidth,
        height: BlueDimens.cartStepperHeight,
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: BlueColors.border, width: 1.5),
        ),
        child: Row(
          children: [
            _StepHit(
              glyph: BlueGlyph.minus,
              enabled: canMinus,
              onPressed: onDecrement,
            ),
            Expanded(
              child: Center(
                child: AnimatedSwitcher(
                  duration: BlueMotion.of(context, _crossFade),
                  switchInCurve: BlueMotion.curve,
                  switchOutCurve: BlueMotion.exitCurve,
                  child: Text(
                    '$quantity',
                    key: ValueKey(quantity),
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15,
                      height: 1,
                      fontWeight: FontWeight.w700,
                      fontFeatures: [FontFeature.tabularFigures()],
                      color: BlueColors.ink,
                    ),
                  ),
                ),
              ),
            ),
            _StepHit(
              glyph: BlueGlyph.plus,
              enabled: enabled && incrementEnabled,
              onPressed: onIncrement,
            ),
          ],
        ),
      ),
    );
  }
}

class _StepHit extends StatelessWidget {
  const _StepHit({
    required this.glyph,
    required this.enabled,
    required this.onPressed,
  });

  final BlueGlyph glyph;
  final bool enabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      enabled: enabled,
      onPressed: enabled ? onPressed : null,
      scale: 0.9,
      duration: _press,
      child: SizedBox(
        width: 42,
        height: BlueDimens.cartStepperHeight,
        child: Center(
          child: BlueGlyphIcon(
            glyph,
            size: 14,
            color: enabled ? BlueColors.ink : BlueColors.dash,
            strokeWidth: 2.05,
          ),
        ),
      ),
    );
  }
}

class CartSummary extends StatelessWidget {
  const CartSummary({super.key, required this.cart});

  final CartSnapshot cart;

  @override
  Widget build(BuildContext context) {
    final fully = cart.fullyPriced;
    final totalLabel = fully ? 'Total' : 'Priced now';
    final totalValue = cartMoneyLabel(
      cart.pricedNow,
      code: cart.currency.code,
      decimalPlaces: cart.currency.decimalPlaces,
    );
    final unpriced = cart.items
        .where((item) => item.pricing.isQuote || item.pricing.isMissingContext)
        .length;
    final blocked = cart.blockedCount;

    final String note;
    if (blocked > 0) {
      note =
          'Remove the unavailable item to continue. Nothing is charged until you confirm at checkout.';
    } else if (!fully) {
      final noun = unpriced == 1 ? 'One service is' : '$unpriced services are';
      note =
          'The total covers priced services only. $noun priced separately before anything is charged.';
    } else {
      note =
          'Confirmed by BLUE before payment. Location and appointment are chosen at checkout.';
    }

    return Padding(
      padding: const EdgeInsets.only(top: 8, bottom: 18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Summary',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 17,
              height: 1.2,
              fontWeight: FontWeight.w700,
              letterSpacing: 17 * -0.015,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 14),
          for (final item in cart.items)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: _SummaryRow(
                item: item,
                currencyCode: cart.currency.code,
                decimalPlaces: cart.currency.decimalPlaces,
              ),
            ),
          const ColoredBox(
            color: BlueColors.sheetHairline,
            child: SizedBox(height: 1, width: double.infinity),
          ),
          const SizedBox(height: 14),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(
                  totalLabel,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 16,
                    height: 1.2,
                    fontWeight: FontWeight.w700,
                    color: BlueColors.ink,
                  ),
                ),
              ),
              _MoneyText(value: totalValue, size: 18),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            note,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              height: 1.45,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
        ],
      ),
    );
  }
}

class _SummaryRow extends StatelessWidget {
  const _SummaryRow({
    required this.item,
    required this.currencyCode,
    required this.decimalPlaces,
  });

  final CartItem item;
  final String currencyCode;
  final int decimalPlaces;

  @override
  Widget build(BuildContext context) {
    final name = item.quantity > 1
        ? '${item.service.name} x ${item.quantity}'
        : item.service.name;
    final Widget trailing;
    if (item.pricing.isQuote) {
      trailing = const _StatusChip(
        label: 'Quote required',
        fill: BlueColors.chipSurface,
        text: BlueColors.chipInk,
      );
    } else if (item.pricing.isMissingContext) {
      trailing = const Text(
        'Price after details',
        style: TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: 13.5,
          fontWeight: FontWeight.w600,
          color: BlueColors.muted,
        ),
      );
    } else if (item.pricing.isUnavailable) {
      trailing = const Text(
        'Unavailable',
        style: TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: 13.5,
          fontWeight: FontWeight.w600,
          color: BlueColors.unavailableText,
        ),
      );
    } else {
      trailing = _MoneyText(
        value: cartMoneyLabel(
          item.pricing.lineTotal,
          code: currencyCode,
          decimalPlaces: decimalPlaces,
        ),
        size: 14.5,
      );
    }
    return Row(
      children: [
        Expanded(
          child: Text(
            name,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14,
              height: 1.3,
              fontWeight: FontWeight.w500,
              color: BlueColors.ink,
            ),
          ),
        ),
        const SizedBox(width: 12),
        trailing,
      ],
    );
  }
}

class CartCheckoutBar extends StatelessWidget {
  const CartCheckoutBar({
    super.key,
    required this.cart,
    required this.onCheckout,
  });

  final CartSnapshot cart;
  final VoidCallback onCheckout;

  @override
  Widget build(BuildContext context) {
    final blocked = cart.checkoutBlocked;
    final total = cartMoneyLabel(
      cart.pricedNow,
      code: cart.currency.code,
      decimalPlaces: cart.currency.decimalPlaces,
    );
    final caption = blocked
        ? (cart.blockedCount == 1
              ? '1 item needs attention'
              : '${cart.blockedCount} items need attention')
        : cart.fullyPriced
        ? (cart.unitCount == 1 ? '1 unit' : '${cart.unitCount} units')
        : 'Priced items only';

    return DecoratedBox(
      decoration: const BoxDecoration(
        color: BlueColors.white,
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: SizedBox(
        height: BlueDimens.cartBarHeight,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 11, 16, 11),
          child: Row(
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  _MoneyText(value: total, size: 17),
                  const SizedBox(height: 3),
                  Text(
                    caption,
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 12,
                      height: 1.2,
                      fontWeight: FontWeight.w500,
                      color: blocked
                          ? BlueColors.unavailableText
                          : BlueColors.muted,
                    ),
                  ),
                ],
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _CheckoutButton(
                  enabled: !blocked,
                  onPressed: onCheckout,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CheckoutButton extends StatelessWidget {
  const _CheckoutButton({required this.enabled, required this.onPressed});

  final bool enabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      enabled: enabled,
      onPressed: enabled ? onPressed : null,
      scale: 0.985,
      duration: _press,
      child: AnimatedContainer(
        duration: BlueMotion.of(context, BlueMotion.snap),
        curve: BlueMotion.curve,
        height: BlueDimens.cartCtaHeight,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: enabled ? BlueColors.ink : BlueColors.ctaDisabled,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Text(
          'Proceed to checkout',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 14.5,
            fontWeight: FontWeight.w700,
            color: enabled ? BlueColors.white : BlueColors.ctaDisabledText,
          ),
        ),
      ),
    );
  }
}

class CartUndoToast extends StatelessWidget {
  const CartUndoToast({super.key, required this.name, required this.onUndo});

  final String name;
  final VoidCallback onUndo;

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
            Expanded(
              child: Text(
                '$name removed',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: BlueColors.white,
                ),
              ),
            ),
            BluePressable(
              onPressed: onUndo,
              child: Container(
                height: 44,
                padding: const EdgeInsets.symmetric(horizontal: 14),
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: const Color(0x1FFFFFFF),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Text(
                  'Undo',
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

class _MoneyText extends StatelessWidget {
  const _MoneyText({required this.value, required this.size});

  final String value;
  final double size;

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: BlueMotion.of(context, _crossFade),
      switchInCurve: BlueMotion.curve,
      switchOutCurve: BlueMotion.exitCurve,
      child: Text(
        value,
        key: ValueKey(value),
        style: TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: size,
          height: 1.15,
          fontWeight: FontWeight.w800,
          letterSpacing: size * -0.01,
          fontFeatures: const [FontFeature.tabularFigures()],
          color: BlueColors.ink,
        ),
      ),
    );
  }
}
