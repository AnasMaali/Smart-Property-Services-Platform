import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../../home/presentation/widgets/home_sections.dart';
import '../../../services/presentation/widgets/services_widgets.dart';
import '../../data/checkout_models.dart';

const _sectionFade = Duration(milliseconds: 240);
const _priceFade = Duration(milliseconds: 220);
const _reviewFade = Duration(milliseconds: 180);
const _press = Duration(milliseconds: 140);

const _goldLine = LinearGradient(
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

const _goldDot = LinearGradient(
  begin: Alignment.topLeft,
  end: Alignment.bottomRight,
  colors: [
    BlueColors.goldDeep,
    BlueColors.gold,
    BlueColors.goldMid,
    BlueColors.goldWarm,
  ],
);

class CheckoutBackButton extends StatelessWidget {
  const CheckoutBackButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(-11, 0),
      child: ServicesBackButton(onPressed: onPressed),
    );
  }
}

class CheckoutTitle extends StatelessWidget {
  const CheckoutTitle({
    super.key,
    required this.subtitle,
    this.title = 'Checkout',
    this.subtitleMaxWidth = 300,
  });

  final String title;
  final String subtitle;
  final double subtitleMaxWidth;

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
          ConstrainedBox(
            constraints: BoxConstraints(maxWidth: subtitleMaxWidth),
            child: Text(
              subtitle,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.45,
                fontWeight: FontWeight.w500,
                color: BlueColors.muted,
              ),
            ),
          ),
        ],
      ],
    );
  }
}

class CheckoutSectionHead extends StatelessWidget {
  const CheckoutSectionHead({
    super.key,
    required this.glyph,
    required this.title,
    this.flag,
    this.flagColor,
    this.trailing,
  });

  final BlueGlyph glyph;
  final String title;
  final String? flag;
  final Color? flagColor;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        BlueGlyphIcon(glyph, size: BlueDimens.checkoutIcon, strokeWidth: 1.9),
        const SizedBox(width: 9),
        Expanded(
          child: Text(
            title,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 15,
              fontWeight: FontWeight.w700,
              letterSpacing: 15 * -0.01,
              color: BlueColors.ink,
            ),
          ),
        ),
        if (trailing != null)
          trailing!
        else if (flag != null)
          Text(
            flag!,
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
              letterSpacing: 0.02 * 11.5,
              color: flagColor ?? BlueColors.body,
            ),
          ),
      ],
    );
  }
}

class CheckoutGhostButton extends StatelessWidget {
  const CheckoutGhostButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.height = 48,
    this.horizontal = 20,
    this.radius = 16,
    this.size = 14.5,
  });

  final String label;
  final VoidCallback onPressed;
  final double height;
  final double horizontal;
  final double radius;
  final double size;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      duration: _press,
      child: AnimatedContainer(
        duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
        curve: BlueMotion.curve,
        height: height,
        padding: EdgeInsets.symmetric(horizontal: horizontal),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(radius),
          border: Border.all(color: BlueColors.ghostLine),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: size,
            fontWeight: FontWeight.w700,
            color: BlueColors.ink,
          ),
        ),
      ),
    );
  }
}

class CheckoutTextLink extends StatelessWidget {
  const CheckoutTextLink({
    super.key,
    required this.label,
    required this.onPressed,
    this.height = 40,
    this.chevron = true,
  });

