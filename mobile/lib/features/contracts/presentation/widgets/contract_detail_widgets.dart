import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../auth/presentation/widgets/blue_sheet.dart';
import '../../../home/presentation/widgets/home_icons.dart';
import '../../../services/presentation/widgets/services_widgets.dart';
import '../../data/contract_models.dart';
import '../widgets/contracts_widgets.dart';

const _swap = Duration(milliseconds: 220);
const _acceptSwap = Duration(milliseconds: 300);
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

class ContractDetailBackButton extends StatelessWidget {
  const ContractDetailBackButton({super.key, required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Transform.translate(
      offset: const Offset(-11, 0),
      child: Semantics(
        button: true,
        label: 'Back to contracts',
        excludeSemantics: true,
        child: ServicesBackButton(onPressed: onPressed),
      ),
    );
  }
}

class ContractDetailAppBar extends StatelessWidget {
  const ContractDetailAppBar({
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
            ContractDetailBackButton(onPressed: onBack),
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

class ContractDetailBody extends StatelessWidget {
  const ContractDetailBody({
    super.key,
    required this.view,
    required this.consented,
    required this.onConsent,
    required this.onHelp,
    required this.onBookings,
    required this.onBills,
    this.onBookVisit,
  });

  final ContractDetailView view;
  final bool consented;
  final ValueChanged<bool> onConsent;
  final VoidCallback onHelp;
  final VoidCallback onBookings;
  final VoidCallback onBills;
  final void Function(String contractItemUuid)? onBookVisit;

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
              _StatusRow(view: view),
              const SizedBox(height: 12),
              Text(
                view.name,
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 21,
                  height: 1.26,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 21 * -0.02,
                  color: view.nameColor,
                ),
              ),
              const SizedBox(height: 8),
              AnimatedSwitcher(
                duration: BlueMotion.of(context, _swap),
                switchInCurve: BlueMotion.curve,
                switchOutCurve: BlueMotion.exitCurve,
                child: Text(
                  view.meaning,
                  key: ValueKey(view.meaning),
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 13.5,
                    height: 1.5,
                    fontWeight: FontWeight.w400,
                    color: BlueColors.muted,
                  ),
                ),
              ),
            ],
          ),
        ),
        if (view.hasAlert)
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 16, 22, 0),
            child: _AlertStrip(text: view.alert),
          ),
        if (view.periodRange.isNotEmpty || view.periodTitle.isNotEmpty) ...[
          const _Hairline(top: 20),
          _SectionHead(glyph: BlueGlyph.calendar, title: view.periodTitle),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 11, 22, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (view.periodRange.isNotEmpty)
                  Text(
                    view.periodRange,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15,
                      height: 1.4,
                      fontWeight: FontWeight.w700,
                      letterSpacing: 15 * -0.01,
                      color: BlueColors.ink,
                    ),
                  ),
                if (view.periodNote.isNotEmpty)
                  Padding(
                    padding: EdgeInsets.only(
                      top: view.periodRange.isEmpty ? 0 : 6,
                    ),
                    child: Text(
                      view.periodNote,
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
            ),
          ),
        ],
        const _Hairline(top: 20),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 18, 22, 0),
          child: Row(
            children: [
              const BlueGlyphIcon(BlueGlyph.check, size: 17, strokeWidth: 1.9),
              const SizedBox(width: 9),
              Expanded(
                child: Text(
                  view.coverageTitle,
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
        if (view.coverageNote.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 8, 22, 0),
            child: Text(
              view.coverageNote,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.45,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
        const SizedBox(height: 8),
        for (final row in view.coverage) _CoverageRow(row: row),
        if (onBookVisit != null &&
            !view.awaiting &&
            view.sticky != ContractStickyKind.accept &&
            view.coverage.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 14, 22, 0),
            child: _TextLink(
              label: 'Book a covered visit',
              onPressed: () {
                if (view.coverage.isNotEmpty) {
                  onBookVisit!(view.coverage.first.uuid);
                }
              },
            ),
          ),
        AnimatedSwitcher(
          duration: BlueMotion.of(context, _acceptSwap),
          switchInCurve: BlueMotion.curve,
          switchOutCurve: BlueMotion.exitCurve,
          child: view.awaiting
              ? _AcceptanceBlock(
                  key: const ValueKey('accept'),
                  copy: view.consentCopy,
                  terms: view.termsReference,
                  consented: consented,
                  onConsent: onConsent,
                )
              : const SizedBox.shrink(key: ValueKey('accepted')),
        ),
        if (view.hasBilling) ...[
          const _Hairline(top: 20),
          const Padding(
            padding: EdgeInsets.fromLTRB(22, 18, 22, 0),
            child: Text(
              'Billing',
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
                  child: AnimatedSwitcher(
                    duration: BlueMotion.of(context, _swap),
                    switchInCurve: BlueMotion.curve,
                    switchOutCurve: BlueMotion.exitCurve,
                    child: Text(
                      view.billingLabel,
                      key: ValueKey(view.billingLabel),
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 13.5,
                        fontWeight: FontWeight.w600,
                        color: view.billingColor,
                      ),
                    ),
                  ),
                ),
                if (view.billingAmount.isNotEmpty)
                  Text(
                    view.billingAmount,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 16 * -0.014,
                      fontFeatures: [FontFeature.tabularFigures()],
                      color: BlueColors.ink,
                    ),
                  ),
              ],
            ),
          ),
          if (view.billingNote.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(22, 6, 22, 0),
              child: Text(
                view.billingNote,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  height: 1.45,
                  fontWeight: FontWeight.w400,
                  color: BlueColors.placeholder,
                ),
              ),
            ),
          if (view.earlierBills.isNotEmpty) ...[
            const Padding(
              padding: EdgeInsets.fromLTRB(22, 16, 22, 0),
              child: Text(
                'EARLIER BILLS',
                style: TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 11.5,
                  fontWeight: FontWeight.w600,
                  letterSpacing: 11.5 * 0.04,
                  height: 1,
                  color: BlueColors.ctaDisabledText,
                ),
              ),
            ),
            for (final bill in view.earlierBills) _BillRow(bill: bill),
            if (view.moreBills)
              Padding(
                padding: const EdgeInsets.fromLTRB(22, 4, 22, 0),
                child: _TextLink(
                  label: 'View billing history',
                  onPressed: onBills,
                ),
              ),
          ],
        ],
        if (view.hasHistory) ...[
          const _Hairline(top: 20),
          const Padding(
            padding: EdgeInsets.fromLTRB(22, 18, 22, 0),
            child: Text(
              'Contract history',
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
                for (final event in view.history) _TimelineRow(event: event),
              ],
            ),
          ),
        ],
        if (view.hasBooked) ...[
          const _Hairline(top: 20),
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 18, 22, 0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  view.bookedNote,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 13.5,
                    height: 1.5,
                    fontWeight: FontWeight.w400,
                    color: BlueColors.muted,
                  ),
                ),
                _TextLink(label: 'View bookings', onPressed: onBookings),
              ],
            ),
          ),
        ],
        const _Hairline(top: 22),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 18, 22, 0),
          child: _HelpRow(onPressed: onHelp),
        ),
      ],
    );
  }
}

