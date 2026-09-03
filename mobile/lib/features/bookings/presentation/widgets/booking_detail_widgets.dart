import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../auth/presentation/widgets/blue_sheet.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../../services/presentation/widgets/services_widgets.dart';
import '../../data/booking_models.dart';

const _swap = Duration(milliseconds: 220);
const _press = Duration(milliseconds: 140);

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

class BookingDetailBackButton extends StatelessWidget {
  const BookingDetailBackButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(-11, 0),
      child: Semantics(
        button: true,
        label: 'Back to bookings',
        excludeSemantics: true,
        child: ServicesBackButton(onPressed: onPressed),
      ),
    );
  }
}

class BookingDetailAppBar extends StatelessWidget {
  const BookingDetailAppBar({
    super.key,
    required this.reference,
    required this.onBack,
  });

  final String reference;
  final VoidCallback onBack;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 52,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: BlueDimens.homeGutter),
        child: Row(
          children: [
            BookingDetailBackButton(onPressed: onBack),
            Expanded(
              child: reference.isEmpty
                  ? const SizedBox.shrink()
                  : Text(
                      reference,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      textAlign: TextAlign.right,
                      style: const TextStyle(
                        fontFamily: BlueFonts.mono,
                        fontSize: 12,
                        fontWeight: FontWeight.w400,
                        letterSpacing: 12 * 0.04,
                        color: BlueColors.placeholder,
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class BookingDetailBody extends StatelessWidget {
  const BookingDetailBody({
    super.key,
    required this.view,
    required this.onAction,
    required this.onAlert,
  });

  final BookingDetailView view;
  final ValueChanged<String> onAction;
  final VoidCallback onAlert;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: BlueDimens.homeGutter,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _StatusRow(chip: view.chip),
              const SizedBox(height: 12),
              Semantics(
                liveRegion: true,
                container: true,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 310),
                  child: AnimatedSwitcher(
                    duration: BlueMotion.of(context, _swap),
                    switchInCurve: BlueMotion.curve,
                    switchOutCurve: BlueMotion.exitCurve,
                    child: Text(
                      view.headline,
                      key: ValueKey(view.headline),
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 22,
                        height: 1.26,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 22 * -0.02,
                        color: BlueColors.ink,
                      ),
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 8),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 300),
                child: AnimatedSwitcher(
                  duration: BlueMotion.of(context, _swap),
                  switchInCurve: BlueMotion.curve,
                  switchOutCurve: BlueMotion.exitCurve,
                  child: Text(
                    view.nextUp,
                    key: ValueKey(view.nextUp),
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 13.5,
                      height: 1.5,
                      fontWeight: FontWeight.w400,
                      color: BlueColors.muted,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
        if (view.hasAlert)
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 16, 22, 0),
            child: _PaymentAlert(
              text: view.alertText,
              cta: view.alertCta,
              onPressed: onAlert,
            ),
          ),
        const _Hairline(top: 20),
        _SectionHead(glyph: BlueGlyph.calendar, title: 'Appointment'),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 11, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                view.apptDate,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 15,
                  height: 1.4,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 15 * -0.01,
                  color: view.apptMuted ? BlueColors.body : BlueColors.ink,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                view.apptWindow,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 13.5,
                  height: 1.5,
                  fontWeight: FontWeight.w500,
                  color: BlueColors.body,
                ),
              ),
              if (view.apptNote.isNotEmpty) ...[
                const SizedBox(height: 6),
                ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 300),
                  child: Text(
                    view.apptNote,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 12.5,
                      height: 1.45,
                      fontWeight: FontWeight.w400,
                      color: BlueColors.placeholder,
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
        const _Hairline(top: 20),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 18, 22, 0),
          child: Row(
            children: [
              const BlueGlyphIcon(BlueGlyph.cart, size: 17, strokeWidth: 1.9),
              const SizedBox(width: 9),
              Expanded(
                child: Text(
                  view.itemsTitle,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 15 * -0.01,
                    color: BlueColors.ink,
                  ),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        for (final item in view.items) _ItemBlock(item: item),
        const DecoratedBox(
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: BlueColors.navLine)),
          ),
          child: SizedBox(width: double.infinity, height: 0),
        ),
        _SectionHead(glyph: BlueGlyph.pin, title: 'Service location'),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 11, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (view.locTitle.isNotEmpty)
                Text(
                  view.locTitle,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 14,
                    height: 1.45,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 14 * -0.008,
                    color: BlueColors.ink,
                  ),
                ),
              if (view.locLine1.isNotEmpty || view.locLine2.isNotEmpty)
                Padding(
                  padding: EdgeInsets.only(top: view.locTitle.isEmpty ? 0 : 3),
                  child: Text(
                    [
                      if (view.locLine1.isNotEmpty) view.locLine1,
                      if (view.locLine2.isNotEmpty) view.locLine2,
                    ].join('\n'),
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 13.5,
                      height: 1.55,
                      fontWeight: FontWeight.w400,
                      color: BlueColors.muted,
                    ),
                  ),
                ),
              if (view.locExtra.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 7),
                  child: Text(
                    view.locExtra,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 12.5,
                      height: 1.45,
                      fontWeight: FontWeight.w400,
                      color: BlueColors.placeholder,
                    ),
                  ),
                ),
              if (view.hasContact)
                Padding(
                  padding: const EdgeInsets.only(top: 11),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.baseline,
                    textBaseline: TextBaseline.alphabetic,
                    children: [
                      const Text(
                        'Visit contact',
                        style: TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 12.5,
                          fontWeight: FontWeight.w500,
                          color: BlueColors.placeholder,
                        ),
                      ),
                      const SizedBox(width: 8),
                      Flexible(
                        child: Text(
                          view.contact,
                          style: const TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                            letterSpacing: 13 * 0.01,
                            color: BlueColors.ink,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ),
        if (view.hasTimeline) ...[
          const _Hairline(top: 20),
          const Padding(
            padding: EdgeInsets.fromLTRB(22, 18, 22, 0),
            child: Text(
              'Progress',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 15,
                fontWeight: FontWeight.w700,
                letterSpacing: 15 * -0.01,
                color: BlueColors.ink,
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 13, 22, 0),
            child: Column(
              children: [
                for (final event in view.timeline) _TimelineRow(event: event),
              ],
            ),
          ),
        ],
        if (view.hasPayment) ...[
          const _Hairline(top: 20),
          const Padding(
            padding: EdgeInsets.fromLTRB(22, 18, 22, 0),
            child: Text(
              'Payment',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 15,
                fontWeight: FontWeight.w700,
                letterSpacing: 15 * -0.01,
                color: BlueColors.ink,
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 12, 22, 0),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: [
                Expanded(
                  child: Text(
                    view.payState,
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 13.5,
                      fontWeight: FontWeight.w600,
                      color: view.payUnpaid
                          ? BlueColors.unavailableInk
                          : BlueColors.body,
                    ),
                  ),
                ),
                if (view.payAmount.isNotEmpty)
                  Text(
                    view.payAmount,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 16 * -0.014,
                      color: BlueColors.ink,
                    ),
                  ),
              ],
            ),
          ),
          if (view.payNote.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(22, 6, 22, 0),
              child: Text(
                view.payNote,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  height: 1.45,
                  fontWeight: FontWeight.w400,
                  color: BlueColors.placeholder,
                ),
              ),
            ),
        ],
        const _Hairline(top: 22),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 18, 22, 0),
          child: Column(
            children: [
              for (var i = 0; i < view.actions.length; i++) ...[
                if (i > 0) const SizedBox(height: 9),
                _ActionButton(
                  action: view.actions[i],
                  onPressed: () => onAction(view.actions[i].id),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _StatusRow extends StatelessWidget {
  const _StatusRow({required this.chip});

  final BookingDetailChip chip;

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: BlueMotion.of(context, _swap),
      switchInCurve: BlueMotion.curve,
      switchOutCurve: BlueMotion.exitCurve,
      child: Row(
        key: ValueKey('${chip.label}-${chip.dot}'),
        children: [
          if (chip.dot) ...[
            Container(
              width: BlueDimens.checkoutHoldDot,
              height: BlueDimens.checkoutHoldDot,
              decoration: const BoxDecoration(
                gradient: _goldDot,
                borderRadius: BorderRadius.all(Radius.circular(4)),
              ),
            ),
            const SizedBox(width: 9),
          ],
          DecoratedBox(
            decoration: BoxDecoration(
              color: chip.background,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: chip.border),
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
              child: Text(
                chip.label,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 12 * 0.02,
                  height: 1.25,
                  color: chip.color,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _SectionHead extends StatelessWidget {
  const _SectionHead({required this.glyph, required this.title});

  final BlueGlyph glyph;
  final String title;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(22, 18, 22, 0),
      child: Row(
        children: [
          BlueGlyphIcon(glyph, size: 17, strokeWidth: 1.9),
          const SizedBox(width: 9),
          Text(
            title,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 15,
              fontWeight: FontWeight.w700,
              letterSpacing: 15 * -0.01,
              color: BlueColors.ink,
            ),
          ),
        ],
      ),
    );
  }
}

class _ItemBlock extends StatelessWidget {
  const _ItemBlock({required this.item});

  final BookingDetailItemView item;

  @override
  Widget build(BuildContext context) {
    final chip = item.chip;
    return Semantics(
      container: true,
      label: item.a11y,
      excludeSemantics: true,
      child: DecoratedBox(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: BlueColors.navLine)),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(22, 15, 22, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (chip != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(
                    children: [
                      if (chip.dot) ...[
                        Container(
                          width: BlueDimens.checkoutHoldDot,
                          height: BlueDimens.checkoutHoldDot,
                          decoration: const BoxDecoration(
                            gradient: _goldDot,
                            borderRadius: BorderRadius.all(Radius.circular(4)),
                          ),
                        ),
                        const SizedBox(width: 8),
                      ],
                      DecoratedBox(
                        decoration: BoxDecoration(
                          color: chip.background,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: chip.border),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 2,
                          ),
                          child: Text(
                            chip.label,
                            style: TextStyle(
                              fontFamily: BlueFonts.jakarta,
                              fontSize: 11.5,
                              fontWeight: FontWeight.w700,
                              letterSpacing: 11.5 * 0.02,
                              height: 1.25,
                              color: chip.color,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          item.name,
                          maxLines: 3,
                          style: const TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 15,
                            height: 1.3,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 15 * -0.008,
                            color: BlueColors.ink,
                          ),
                        ),
                        if (item.config.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(top: 4),
                            child: Text(
                              item.config,
                              style: const TextStyle(
                                fontFamily: BlueFonts.jakarta,
                                fontSize: 12.5,
                                height: 1.45,
                                fontWeight: FontWeight.w400,
                                color: BlueColors.muted,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
                  if (item.amount.isNotEmpty || item.qty.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(left: 12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          if (item.amount.isNotEmpty)
                            Text(
                              item.amount,
                              style: const TextStyle(
                                fontFamily: BlueFonts.jakarta,
                                fontSize: 14.5,
                                fontWeight: FontWeight.w800,
                                letterSpacing: 14.5 * -0.012,
                                color: BlueColors.ink,
                              ),
                            ),
                          if (item.qty.isNotEmpty)
                            Padding(
                              padding: const EdgeInsets.only(top: 2),
                              child: Text(
                                item.qty,
                                style: const TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 11.5,
                                  fontWeight: FontWeight.w500,
                                  color: BlueColors.placeholder,
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
                ],
              ),
              if (item.tech.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 11),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: BlueColors.white,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: BlueColors.navLine),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                      child: Row(
                        children: [
                          const BlueGlyphIcon(
                            BlueGlyph.account,
                            size: 15,
                            color: BlueColors.chevron,
                            strokeWidth: 1.9,
                          ),
                          const SizedBox(width: 9),
                          Expanded(
                            child: Text(
                              item.tech,
                              style: const TextStyle(
                                fontFamily: BlueFonts.jakarta,
                                fontSize: 12.5,
                                height: 1.4,
                                fontWeight: FontWeight.w500,
                                color: BlueColors.body,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _TimelineRow extends StatelessWidget {
  const _TimelineRow({required this.event});

  final BookingDetailEventView event;

  @override
  Widget build(BuildContext context) {
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 9,
            child: Column(
              children: [
                const SizedBox(height: 4),
                Container(
                  width: 9,
                  height: 9,
                  decoration: BoxDecoration(
                    color: event.dotBg,
                    border: Border.all(color: event.dotBorder, width: 1.5),
                    borderRadius: BorderRadius.circular(5),
                  ),
                ),
                if (event.rail)
                  const Expanded(
                    child: Align(
                      alignment: Alignment.topCenter,
                      child: SizedBox(
                        width: 1.5,
                        height: double.infinity,
                        child: ColoredBox(color: BlueColors.border),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: event.pad),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    event.label,
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 13.5,
                      height: 1.4,
                      fontWeight: event.weight,
                      letterSpacing: 13.5 * -0.005,
                      color: event.color,
                    ),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    event.at,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 12,
                      fontWeight: FontWeight.w400,
                      color: BlueColors.ctaDisabledText,
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

class _PaymentAlert extends StatelessWidget {
  const _PaymentAlert({
    required this.text,
    required this.cta,
    required this.onPressed,
  });

  final String text;
  final String cta;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: BlueColors.unavailableSurface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: BlueColors.unavailableLine),
      ),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(15, 13, 15, 13),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              text,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.45,
                fontWeight: FontWeight.w500,
                color: BlueColors.unavailableInk,
              ),
            ),
            BluePressable(
              onPressed: onPressed,
              scale: 0.99,
              duration: _press,
              child: ConstrainedBox(
                constraints: const BoxConstraints(minHeight: 40),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      cta,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: BlueColors.ink,
                      ),
                    ),
                    const SizedBox(width: 6),
                    const BlueGlyphIcon(
                      BlueGlyph.chevronRight,
                      size: 13,
                      strokeWidth: 2.3,
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ActionButton extends StatelessWidget {
  const _ActionButton({required this.action, required this.onPressed});

  final BookingDetailActionView action;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.99,
      duration: _press,
      child: SizedBox(
        width: double.infinity,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 50),
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: action.primary ? BlueColors.ink : BlueColors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(
                color: action.primary ? BlueColors.ink : BlueColors.border,
              ),
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 18),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      action.label,
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 14.5 * -0.005,
                        color: action.primary
                            ? BlueColors.white
                            : BlueColors.ink,
                      ),
                    ),
                  ),
                  BlueGlyphIcon(
                    BlueGlyph.chevronRight,
                    size: 15,
                    color: action.primary ? BlueColors.white : BlueColors.ink,
                    strokeWidth: 2.2,
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

class _Hairline extends StatelessWidget {
  const _Hairline({required this.top});

  final double top;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(22, top, 22, 0),
      child: const DecoratedBox(
        decoration: BoxDecoration(
          border: Border(top: BorderSide(color: BlueColors.navLine)),
        ),
        child: SizedBox(width: double.infinity, height: 0),
      ),
    );
  }
}

class BookingDetailSkeleton extends StatefulWidget {
  const BookingDetailSkeleton({super.key});

  @override
  State<BookingDetailSkeleton> createState() => _BookingDetailSkeletonState();
}

class _BookingDetailSkeletonState extends State<BookingDetailSkeleton>
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
        return Opacity(
          opacity: 0.55 + (_pulse.value * 0.45),
          child: const _SkeletonBody(),
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
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Padding(
          padding: EdgeInsets.symmetric(horizontal: 22),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _Bone(
                width: 78,
                height: 20,
                radius: 8,
                color: BlueColors.skeletonAlt,
              ),
              SizedBox(height: 14),
              _Bone(
                width: 262,
                height: 22,
                radius: 7,
                color: BlueColors.skeleton,
              ),
              SizedBox(height: 12),
              _Bone(
                width: 206,
                height: 12,
                radius: 5,
                color: BlueColors.skeletonAlt,
              ),
            ],
          ),
        ),
        const Padding(
          padding: EdgeInsets.fromLTRB(22, 24, 22, 0),
          child: DecoratedBox(
            decoration: BoxDecoration(
              border: Border(top: BorderSide(color: BlueColors.navLine)),
            ),
            child: SizedBox(width: double.infinity, height: 0),
          ),
        ),
        const Padding(
          padding: EdgeInsets.fromLTRB(22, 20, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _Bone(
                width: 112,
                height: 13,
                radius: 5,
                color: BlueColors.skeleton,
              ),
              SizedBox(height: 13),
              _Bone(
                width: 186,
                height: 15,
                radius: 6,
                color: BlueColors.skeletonAlt,
              ),
              SizedBox(height: 9),
              _Bone(
                width: 232,
                height: 11,
                radius: 5,
                color: BlueColors.skeletonAlt,
              ),
            ],
          ),
        ),
        const Padding(
          padding: EdgeInsets.fromLTRB(22, 24, 22, 0),
          child: DecoratedBox(
            decoration: BoxDecoration(
              border: Border(top: BorderSide(color: BlueColors.navLine)),
            ),
            child: SizedBox(width: double.infinity, height: 0),
          ),
        ),
        const Padding(
          padding: EdgeInsets.fromLTRB(22, 20, 22, 0),
          child: _Bone(
            width: 96,
            height: 13,
            radius: 5,
            color: BlueColors.skeleton,
          ),
        ),
        const SizedBox(height: 16),
        const DecoratedBox(
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: BlueColors.navLine)),
          ),
          child: Padding(
            padding: EdgeInsets.fromLTRB(22, 15, 22, 15),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _Bone(
                  width: 168,
                  height: 15,
                  radius: 6,
                  color: BlueColors.skeleton,
                ),
                SizedBox(height: 8),
                _Bone(
                  width: 206,
                  height: 11,
                  radius: 5,
                  color: BlueColors.skeletonAlt,
                ),
              ],
            ),
          ),
        ),
        const DecoratedBox(
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: BlueColors.navLine)),
          ),
          child: Padding(
            padding: EdgeInsets.fromLTRB(22, 15, 22, 15),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _Bone(
                  width: 142,
                  height: 15,
                  radius: 6,
                  color: BlueColors.skeletonAlt,
                ),
                SizedBox(height: 8),
                _Bone(
                  width: 184,
                  height: 11,
                  radius: 5,
                  color: BlueColors.skeletonAlt,
                ),
              ],
            ),
          ),
        ),
        const DecoratedBox(
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: BlueColors.navLine)),
          ),
          child: SizedBox(width: double.infinity, height: 0),
        ),
      ],
    );
  }
}

class _Bone extends StatelessWidget {
  const _Bone({
    required this.width,
    required this.height,
    required this.radius,
    required this.color,
  });

  final double width;
  final double height;
  final double radius;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(radius),
      ),
    );
  }
}

