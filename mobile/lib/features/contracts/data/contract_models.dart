import 'package:flutter/material.dart';

import '../../../app/theme/blue_theme.dart';

enum ContractTone { live, neutral, warm, flat }

class ContractService {
  const ContractService({
    required this.uuid,
    required this.code,
    required this.name,
  });

  final String uuid;
  final String code;
  final String name;

  factory ContractService.fromJson(Map<String, dynamic>? json) {
    return ContractService(
      uuid: json?['uuid'] as String? ?? '',
      code: json?['code'] as String? ?? '',
      name: (json?['name'] as String? ?? '').trim(),
    );
  }
}

class ContractItem {
  const ContractItem({
    required this.uuid,
    required this.service,
    this.entitlementMode = '',
    this.includedVisits,
    this.usedVisits = 0,
    this.remainingVisits,
    this.description = '',
  });

  final String uuid;
  final ContractService service;
  final String entitlementMode;
  final int? includedVisits;
  final int usedVisits;
  final int? remainingVisits;
  final String description;

  factory ContractItem.fromJson(Map<String, dynamic> json) {
    final service = json['service'] as Map<String, dynamic>?;
    final description =
        (json['description'] as String? ??
                service?['description'] as String? ??
                '')
            .trim();
    return ContractItem(
      uuid: json['contract_item_uuid'] as String? ?? '',
      service: ContractService.fromJson(service),
      entitlementMode: (json['entitlement_mode'] as String? ?? '').trim(),
      includedVisits: (json['included_visits'] as num?)?.toInt(),
      usedVisits: (json['used_visits'] as num?)?.toInt() ?? 0,
      remainingVisits: (json['remaining_visits'] as num?)?.toInt(),
      description: description,
    );
  }
}

class ContractCurrency {
  const ContractCurrency({
    this.code = 'AED',
    this.symbol = '',
    this.decimalPlaces = 2,
  });

  final String code;
  final String symbol;
  final int decimalPlaces;

  factory ContractCurrency.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const ContractCurrency();
    final code = (json['code'] as String? ?? 'AED').trim();
    return ContractCurrency(
      code: code.isEmpty ? 'AED' : code,
      symbol: (json['symbol'] as String? ?? '').trim(),
      decimalPlaces: (json['decimal_places'] as num?)?.toInt() ?? 2,
    );
  }
}

class ContractBillRow {
  const ContractBillRow({
    required this.label,
    required this.state,
    required this.amount,
    this.at,
  });

  final String label;
  final String state;
  final String amount;
  final DateTime? at;

  factory ContractBillRow.fromJson(Map<String, dynamic> json) {
    final at = DateTime.tryParse(
      '${json['paid_at'] ?? json['at'] ?? json['date'] ?? ''}',
    )?.toLocal();
    return ContractBillRow(
      label: (json['label'] as String? ?? '').trim(),
      state: (json['status'] as String? ?? json['state'] as String? ?? '')
          .trim(),
      amount: '${json['amount'] ?? json['recurring_amount'] ?? ''}',
      at: at,
    );
  }
}

class ContractBookingRef {
  const ContractBookingRef({
    required this.uuid,
    required this.status,
    this.number = '',
  });

  final String uuid;
  final String status;
  final String number;

  bool get counts => status != 'CANCELLED';

  factory ContractBookingRef.fromJson(Map<String, dynamic> json) {
    return ContractBookingRef(
      uuid: json['uuid'] as String? ?? '',
      status: (json['status'] as String? ?? '').trim(),
      number: (json['booking_number'] as String? ?? '').trim(),
    );
  }
}

class ContractBilling {
  const ContractBilling({
    required this.status,
    this.amount = '',
    this.currency = const ContractCurrency(),
    this.periodEnd,
    this.bills = const [],
    this.refundedAmount = '',
    this.refundNote = '',
  });

  final String status;
  final String amount;
  final ContractCurrency currency;
  final DateTime? periodEnd;
  final List<ContractBillRow> bills;
  final String refundedAmount;
  final String refundNote;