class ContractDetailSticky extends StatelessWidget {
  const ContractDetailSticky({
    super.key,
    required this.view,
    required this.enabled,
    required this.busy,
    required this.onPressed,
  });

  final ContractDetailView view;
  final bool enabled;
  final bool busy;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    if (!view.hasSticky) return const SizedBox.shrink();
    final canTap = enabled && !busy;
    return DecoratedBox(
      decoration: const BoxDecoration(
        color: BlueColors.white,
        border: Border(top: BorderSide(color: BlueColors.navLine)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(22, 12, 22, 12),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              BluePressable(
                enabled: canTap,
                onPressed: canTap ? onPressed : null,
                scale: 0.985,
                duration: _press,
                child: AnimatedContainer(
                  duration: BlueMotion.of(context, _swap),
                  curve: BlueMotion.curve,
                  height: 52,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: canTap ? BlueColors.ink : BlueColors.ctaDisabled,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      if (busy)
                        const Padding(
                          padding: EdgeInsets.only(right: 10),
                          child: _BusyRing(),
                        ),
                      Text(
                        view.stickyLabel,
                        style: TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: canTap
                              ? BlueColors.white
                              : BlueColors.ctaDisabledText,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              if (view.stickyFoot.isNotEmpty) ...[
                const SizedBox(height: 8),
                Text(
                  view.stickyFoot,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12.5,
                    height: 1.4,
                    fontWeight: FontWeight.w400,
                    color: BlueColors.placeholder,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _StatusRow extends StatelessWidget {
  const _StatusRow({required this.view});

  final ContractDetailView view;

  @override
  Widget build(BuildContext context) {
    final chip = view.chip;
    return Semantics(
      liveRegion: true,
      container: true,
      label: view.statusA11y,
      excludeSemantics: true,
      child: AnimatedSwitcher(
        duration: BlueMotion.of(context, _swap),
        switchInCurve: BlueMotion.curve,
        switchOutCurve: BlueMotion.exitCurve,
        child: Row(
          key: ValueKey('${view.status}-${view.dot}'),
          children: [
            if (view.dot) ...[
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
                  view.status,
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

class _CoverageRow extends StatelessWidget {
  const _CoverageRow({required this.row});

  final ContractCoverageRow row;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      container: true,
      label: row.a11y,
      excludeSemantics: true,
      child: DecoratedBox(
        decoration: const BoxDecoration(
          border: Border(top: BorderSide(color: BlueColors.navLine)),
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(22, 15, 22, 16),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      row.name,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 15,
                        height: 1.3,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 15 * -0.008,
                        color: BlueColors.ink,
                      ),
                    ),
                    if (row.description.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text(
                          row.description,
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
              if (row.chip.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(left: 12, top: 1),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      color: BlueColors.chipSurface,
                      borderRadius: BorderRadius.circular(9),
                      border: Border.all(color: BlueColors.border),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 4,
                      ),
                      child: Text(
                        row.chip,
                        style: const TextStyle(
                          fontFamily: BlueFonts.jakarta,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 12 * -0.005,
                          height: 1.2,
                          color: BlueColors.ink,
                        ),
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

class _AcceptanceBlock extends StatelessWidget {
  const _AcceptanceBlock({
    super.key,
    required this.copy,
    required this.terms,
    required this.consented,
    required this.onConsent,
  });

  final String copy;
  final String terms;
  final bool consented;
  final ValueChanged<bool> onConsent;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _Hairline(top: 20),
        const Padding(
          padding: EdgeInsets.fromLTRB(22, 18, 22, 0),
          child: Text(
            'Before you accept',
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
          padding: const EdgeInsets.fromLTRB(22, 8, 22, 0),
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
        if (terms.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(22, 10, 22, 0),
            child: Text(
              'Terms reference $terms',
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.45,
                fontWeight: FontWeight.w400,
                color: BlueColors.placeholder,
              ),
            ),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(22, 14, 22, 0),
          child: _ConsentBox(
            key: const Key('contract-consent'),
            checked: consented,
            onChanged: onConsent,
          ),
        ),
      ],
    );
  }
}

class _ConsentBox extends StatelessWidget {
  const _ConsentBox({
    super.key,
    required this.checked,
    required this.onChanged,
  });

  final bool checked;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      checked: checked,
      button: true,
      label: "I've read what this contract covers and agree to its terms.",
      excludeSemantics: true,
      child: BluePressable(
        onPressed: () => onChanged(!checked),
        scale: 0.99,
        duration: _press,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: BlueColors.white,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: BlueColors.ink, width: 1.4),
          ),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 52),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(top: 1),
                    child: AnimatedContainer(
                      duration: BlueMotion.of(context, _swap),
                      curve: BlueMotion.curve,
                      width: 22,
                      height: 22,
                      decoration: BoxDecoration(
                        color: checked ? BlueColors.ink : BlueColors.white,
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(color: BlueColors.ink, width: 1.6),
                      ),
                      child: checked
                          ? const Center(
                              child: BlueGlyphIcon(
                                BlueGlyph.check,
                                size: 13,
                                color: BlueColors.white,
                                strokeWidth: 2.4,
                              ),
                            )
                          : null,
                    ),
                  ),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text(
                      "I've read what this contract covers and agree to its terms.",
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 13.5,
                        height: 1.4,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 13.5 * -0.005,
                        color: BlueColors.ink,
                      ),
                    ),
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

class _AlertStrip extends StatelessWidget {
  const _AlertStrip({required this.text});

  final String text;

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
        child: Text(
          text,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 12.5,
            height: 1.45,
            fontWeight: FontWeight.w500,
            color: BlueColors.unavailableInk,
          ),
        ),
      ),
    );
  }
}

class _BillRow extends StatelessWidget {
  const _BillRow({required this.bill});

  final ContractEarlierBill bill;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(22, 10, 22, 0),
      child: Row(
        children: [
          Expanded(
            child: Text(
              bill.date,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13,
                fontWeight: FontWeight.w400,
                color: BlueColors.muted,
              ),
            ),
          ),
          Text(
            bill.state,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13,
              fontWeight: FontWeight.w400,
              color: BlueColors.muted,
            ),
          ),
          const SizedBox(width: 10),
          Text(
            bill.amount,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 13,
              fontWeight: FontWeight.w700,
              fontFeatures: [FontFeature.tabularFigures()],
              color: BlueColors.ink,
            ),
          ),
        ],
      ),
    );
  }
}

class _TimelineRow extends StatelessWidget {
  const _TimelineRow({required this.event});

  final ContractHistoryEvent event;

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

class _TextLink extends StatelessWidget {
  const _TextLink({required this.label, required this.onPressed});

  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
      scale: 0.99,
      duration: _press,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 44),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              label,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 13.5,
                fontWeight: FontWeight.w700,
                height: 1,
                color: BlueColors.ink,
              ),
            ),
            const SizedBox(width: 6),
            const BlueGlyphIcon(
              BlueGlyph.chevronRight,
              size: 13,
              color: BlueColors.ink,
              strokeWidth: 2.3,
            ),
          ],
        ),
      ),
    );
  }
}