  final String label;
  final VoidCallback onPressed;
  final double height;
  final bool chevron;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.98,
      duration: _press,
      child: SizedBox(
        height: height,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              label,
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: chevron ? 13.5 : 13,
                fontWeight: FontWeight.w700,
                color: BlueColors.ink,
              ),
            ),
            if (chevron) ...[
              const SizedBox(width: 6),
              const BlueGlyphIcon(
                BlueGlyph.chevronRight,
                size: 13,
                strokeWidth: 2.3,
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class CheckoutLocationBlock extends StatelessWidget {
  const CheckoutLocationBlock({
    super.key,
    required this.view,
    required this.onAdd,
    required this.onChange,
  });

  final CheckoutReviewView view;
  final VoidCallback onAdd;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        20,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CheckoutSectionHead(
            glyph: BlueGlyph.pin,
            title: 'Service location',
            flag: view.locFlag,
            flagColor: view.locFlagColor,
          ),
          AnimatedSwitcher(
            duration: BlueMotion.of(context, _sectionFade),
            switchInCurve: Curves.easeOut,
            switchOutCurve: Curves.easeOut,
            child: view.locDone
                ? _LocationSaved(
                    key: const ValueKey('saved'),
                    title: view.locTitle,
                    line1: view.locLine1,
                    line2: view.locLine2,
                    onChange: onChange,
                  )
                : _LocationNeeded(key: const ValueKey('needed'), onAdd: onAdd),
          ),
        ],
      ),
    );
  }
}

class _LocationSaved extends StatelessWidget {
  const _LocationSaved({
    super.key,
    required this.title,
    required this.line1,
    required this.line2,
    required this.onChange,
  });

  final String title;
  final String line1;
  final String line2;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 11),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14,
              height: 1.45,
              fontWeight: FontWeight.w700,
              letterSpacing: 14 * -0.008,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 3),
          Text(
            [line1, line2].where((line) => line.isNotEmpty).join('\n'),
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13.5,
              height: 1.55,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
          CheckoutTextLink(label: 'Change', onPressed: onChange),
        ],
      ),
    );
  }
}

class _LocationNeeded extends StatelessWidget {
  const _LocationNeeded({super.key, required this.onAdd});

  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 9),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 290),
            child: const Text(
              'Add the address where the service will take place.',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 13),
          CheckoutGhostButton(label: 'Add location', onPressed: onAdd),
        ],
      ),
    );
  }
}

class CheckoutAppointmentBlock extends StatelessWidget {
  const CheckoutAppointmentBlock({
    super.key,
    required this.view,
    required this.onAdd,
    required this.onChange,
  });

  final CheckoutReviewView view;
  final VoidCallback onAdd;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        20,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CheckoutSectionHead(
            glyph: BlueGlyph.bookings,
            title: 'Appointment',
            flag: view.apptFlag,
            flagColor: view.apptFlagColor,
          ),
          AnimatedSwitcher(
            duration: BlueMotion.of(context, _sectionFade),
            switchInCurve: Curves.easeOut,
            switchOutCurve: Curves.easeOut,
            child: view.apptDone
                ? _AppointmentHeld(
                    key: const ValueKey('held'),
                    date: view.apptDate,
                    window: view.apptWindow,
                    holdText: view.holdText,
                    holdColor: view.holdColor,
                    onChange: onChange,
                  )
                : view.apptExpired
                ? _AppointmentExpired(
                    key: const ValueKey('expired'),
                    copy: view.expiredCopy,
                    onChange: onChange,
                  )
                : _AppointmentNeeded(
                    key: const ValueKey('needed'),
                    onAdd: onAdd,
                  ),
          ),
        ],
      ),
    );
  }
}

class _AppointmentHeld extends StatelessWidget {
  const _AppointmentHeld({
    super.key,
    required this.date,
    required this.window,
    required this.holdText,
    required this.holdColor,
    required this.onChange,
  });

  final String date;
  final String window;
  final String holdText;
  final Color holdColor;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 11),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            date,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14,
              height: 1.45,
              fontWeight: FontWeight.w700,
              letterSpacing: 14 * -0.008,
              color: BlueColors.ink,
            ),
          ),
          const SizedBox(height: 3),
          Text(
            window,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13.5,
              height: 1.5,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              Container(
                width: BlueDimens.checkoutHoldDot,
                height: BlueDimens.checkoutHoldDot,
                decoration: const BoxDecoration(
                  gradient: _goldDot,
                  borderRadius: BorderRadius.all(Radius.circular(4)),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                holdText,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 0.005 * 13,
                  fontFeatures: const [FontFeature.tabularFigures()],
                  color: holdColor,
                ),
              ),
            ],
          ),
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: CheckoutTextLink(label: 'Change time', onPressed: onChange),
          ),
        ],
      ),
    );
  }
}