  factory ContractBilling.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const ContractBilling(status: '');
    final bills = json['recent_bills'] ?? json['bills'] ?? json['invoices'];
    return ContractBilling(
      status: (json['status'] as String? ?? '').trim(),
      amount: '${json['recurring_amount'] ?? ''}',
      currency: ContractCurrency.fromJson(
        json['currency'] as Map<String, dynamic>?,
      ),
      periodEnd: DateTime.tryParse(
        json['current_period_end'] as String? ?? '',
      )?.toLocal(),
      bills: bills is List
          ? bills
                .whereType<Map<String, dynamic>>()
                .map(ContractBillRow.fromJson)
                .toList()
          : const [],
      refundedAmount: '${json['refunded_amount'] ?? ''}',
      refundNote: (json['refund_note'] as String? ?? '').trim(),
    );
  }
}

class Contract {
  const Contract({
    required this.uuid,
    required this.contractNumber,
    required this.status,
    required this.items,
    required this.createdAt,
    this.startsAt,
    this.endsAt,
    this.quotedAmount = '',
    this.currency = const ContractCurrency(),
    this.acceptedAt,
    this.billing,
    this.updatedAt,
    this.bookings = const [],
    this.termsReference = '',
  });

  final String uuid;
  final String contractNumber;
  final String status;
  final List<ContractItem> items;
  final DateTime createdAt;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final String quotedAmount;
  final ContractCurrency currency;
  final DateTime? acceptedAt;
  final ContractBilling? billing;
  final DateTime? updatedAt;
  final List<ContractBookingRef> bookings;
  final String termsReference;

  bool get isPast => status == 'EXPIRED' || status == 'CANCELLED';

  bool get isCurrent => !isPast;

  bool get canPay {
    return status == 'PENDING_PAYMENT' &&
        (billing?.status ?? '') == 'PENDING_CHECKOUT';
  }