class _HelpRow extends StatelessWidget {
  const _HelpRow({required this.onPressed});

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
              color: BlueColors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: BlueColors.border),
            ),
            child: const Padding(
              padding: EdgeInsets.symmetric(horizontal: 18),
              child: Row(
                children: [
                  Expanded(
                    child: Text(
                      'Get help with this contract',
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 14.5,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 14.5 * -0.005,
                        color: BlueColors.ink,
                      ),
                    ),
                  ),
                  BlueGlyphIcon(
                    BlueGlyph.chevronRight,
                    size: 15,
                    color: BlueColors.ink,
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

class _BusyRing extends StatefulWidget {
  const _BusyRing();

  @override
  State<_BusyRing> createState() => _BusyRingState();
}

class _BusyRingState extends State<_BusyRing>
    with SingleTickerProviderStateMixin {
  late final AnimationController _spin;

  @override
  void initState() {
    super.initState();
    _spin = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 700),
    )..repeat();
  }

  @override
  void dispose() {
    _spin.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return RotationTransition(
      turns: _spin,
      child: const CustomPaint(size: Size(15, 15), painter: _RingPainter()),
    );
  }
}

class _RingPainter extends CustomPainter {
  const _RingPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Rect.fromLTWH(1, 1, size.width - 2, size.height - 2);
    canvas.drawArc(
      rect,
      0,
      6.2832,
      false,
      Paint()
        ..color = const Color(0x52FFFFFF)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2,
    );
    canvas.drawArc(
      rect,
      -1.57,
      1.6,
      false,
      Paint()
        ..color = BlueColors.white
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeCap = StrokeCap.round,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class ContractDetailSkeleton extends StatefulWidget {
  const ContractDetailSkeleton({super.key});

  @override
  State<ContractDetailSkeleton> createState() => _ContractDetailSkeletonState();
}

class _ContractDetailSkeletonState extends State<ContractDetailSkeleton>
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
    return const Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
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
                width: 196,
                height: 22,
                radius: 7,
                color: BlueColors.skeleton,
              ),
              SizedBox(height: 12),
              _Bone(
                width: 216,
                height: 12,
                radius: 5,
                color: BlueColors.skeletonAlt,
              ),
            ],
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(22, 24, 22, 0),
          child: DecoratedBox(
            decoration: BoxDecoration(
              border: Border(top: BorderSide(color: BlueColors.navLine)),
            ),
            child: SizedBox(width: double.infinity, height: 0),
          ),
        ),
        Padding(
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
                width: 182,
                height: 15,
                radius: 6,
                color: BlueColors.skeletonAlt,
              ),
              SizedBox(height: 9),
              _Bone(
                width: 176,
                height: 11,
                radius: 5,
                color: BlueColors.skeletonAlt,
              ),
            ],
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(22, 24, 22, 0),
          child: DecoratedBox(
            decoration: BoxDecoration(
              border: Border(top: BorderSide(color: BlueColors.navLine)),
            ),
            child: SizedBox(width: double.infinity, height: 0),
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(22, 20, 22, 0),
          child: _Bone(
            width: 132,
            height: 13,
            radius: 5,
            color: BlueColors.skeleton,
          ),
        ),
        SizedBox(height: 14),
        DecoratedBox(
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: BlueColors.navLine)),
          ),
          child: Padding(
            padding: EdgeInsets.fromLTRB(22, 15, 22, 15),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _Bone(
                        width: 146,
                        height: 14,
                        radius: 6,
                        color: BlueColors.skeleton,
                      ),
                      SizedBox(height: 8),
                      _Bone(
                        width: 176,
                        height: 11,
                        radius: 5,
                        color: BlueColors.skeletonAlt,
                      ),
                    ],
                  ),
                ),
                _Bone(
                  width: 62,
                  height: 20,
                  radius: 9,
                  color: BlueColors.skeletonAlt,
                ),
              ],
            ),
          ),
        ),
        DecoratedBox(
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: BlueColors.navLine)),
          ),
          child: Padding(
            padding: EdgeInsets.fromLTRB(22, 15, 22, 15),
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _Bone(
                        width: 132,
                        height: 14,
                        radius: 6,
                        color: BlueColors.skeleton,
                      ),
                      SizedBox(height: 8),
                      _Bone(
                        width: 160,
                        height: 11,
                        radius: 5,
                        color: BlueColors.skeletonAlt,
                      ),
                    ],
                  ),
                ),
                _Bone(
                  width: 56,
                  height: 20,
                  radius: 9,
                  color: BlueColors.skeletonAlt,
                ),
              ],
            ),
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(22, 24, 22, 0),
          child: DecoratedBox(
            decoration: BoxDecoration(
              border: Border(top: BorderSide(color: BlueColors.navLine)),
            ),
            child: SizedBox(width: double.infinity, height: 0),
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(22, 20, 22, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _Bone(
                width: 72,
                height: 13,
                radius: 5,
                color: BlueColors.skeleton,
              ),
              SizedBox(height: 12),
              _Bone(
                width: 86,
                height: 12,
                radius: 5,
                color: BlueColors.skeletonAlt,
              ),
            ],
          ),
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

class ContractDetailFail extends StatelessWidget {
  const ContractDetailFail({
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
                  ? "This contract isn't available"
                  : "We couldn't load this contract",
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
                  ? 'It may have been removed, or it belongs to another account. Your other contracts are unaffected.'
                  : 'Any contract you have is still in force — this is only a problem loading the page. Check your connection and try again.',
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
              ContractsInkButton(
                label: notFound ? 'Back to contracts' : 'Try again',
                height: 48,
                horizontal: 24,
                onPressed: notFound ? onBack : onRetry,
              ),
              if (!notFound)
                ContractsGhostButton(
                  label: 'Back to contracts',
                  onPressed: onBack,
                ),
            ],
          ),
        ],
      ),
    );
  }
}

Future<void> showContractHelpSheet(BuildContext context, String reference) {
  final quote = reference.isEmpty ? 'this contract' : reference;
  return showBlueSheet<void>(
    context: context,
    builder: (context) {
      return BlueSheetPanel(
        title: 'Get help with this contract',
        onClose: () => Navigator.pop(context),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
          child: Text(
            'Quote $quote if you contact BLUE. Nothing on this contract changes from here.',
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