class _AppointmentExpired extends StatelessWidget {
  const _AppointmentExpired({
    super.key,
    required this.copy,
    required this.onChange,
  });

  final String copy;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 11),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Your reserved time has expired',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14,
              height: 1.45,
              fontWeight: FontWeight.w700,
              letterSpacing: 14 * -0.008,
              color: BlueColors.unavailableInk,
            ),
          ),
          const SizedBox(height: 4),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 295),
            child: Text(
              copy,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 13),
          CheckoutGhostButton(
            label: 'Choose another time',
            onPressed: onChange,
          ),
        ],
      ),
    );
  }
}

class _AppointmentNeeded extends StatelessWidget {
  const _AppointmentNeeded({super.key, required this.onAdd});

  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 9),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 290),
            child: const Text(
              'Choose a date and time for your service. We hold your slot for 10 minutes while you pay.',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 13),
          CheckoutGhostButton(label: 'Choose appointment', onPressed: onAdd),
        ],
      ),
    );
  }
}

class CheckoutOrderBlock extends StatelessWidget {
  const CheckoutOrderBlock({
    super.key,
    required this.view,
    required this.onEditCart,
    required this.onChangeLocation,
  });

  final CheckoutReviewView view;
  final VoidCallback onEditCart;
  final VoidCallback onChangeLocation;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        20,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CheckoutSectionHead(
            glyph: BlueGlyph.cart,
            title: 'Your order',
            trailing: CheckoutTextLink(
              label: 'Edit cart',
              onPressed: onEditCart,
              height: 36,
              chevron: false,
            ),
          ),
          const SizedBox(height: 14),
          Column(
            children: [
              for (var i = 0; i < view.lines.length; i++) ...[
                if (i > 0) const SizedBox(height: 16),
                _OrderLine(line: view.lines[i]),
              ],
            ],
          ),
          if (view.showFix) ...[
            const SizedBox(height: 16),
            Wrap(
              spacing: 9,
              runSpacing: 9,
              children: [
                CheckoutGhostButton(
                  label: 'Change location',
                  onPressed: onChangeLocation,
                  height: 44,
                  horizontal: 16,
                  radius: 14,
                  size: 13.5,
                ),
                CheckoutGhostButton(
                  label: 'Edit cart',
                  onPressed: onEditCart,
                  height: 44,
                  horizontal: 16,
                  radius: 14,
                  size: 13.5,
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _OrderLine extends StatelessWidget {
  const _OrderLine({required this.line});

  final CheckoutLineView line;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                line.name,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 14,
                  height: 1.35,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 14 * -0.008,
                  color: line.nameColor,
                ),
              ),
              if (line.config.isNotEmpty) ...[
                const SizedBox(height: 3),
                Text(
                  line.config,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12.5,
                    height: 1.45,
                    fontWeight: FontWeight.w400,
                    color: BlueColors.muted,
                  ),
                ),
              ],
              if (line.note.isNotEmpty) ...[
                const SizedBox(height: 3),
                Text(
                  line.note,
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12.5,
                    height: 1.45,
                    fontWeight: FontWeight.w500,
                    color: line.noteColor,
                  ),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(width: 12),
        Column(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            if (line.priced)
              _PriceText(value: line.amount, size: 14.5)
            else if (line.chip.isNotEmpty)
              Container(
                padding: const EdgeInsets.fromLTRB(8, 3, 8, 3),
                decoration: BoxDecoration(
                  color: line.chipBg,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: line.chipBorder),
                ),
                child: Text(
                  line.chip,
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 0.005 * 12.5,
                    color: line.chipColor,
                  ),
                ),
              ),
            if (line.showQty) ...[
              const SizedBox(height: 3),
              Text(
                line.qtyText,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 11.5,
                  fontWeight: FontWeight.w500,
                  color: BlueColors.placeholder,
                ),
              ),
            ],
          ],
        ),
      ],
    );
  }
}