class BookingDetailFail extends StatelessWidget {
  const BookingDetailFail({
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
    return Padding(
      padding: const EdgeInsets.fromLTRB(22, 90, 22, 0),
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
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 300),
            child: Text(
              notFound
                  ? "This booking isn't available"
                  : "We couldn't load this booking",
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 19,
                height: 1.3,
                fontWeight: FontWeight.w700,
                letterSpacing: 19 * -0.018,
                color: BlueColors.ink,
              ),
            ),
          ),
          const SizedBox(height: 7),
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 292),
            child: Text(
              notFound
                  ? 'It may have been removed, or it belongs to another account. Your other bookings are unaffected.'
                  : 'Any scheduled service still stands — this is only a problem loading the page. Check your connection and try again.',
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
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              _FailInk(
                label: notFound ? 'Back to bookings' : 'Try again',
                onPressed: notFound ? onBack : onRetry,
              ),
              if (!notFound)
                _FailGhost(label: 'Back to bookings', onPressed: onBack),
            ],
          ),
        ],
      ),
    );
  }
}

class _FailInk extends StatelessWidget {
  const _FailInk({required this.label, required this.onPressed});

  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      duration: _press,
      child: Container(
        height: 48,
        padding: const EdgeInsets.symmetric(horizontal: 24),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: BlueColors.ink,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Text(
          label,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 15,
            fontWeight: FontWeight.w700,
            color: BlueColors.white,
          ),
        ),
      ),
    );
  }
}

class _FailGhost extends StatelessWidget {
  const _FailGhost({required this.label, required this.onPressed});

  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.985,
      duration: _press,
      child: Container(
        height: 48,
        padding: const EdgeInsets.symmetric(horizontal: 20),
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: BlueColors.border),
        ),
        child: Text(
          label,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 15,
            fontWeight: FontWeight.w700,
            color: BlueColors.ink,
          ),
        ),
      ),
    );
  }
}

Future<void> showBookingHelpSheet(BuildContext context, String reference) {
  final quote = reference.isEmpty ? 'this booking' : reference;
  return showBlueSheet<void>(
    context: context,
    builder: (context) {
      return BlueSheetPanel(
        title: 'Get help with this booking',
        onClose: () => Navigator.pop(context),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
          child: Text(
            'Quote $quote if you contact BLUE. Nothing on this appointment changes from here.',
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14,
              height: 1.5,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
        ),
      );
    },
  );
}