  factory Contract.fromJson(Map<String, dynamic> json) {
    final term = json['term'] as Map<String, dynamic>?;
    final acceptance = json['acceptance'] as Map<String, dynamic>?;
    final billing = json['billing'];
    final terms =
        (json['terms_reference'] as String? ??
                acceptance?['terms_reference'] as String? ??
                '')
            .trim();
    return Contract(
      uuid: json['uuid'] as String? ?? '',
      contractNumber: (json['contract_number'] as String? ?? '').trim(),
      status: json['status'] as String? ?? '',
      items: (json['covered_services'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(ContractItem.fromJson)
          .toList(),
      createdAt:
          DateTime.tryParse(json['created_at'] as String? ?? '')?.toLocal() ??
          DateTime.fromMillisecondsSinceEpoch(0),
      startsAt: DateTime.tryParse(
        '${term?['starts_at'] ?? json['starts_at'] ?? ''}',
      )?.toLocal(),
      endsAt: DateTime.tryParse(
        '${term?['ends_at'] ?? json['ends_at'] ?? ''}',
      )?.toLocal(),
      quotedAmount: '${json['quoted_amount'] ?? ''}',
      currency: ContractCurrency.fromJson(
        json['currency'] as Map<String, dynamic>?,
      ),
      acceptedAt: DateTime.tryParse(
        acceptance?['accepted_at'] as String? ?? '',
      )?.toLocal(),
      billing: billing is Map<String, dynamic>
          ? ContractBilling.fromJson(billing)
          : null,
      updatedAt: DateTime.tryParse(
        json['updated_at'] as String? ?? '',
      )?.toLocal(),
      bookings: (json['bookings'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(ContractBookingRef.fromJson)
          .toList(),
      termsReference: terms,
    );
  }

  ContractRowView present([DateTime? now]) {
    return ContractRowView.fromContract(this, now ?? DateTime.now());
  }

  ContractDetailView detail([DateTime? now]) {
    return ContractDetailView.fromContract(this, now ?? DateTime.now());
  }
}

class ContractChipStyle {
  const ContractChipStyle({
    required this.color,
    required this.background,
    required this.border,
  });

  final Color color;
  final Color background;
  final Color border;

  static const live = ContractChipStyle(
    color: BlueColors.white,
    background: BlueColors.ink,
    border: BlueColors.ink,
  );
  static const neutral = ContractChipStyle(
    color: BlueColors.chipInk,
    background: BlueColors.chipSurface,
    border: BlueColors.border,
  );
  static const warm = ContractChipStyle(
    color: BlueColors.unavailableInk,
    background: BlueColors.unavailableSurface,
    border: BlueColors.unavailableLine,
  );
  static const flat = ContractChipStyle(
    color: BlueColors.placeholder,
    background: BlueColors.areaLocked,
    border: BlueColors.dateFullLine,
  );

  static ContractChipStyle of(ContractTone tone) {
    return switch (tone) {
      ContractTone.live => live,
      ContractTone.neutral => neutral,
      ContractTone.warm => warm,
      ContractTone.flat => flat,
    };
  }
}

class ContractRowView {
  const ContractRowView({
    required this.uuid,
    required this.status,
    required this.tone,
    required this.dot,
    required this.name,
    required this.coverage,
    required this.more,
    required this.period,
    required this.past,
    required this.billing,
    required this.billingWarm,
    required this.billingAmount,
    required this.note,
    required this.noteWarm,
    required this.action,
    required this.canPay,
  });

  final String uuid;
  final String status;
  final ContractTone tone;
  final bool dot;
  final String name;
  final String coverage;
  final String more;
  final String period;
  final bool past;
  final String billing;
  final bool billingWarm;
  final String billingAmount;
  final String note;
  final bool noteWarm;
  final String action;
  final bool canPay;

  ContractChipStyle get chip => ContractChipStyle.of(tone);

  Color get nameColor =>
      tone == ContractTone.flat ? BlueColors.chipInk : BlueColors.ink;

  FontWeight get periodWeight => past ? FontWeight.w500 : FontWeight.w600;

  Color get periodColor => past ? BlueColors.muted : BlueColors.ink;

  Color get billingColor =>
      billingWarm ? BlueColors.unavailableInk : BlueColors.chipInk;

  Color get noteColor =>
      noteWarm ? BlueColors.unavailableInk : BlueColors.placeholder;

  bool get needsAttention => action.isNotEmpty;

  String get a11y {
    final extra = more.isEmpty ? '' : ', $more';
    final bill = billing.isEmpty
        ? ''
        : '. Billing $billing${billingAmount.isEmpty ? '' : ', $billingAmount'}';
    final extraNote = note.isEmpty ? '' : '. $note';
    return '$name. Contract status $status. Covers $coverage$extra. $period$bill$extraNote. Opens contract details.';
  }

  factory ContractRowView.fromContract(Contract contract, DateTime now) {
    final names = [
      for (final item in contract.items)
        if (item.service.name.isNotEmpty) item.service.name,
    ];
    final name = names.isEmpty ? 'Service contract' : names.first;
    final shown = names.take(2).toList();
    final extra = names.length > 2 ? names.length - 2 : 0;
    final coverage = shown.join(' · ');
    final more = extra == 0
        ? ''
        : (extra == 1
              ? '+ 1 more included service'
              : '+ $extra more included services');

    final awaiting = contract.status == 'PENDING_CUSTOMER_ACCEPTANCE';
    final pendingPay = contract.status == 'PENDING_PAYMENT';
    final requested = contract.status == 'REQUESTED';
    final approved = contract.status == 'APPROVED';
    final paused = contract.status == 'SUSPENDED';
    final ended = contract.status == 'EXPIRED';
    final cancelled = contract.status == 'CANCELLED';
    final start = contract.startsAt;
    final startDay = start == null
        ? null
        : DateTime(start.year, start.month, start.day);
    final today = DateTime(now.year, now.month, now.day);
    final upcoming = startDay != null && startDay.isAfter(today);

    late final String statusLabel;
    late final ContractTone tone;
    var dot = false;
    if (cancelled) {
      statusLabel = 'Cancelled';
      tone = ContractTone.flat;
    } else if (ended) {
      statusLabel = 'Ended';
      tone = ContractTone.neutral;
    } else if (paused) {
      statusLabel = 'Paused';
      tone = ContractTone.warm;
    } else if (awaiting) {
      statusLabel = 'Needs your approval';
      tone = ContractTone.warm;
    } else if (requested) {
      statusLabel = 'Under review';
      tone = ContractTone.neutral;
    } else if (approved) {
      statusLabel = 'Quote ready';
      tone = ContractTone.warm;
    } else if (upcoming || pendingPay) {
      statusLabel = start == null ? 'Starts' : 'Starts ${_chipDate(start)}';
      tone = ContractTone.neutral;
    } else {
      statusLabel = 'Active';
      tone = ContractTone.live;
      dot = true;
    }

    final past = ended || cancelled;
    final period = _period(
      contract,
      awaiting: awaiting,
      ended: ended,
      cancelled: cancelled,
    );

    final billed = _billing(contract, paused: paused);
    var action = '';
    var note = billed.note;
    if (awaiting) {
      note = "Review what's covered and confirm to start this contract.";
      action = 'Review and accept';
    } else if (requested) {
      note = 'We received your request and will send a quote to review.';
    } else if (approved) {
      note = 'Your quote is ready to review.';
      action = 'Review quote';
    } else if (paused && billed.failed) {
      note = 'Cover is on hold until this payment goes through.';
      action = 'Update payment';
    } else if (upcoming && billed.label == 'Paid') {
      note = 'Cover begins on the start date.';
    }
    if (contract.canPay && billed.due) {
      action = 'Pay now';
    }

    return ContractRowView(
      uuid: contract.uuid,
      status: statusLabel,
      tone: tone,
      dot: dot,
      name: name,
      coverage: coverage,
      more: more,
      period: period,
      past: past,
      billing: past ? '' : billed.label,
      billingWarm: billed.warm,
      billingAmount: past ? '' : billed.amount,
      note: note,
      noteWarm: awaiting || requested || approved || paused || billed.warm,
      action: action,
      canPay: contract.canPay,
    );
  }
}

class _BillingView {
  const _BillingView({
    this.label = '',
    this.amount = '',
    this.note = '',
    this.warm = false,
    this.due = false,
    this.failed = false,
  });

  final String label;
  final String amount;
  final String note;
  final bool warm;
  final bool due;
  final bool failed;
}

_BillingView _billing(Contract contract, {required bool paused}) {
  final billing = contract.billing;
  final money = _money(
    (billing?.amount ?? '').trim().isEmpty
        ? contract.quotedAmount
        : billing!.amount,
    billing?.currency ?? contract.currency,
  );
  final code = billing?.status ?? '';
  if (code.isEmpty && money.isEmpty) return const _BillingView();

  if (code == 'PAST_DUE') {
    if (paused) {
      return _BillingView(
        label: 'Payment failed',
        amount: money,
        warm: true,
        failed: true,
      );
    }
    final due = billing?.periodEnd;
    return _BillingView(
      label: 'Payment due',
      amount: money,
      note: due == null ? '' : 'Due ${_shortDate(due)}',
      warm: true,
      due: true,
    );
  }
  if (code == 'INCOMPLETE') {
    return const _BillingView(label: 'Payment processing');
  }
  if (code == 'PENDING_CHECKOUT' || contract.status == 'PENDING_PAYMENT') {
    final due = billing?.periodEnd ?? contract.startsAt;
    return _BillingView(
      label: 'Payment due',
      amount: money,
      note: due == null ? '' : 'Due ${_shortDate(due)}',
      warm: true,
      due: true,
    );
  }
  if (code == 'ACTIVE' || code == 'CANCEL_AT_PERIOD_END') {
    return const _BillingView(label: 'Paid');
  }
  if (money.isNotEmpty && contract.status == 'ACTIVE') {
    return const _BillingView(label: 'Paid');
  }
  return const _BillingView();
}

String _period(
  Contract contract, {
  required bool awaiting,
  required bool ended,
  required bool cancelled,
}) {
  final start = contract.startsAt;
  final end = contract.endsAt;
  if (cancelled) {
    final when = contract.updatedAt ?? contract.createdAt;
    final began = start ?? contract.createdAt;
    return 'Cancelled ${_shortDate(when)} · started ${_shortDate(began)}';
  }
  if (ended && start != null && end != null) {
    return 'Ran ${_shortDate(start)} – ${_shortDate(end)}';
  }
  if (start == null) return '';
  final range = end == null
      ? _shortDate(start)
      : '${_shortDate(start)} – ${_shortDate(end)}';
  if (awaiting) return 'Proposed: $range';
  return range;
}

const _shortMonths = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

String _chipDate(DateTime value) =>
    '${value.day} ${_shortMonths[value.month - 1]}';

String _shortDate(DateTime value) {
  return '${value.day} ${_shortMonths[value.month - 1]} ${value.year}';
}

String _money(String raw, ContractCurrency currency) {
  final amount = double.tryParse(raw.trim());
  if (amount == null) return '';
  final sign = amount < 0 ? '-' : '';
  final abs = amount.abs();
  final whole = abs.truncate();
  final digits = '$whole';
  final grouped = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    final remaining = digits.length - i;
    if (i != 0 && remaining % 3 == 0) grouped.write(',');
    grouped.write(digits[i]);
  }
  var fraction = abs.toStringAsFixed(currency.decimalPlaces);
  fraction = fraction.contains('.') ? fraction.split('.').last : '';
  fraction = fraction.replaceFirst(RegExp(r'0+$'), '');
  final number = fraction.isEmpty ? grouped.toString() : '$grouped.$fraction';
  final code = currency.code.trim().isEmpty ? 'AED' : currency.code.trim();
  return '$code $sign$number';
}

enum ContractStickyKind { none, accept, pay, update }

class ContractCoverageRow {
  const ContractCoverageRow({
    required this.uuid,
    required this.name,
    required this.description,
    required this.chip,
    required this.a11y,
  });

  final String uuid;
  final String name;
  final String description;
  final String chip;
  final String a11y;
}

class ContractHistoryEvent {
  const ContractHistoryEvent({
    required this.label,
    required this.at,
    required this.current,
    required this.rail,
  });

  final String label;
  final String at;
  final bool current;
  final bool rail;

  FontWeight get weight => current ? FontWeight.w700 : FontWeight.w500;

  Color get color => current ? BlueColors.ink : BlueColors.muted;

  Color get dotBg => current ? BlueColors.ink : BlueColors.white;

  Color get dotBorder => current ? BlueColors.ink : BlueColors.checkLine;

  double get pad => rail ? 16 : 0;
}

class ContractEarlierBill {
  const ContractEarlierBill({
    required this.date,
    required this.state,
    required this.amount,
  });

  final String date;
  final String state;
  final String amount;
}

class ContractDetailView {
  const ContractDetailView({
    required this.uuid,
    required this.reference,
    required this.status,
    required this.tone,
    required this.dot,
    required this.name,
    required this.meaning,
    required this.alert,
    required this.periodTitle,
    required this.periodRange,
    required this.periodNote,
    required this.coverageTitle,
    required this.coverageNote,
    required this.coverage,
    required this.awaiting,
    required this.consentCopy,
    required this.termsReference,
    required this.billingLabel,
    required this.billingWarm,
    required this.billingAmount,
    required this.billingNote,
    required this.earlierBills,
    required this.moreBills,
    required this.history,
    required this.bookedNote,
    required this.sticky,
    required this.stickyLabel,
    required this.stickyFoot,
  });

  final String uuid;
  final String reference;
  final String status;
  final ContractTone tone;
  final bool dot;
  final String name;
  final String meaning;
  final String alert;
  final String periodTitle;
  final String periodRange;
  final String periodNote;
  final String coverageTitle;
  final String coverageNote;
  final List<ContractCoverageRow> coverage;
  final bool awaiting;
  final String consentCopy;
  final String termsReference;
  final String billingLabel;
  final bool billingWarm;
  final String billingAmount;
  final String billingNote;
  final List<ContractEarlierBill> earlierBills;
  final bool moreBills;
  final List<ContractHistoryEvent> history;
  final String bookedNote;
  final ContractStickyKind sticky;
  final String stickyLabel;
  final String stickyFoot;

  ContractChipStyle get chip => ContractChipStyle.of(tone);

  Color get nameColor =>
      tone == ContractTone.flat ? BlueColors.chipInk : BlueColors.ink;

  Color get billingColor =>
      billingWarm ? BlueColors.unavailableInk : BlueColors.chipInk;

  bool get hasAlert => alert.isNotEmpty;

  bool get hasBilling => billingLabel.isNotEmpty || billingAmount.isNotEmpty;

  bool get hasHistory => history.isNotEmpty;

  bool get hasBooked => bookedNote.isNotEmpty;

  bool get hasSticky => sticky != ContractStickyKind.none;

  String get statusA11y => 'Contract status $status. $meaning';

  factory ContractDetailView.fromContract(Contract contract, DateTime now) {
    final row = ContractRowView.fromContract(contract, now);
    final awaiting = contract.status == 'PENDING_CUSTOMER_ACCEPTANCE';
    final paused = contract.status == 'SUSPENDED';
    final ended = contract.status == 'EXPIRED';
    final cancelled = contract.status == 'CANCELLED';
    final past = ended || cancelled;
    final billed = _billing(contract, paused: paused);
    final money = _money(
      (contract.billing?.amount ?? '').trim().isEmpty
          ? contract.quotedAmount
          : contract.billing!.amount,
      contract.billing?.currency ?? contract.currency,
    );

    final names = [
      for (final item in contract.items)
        if (item.service.name.isNotEmpty) item.service.name,
    ];
    final name = names.isEmpty ? 'Service contract' : names.first;

    final meaning = _meaning(contract, row: row, now: now);
    final alert = awaiting
        ? 'Nothing is charged and nothing is covered until you accept.'
        : (paused
              ? 'Your bank declined the last payment, so cover is paused. Updating the payment restores it straight away.'
              : '');

    final period = _detailPeriod(
      contract,
      awaiting: awaiting,
      ended: ended,
      cancelled: cancelled,
      paused: paused,
    );

    final count = contract.items.length;
    final coverageTitle = count <= 1
        ? "What's covered"
        : "What's covered · $count services";
    final coverageNote = awaiting
        ? "This is what the contract will cover once it's active."
        : paused
        ? "Covered visits can't be booked while the contract is paused."
        : past
        ? ''
        : 'Included visits reset only when a new contract starts.';

    final coverage = [
      for (final item in contract.items) _coverageRow(item, past: past),
    ];

    var billingLabel = billed.label;
    var billingNote = billed.note;
    var billingWarm = billed.warm;
    var billingAmount = money;
    if (billed.label == 'Payment due' &&
        contract.status == 'ACTIVE' &&
        !paused) {
      final due = contract.billing?.periodEnd;
      billingNote = due == null
          ? 'Cover continues meanwhile.'
          : 'Due ${_shortDate(due)}. Cover continues meanwhile.';
    } else if (billed.label == 'Payment processing') {
      billingNote =
          "We're waiting for confirmation. Please don't pay again — we'll update this page.";
    } else if (billed.label == 'Paid') {
      billingNote = '';
    } else if (paused && billed.failed) {
      billingLabel = 'Payment needs attention';
      billingNote = '';
    }
    if (cancelled) {
      final refund = _money(
        contract.billing?.refundedAmount ?? '',
        contract.billing?.currency ?? contract.currency,
      );
      if (refund.isNotEmpty ||
          (contract.billing?.refundNote ?? '').isNotEmpty) {
        billingLabel = 'Refunded';
        billingAmount = refund;
        billingNote = contract.billing?.refundNote ?? '';
        billingWarm = false;
      } else {
        billingLabel = '';
        billingAmount = '';
        billingNote = '';
      }
    }

    final rawBills = contract.billing?.bills ?? const <ContractBillRow>[];
    final shownBills = rawBills.take(3).map((bill) {
      final when = bill.at == null ? bill.label : _shortDate(bill.at!);
      final state = bill.state.isEmpty ? 'Paid' : bill.state;
      final amount = _money(
        bill.amount,
        contract.billing?.currency ?? contract.currency,
      );
      return ContractEarlierBill(date: when, state: state, amount: amount);
    }).toList();

    final sticky = awaiting
        ? ContractStickyKind.accept
        : paused && billed.failed
        ? ContractStickyKind.update
        : (contract.canPay ||
              (contract.status == 'ACTIVE' && billed.due && !paused))
        ? ContractStickyKind.pay
        : ContractStickyKind.none;
    final stickyLabel = switch (sticky) {
      ContractStickyKind.accept => 'Accept contract',
      ContractStickyKind.pay => money.isEmpty ? 'Pay now' : 'Pay $money',
      ContractStickyKind.update => 'Update payment',
      ContractStickyKind.none => '',
    };
    final stickyFoot = switch (sticky) {
      ContractStickyKind.accept => 'Tick the box above to continue.',
      ContractStickyKind.pay ||
      ContractStickyKind.update => "Opens BLUE's secure payment sheet.",
      ContractStickyKind.none => '',
    };

    final booked = contract.bookings.where((row) => row.counts).length;
    final bookedNote = booked == 0 || awaiting || past
        ? ''
        : booked == 1
        ? 'One visit has been booked under this contract so far.'
        : '$booked visits have been booked under this contract so far.';

    return ContractDetailView(
      uuid: contract.uuid,
      reference: _reference(contract.contractNumber),
      status: row.status,
      tone: row.tone,
      dot: row.dot,
      name: name,
      meaning: meaning,
      alert: alert,
      periodTitle: period.title,
      periodRange: period.range,
      periodNote: period.note,
      coverageTitle: coverageTitle,
      coverageNote: coverageNote,
      coverage: coverage,
      awaiting: awaiting,
      consentCopy:
          'Accepting confirms the services, allowances and period shown above, and the contract terms BLUE has shared with you. This is a consent step, not a signed document — you can ask us to change anything before you accept.',
      termsReference: contract.termsReference,
      billingLabel: billingLabel,
      billingWarm: billingWarm,
      billingAmount: billingAmount,
      billingNote: billingNote,
      earlierBills: shownBills,
      moreBills: rawBills.length > 3,
      history: _history(contract),
      bookedNote: bookedNote,
      sticky: sticky,
      stickyLabel: stickyLabel,
      stickyFoot: stickyFoot,
    );
  }
}

class _PeriodDetail {
  const _PeriodDetail({this.title = '', this.range = '', this.note = ''});

  final String title;
  final String range;
  final String note;
}

_PeriodDetail _detailPeriod(
  Contract contract, {
  required bool awaiting,
  required bool ended,
  required bool cancelled,
  required bool paused,
}) {
  final start = contract.startsAt;
  final end = contract.endsAt;
  final range = start == null
      ? ''
      : end == null
      ? _shortDate(start)
      : '${_shortDate(start)} – ${_shortDate(end)}';
  if (awaiting) {
    return _PeriodDetail(
      title: 'Proposed period',
      range: range,
      note: 'Cover starts once you accept and payment is settled.',
    );
  }
  if (cancelled) {
    final stopped = contract.updatedAt;
    final ran = start == null
        ? (stopped == null ? '' : 'Ran until ${_shortDate(stopped)}')
        : stopped == null
        ? 'Ran ${_shortDate(start)}'
        : 'Ran ${_shortDate(start)} – ${_shortDate(stopped)}';
    return _PeriodDetail(
      title: 'Coverage period',
      range: ran,
      note: end == null
          ? ''
          : 'Originally due to run until ${_shortDate(end)}.',
    );
  }
  if (ended) {
    return _PeriodDetail(
      title: 'Coverage period',
      range: range.isEmpty ? '' : 'Ran $range',
      note: '',
    );
  }
  return _PeriodDetail(
    title: 'Coverage period',
    range: range,
    note: paused
        ? "Paused days don't extend the period."
        : (end == null ? '' : 'Cover ends on the last day of the period.'),
  );
}

String _meaning(
  Contract contract, {
  required ContractRowView row,
  required DateTime now,
}) {
  if (contract.status == 'PENDING_CUSTOMER_ACCEPTANCE') {
    return "Review what's covered and accept to activate this contract.";
  }
  if (contract.status == 'EXPIRED') {
    final end = contract.endsAt;
    return end == null
        ? 'This contract ran its full term.'
        : 'This contract ran its full term and ended on ${_shortDate(end)}.';
  }
  if (contract.status == 'CANCELLED') {
    final when = contract.updatedAt ?? contract.createdAt;
    return 'This contract was cancelled on ${_shortDate(when)}, before the end of its term.';
  }
  if (contract.status == 'SUSPENDED') {
    return 'Cover is on hold until this payment goes through.';
  }
  if (row.status.startsWith('Starts')) {
    return 'Cover begins on the start date.';
  }
  return 'Your services are covered. Book a covered visit whenever you need one.';
}

ContractCoverageRow _coverageRow(ContractItem item, {required bool past}) {
  final name = item.service.name.trim().isEmpty
      ? 'Included service'
      : item.service.name.trim();
  final chip = _allowance(item, past: past);
  final extra = item.description.isEmpty ? '' : ', ${item.description}';
  final chipA11y = chip.isEmpty ? '' : ', $chip';
  return ContractCoverageRow(
    uuid: item.uuid,
    name: name,
    description: item.description,
    chip: chip,
    a11y: '$name$chipA11y$extra',
  );
}

String _allowance(ContractItem item, {required bool past}) {
  final included = item.includedVisits;
  if (included == null || included <= 0) return '';
  if (!past) return '$included visits';
  if (item.usedVisits >= included) return '$included used';
  return '${item.usedVisits} of $included used';
}

final _uuidShape = RegExp(
  r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
);

String _reference(String number) {
  final value = number.trim();
  if (value.isEmpty || _uuidShape.hasMatch(value)) return '';
  return value;
}

List<ContractHistoryEvent> _history(Contract contract) {
  final events = <({DateTime at, String label})>[];
  events.add((at: contract.createdAt, label: 'Contract created'));
  final accepted = contract.acceptedAt;
  if (accepted != null) {
    events.add((at: accepted, label: 'Accepted by you'));
  }
  final started = contract.startsAt;
  final activated =
      contract.status == 'ACTIVE' ||
      contract.status == 'SUSPENDED' ||
      contract.status == 'EXPIRED' ||
      (contract.status == 'CANCELLED' &&
          started != null &&
          (contract.updatedAt ?? contract.createdAt).isAfter(started));
  if (activated && started != null) {
    events.add((at: started, label: 'Contract activated'));
  }
  if (contract.status == 'SUSPENDED') {
    events.add((
      at: contract.updatedAt ?? contract.createdAt,
      label: 'Contract paused',
    ));
  }
  if (contract.status == 'EXPIRED') {
    events.add((
      at: contract.endsAt ?? contract.updatedAt ?? contract.createdAt,
      label: 'Contract ended',
    ));
  }
  if (contract.status == 'CANCELLED') {
    events.add((
      at: contract.updatedAt ?? contract.createdAt,
      label: 'Contract cancelled',
    ));
  }
  events.sort((a, b) => b.at.compareTo(a.at));
  return [
    for (var i = 0; i < events.length; i++)
      ContractHistoryEvent(
        label: events[i].label,
        at: _shortDate(events[i].at),
        current: i == 0,
        rail: i != events.length - 1,
      ),
  ];
}