class CheckoutTotalBlock extends StatelessWidget {
  const CheckoutTotalBlock({super.key, required this.view});

  final CheckoutReviewView view;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        18,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.baseline,
            textBaseline: TextBaseline.alphabetic,
            children: [
              Text(
                view.totalLabel,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 15,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 15 * -0.01,
                  color: BlueColors.ink,
                ),
              ),
              const Spacer(),
              if (view.hasTotal)
                _PriceText(
                  value: view.totalValue,
                  size: 20,
                  keyValue: view.totalKey,
                )
              else
                Container(
                  padding: const EdgeInsets.fromLTRB(9, 4, 9, 4),
                  decoration: BoxDecoration(
                    color: BlueColors.chipSurface,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: BlueColors.border),
                  ),
                  child: Text(
                    view.totalChip,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 13.5,
                      fontWeight: FontWeight.w600,
                      color: BlueColors.chipInk,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 9),
          Text(
            view.totalNote,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              height: 1.5,
              fontWeight: FontWeight.w400,
              color: BlueColors.placeholder,
            ),
          ),
        ],
      ),
    );
  }
}

class CheckoutEmptyState extends StatelessWidget {
  const CheckoutEmptyState({super.key, required this.onBrowse});

  final VoidCallback onBrowse;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        110,
        BlueDimens.checkoutGutter,
        24,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const BlueGlyphIcon(
            BlueGlyph.cart,
            size: 28,
            color: BlueColors.chevron,
            strokeWidth: 1.7,
          ),
          const SizedBox(height: 16),
          const Text(
            'Your cart is empty',
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
            constraints: const BoxConstraints(maxWidth: 280),
            child: Text(
              'Add a service before continuing. Any location or appointment you pick will wait for you here.',
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                height: 1.5,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          const SizedBox(height: 20),
          HomeInkButton(label: 'Browse services', onPressed: onBrowse),
        ],
      ),
    );
  }
}

class CheckoutErrorState extends StatelessWidget {
  const CheckoutErrorState({
    super.key,
    required this.onRetry,
    required this.onBack,
  });

  final VoidCallback onRetry;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        90,
        BlueDimens.checkoutGutter,
        24,
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
            "We couldn't load your checkout",
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
            constraints: const BoxConstraints(maxWidth: 292),
            child: Text(
              'Your cart, location and appointment are saved on your account. Check your connection and try again.',
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
              HomeInkButton(label: 'Try again', onPressed: onRetry, height: 48),
              const SizedBox(width: 10),
              CheckoutGhostButton(
                label: 'Back to cart',
                onPressed: onBack,
                height: 48,
                horizontal: 20,
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class CheckoutSkeleton extends StatefulWidget {
  const CheckoutSkeleton({super.key});

  @override
  State<CheckoutSkeleton> createState() => _CheckoutSkeletonState();
}

class _CheckoutSkeletonState extends State<CheckoutSkeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;

  static const _rows = [
    [124.0, 232.0, 168.0],
    [108.0, 214.0, 152.0],
    [96.0, 246.0, 176.0],
  ];

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
          child: const Padding(
            padding: EdgeInsets.symmetric(
              horizontal: BlueDimens.checkoutGutter,
            ),
            child: _SkeletonBody(),
          ),
        );
      },
    );
  }
}

class _SkeletonBody extends StatelessWidget {
  const _SkeletonBody();

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        const SizedBox(height: 22),
        const Divider(height: 1, thickness: 1, color: BlueColors.navLine),
        for (final row in _CheckoutSkeletonState._rows)
          Padding(
            padding: const EdgeInsets.only(top: 20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    _bone(17, 17, 5),
                    const SizedBox(width: 9),
                    _bone(row[0], 13, 5),
                  ],
                ),
                const SizedBox(height: 13),
                _bone(row[1], 12, 5),
                const SizedBox(height: 9),
                _bone(row[2], 12, 5),
                const SizedBox(height: 22),
                const Divider(
                  height: 1,
                  thickness: 1,
                  color: BlueColors.navLine,
                ),
              ],
            ),
          ),
        const Padding(
          padding: EdgeInsets.only(top: 20),
          child: Row(
            children: [
              _Bone(width: 76, height: 14, radius: 5),
              Spacer(),
              _Bone(width: 104, height: 20, radius: 6),
            ],
          ),
        ),
      ],
    );
  }

  static Widget _bone(double width, double height, double radius) {
    return _Bone(width: width, height: height, radius: radius);
  }
}

class _Bone extends StatelessWidget {
  const _Bone({
    required this.width,
    required this.height,
    required this.radius,
  });

  final double width;
  final double height;
  final double radius;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: BlueColors.skeleton,
        borderRadius: BorderRadius.circular(radius),
      ),
    );
  }
}

class CheckoutPayBar extends StatelessWidget {
  const CheckoutPayBar({super.key, required this.view, required this.onPay});

  final CheckoutReviewView view;
  final VoidCallback onPay;

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.paddingOf(context).bottom;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: BlueColors.barFill,
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          BlueDimens.checkoutGutter,
          12,
          BlueDimens.checkoutGutter,
          bottom < 30 ? 30 : bottom,
        ),
        child: Row(
          children: [
            ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 136),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  _PriceText(
                    value: view.barTotal,
                    size: 17,
                    keyValue: view.totalKey,
                  ),
                  const SizedBox(height: 1),
                  Text(
                    view.barCaption,
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
              child: _PayButton(enabled: !view.ctaOff, onPressed: onPay),
            ),
          ],
        ),
      ),
    );
  }
}

class _PayButton extends StatelessWidget {
  const _PayButton({required this.enabled, required this.onPressed});

  final bool enabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      enabled: enabled,
      onPressed: enabled ? onPressed : null,
      scale: 0.99,
      duration: _press,
      child: AnimatedContainer(
        duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
        curve: BlueMotion.curve,
        constraints: const BoxConstraints(
          minHeight: BlueDimens.checkoutCtaHeight,
        ),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: enabled ? BlueColors.ink : BlueColors.ctaDisabled,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Text(
          'Continue to payment',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 15.5,
            fontWeight: FontWeight.w700,
            letterSpacing: 15.5 * -0.005,
            color: enabled ? BlueColors.white : BlueColors.ctaDisabledText,
          ),
        ),
      ),
    );
  }
}

class CheckoutHairline extends StatelessWidget {
  const CheckoutHairline({super.key});

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.fromLTRB(
        BlueDimens.checkoutGutter,
        22,
        BlueDimens.checkoutGutter,
        0,
      ),
      child: Divider(height: 1, thickness: 1, color: BlueColors.navLine),
    );
  }
}

class _PriceText extends StatelessWidget {
  const _PriceText({required this.value, required this.size, this.keyValue});

  final String value;
  final double size;
  final String? keyValue;

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: BlueMotion.of(context, _priceFade),
      switchInCurve: Curves.easeOut,
      switchOutCurve: Curves.easeOut,
      child: Text(
        value,
        key: ValueKey(keyValue ?? value),
        style: TextStyle(
          fontFamily: BlueFonts.jakarta,
          fontSize: size,
          height: 1.15,
          fontWeight: FontWeight.w800,
          letterSpacing: size * -0.018,
          fontFeatures: const [FontFeature.tabularFigures()],
          color: BlueColors.ink,
        ),
      ),
    );
  }
}

Duration checkoutReviewFade(BuildContext context) {
  return BlueMotion.of(context, _reviewFade);
}
