import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_stripe/flutter_stripe.dart' hide PaymentMethod;

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../checkout/data/checkout_models.dart';
import '../../services/data/service_detail.dart';
import '../data/payment_models.dart';
import '../data/payment_repository.dart';
import '../data/stripe_checkout_payment.dart';
import 'widgets/payment_widgets.dart';

class PaymentPage extends StatefulWidget {
  const PaymentPage({
    super.key,
    required this.checkout,
    this.details = const {},
  });

  final CheckoutSnapshot checkout;
  final Map<String, ServiceDetail> details;

  @override
  State<PaymentPage> createState() => _PaymentPageState();
}

class _PaymentPageState extends State<PaymentPage> {
  late PaymentSnapshot _snapshot;
  PaymentPhase _phase = PaymentPhase.form;
  String? _methodId = 'card';
  bool _sheet = false;
  bool _providerSheet = false;
  bool _cancelled = false;
  int _holdSeconds = 0;
  String? _reviewedTotal;
  String? _updatedTotal;
  PaymentAttempt? _attempt;
  String? _key;
  Timer? _holdTimer;
  Timer? _waitTimer;
  int _seq = 0;

  @override
  void initState() {
    super.initState();
    _snapshot = PaymentSnapshot(
      checkout: widget.checkout,
      details: Map<String, ServiceDetail>.from(widget.details),
    );
    _reviewedTotal = _snapshot.money;
    _holdSeconds = widget.checkout.appointment?.remainingSeconds() ?? 0;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      AppScope.of(context).shell.hideNav.value = true;
      _refresh();
    });
    _syncHold();
  }

  @override
  void dispose() {
    _holdTimer?.cancel();
    _waitTimer?.cancel();
    super.dispose();
  }

  bool get _form => _phase == PaymentPhase.form;

  bool get _locked =>
      _phase == PaymentPhase.processing ||
      _phase == PaymentPhase.confirming ||
      _phase == PaymentPhase.unknown;

  bool get _showBack =>
      _form ||
      _phase == PaymentPhase.initError ||
      _phase == PaymentPhase.holdExpired ||
      _phase == PaymentPhase.failed ||
      _phase == PaymentPhase.priceChanged ||
      _phase == PaymentPhase.loading;

  bool get _showActions =>
      _phase != PaymentPhase.processing && _phase != PaymentPhase.loading;

  PaymentMethod? get _method => paymentMethodById(_methodId);

  void _syncHold() {
    _holdTimer?.cancel();
    if (!_form) return;
    final expires = _snapshot.checkout.appointment?.expiresAt;
    if (expires == null) return;
    _holdTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      final left = expires.difference(DateTime.now()).inSeconds;
      if (left <= 0) {
        _holdTimer?.cancel();
        setState(() {
          _holdSeconds = 0;
          _phase = PaymentPhase.holdExpired;
        });
        return;
      }
      if (left != _holdSeconds) {
        setState(() => _holdSeconds = left);
      }
    });
  }

  Future<void> _refresh() async {
    final seq = ++_seq;
    try {
      final checkout = await AppScope.of(context).checkout.get();
      if (!mounted || seq != _seq) return;
      final details = Map<String, ServiceDetail>.from(_snapshot.details);
      final catalog = AppScope.of(context).catalog;
      await Future.wait(
        checkout.items.map((item) async {
          final slug = item.service.slug;
          if (slug.isEmpty || details.containsKey(slug)) return;
          try {
            details[slug] = await catalog.getDetails(slug);
          } catch (_) {}
        }),
      );
      if (!mounted || seq != _seq) return;
      final next = PaymentSnapshot(
        checkout: checkout,
        details: details,
        reviewedTotal: _reviewedTotal,
      );
      final hold = checkout.appointment?.remainingSeconds() ?? 0;
      PaymentPhase phase = _phase;
      if (_form || _phase == PaymentPhase.loading) {
        if (hold <= 0 || checkout.appointment == null) {
          phase = PaymentPhase.holdExpired;
        } else if (_reviewedTotal != null &&
            next.money.isNotEmpty &&
            next.money != _reviewedTotal) {
          phase = PaymentPhase.priceChanged;
          _updatedTotal = next.money;
        } else if (!checkout.readyForPayment) {
          phase = PaymentPhase.initError;
        } else {
          phase = PaymentPhase.form;
        }
      }
      setState(() {
        _snapshot = next;
        _holdSeconds = hold;
        _phase = phase;
      });
      _syncHold();
    } catch (_) {
      if (!mounted || seq != _seq) return;
      if (_phase == PaymentPhase.loading) {
        setState(() => _phase = PaymentPhase.initError);
      }
    }
  }

  void _back() {
    if (_locked) return;
    Navigator.of(context).pop(PaymentExit.checkout);
  }

  void _closeSheet() {
    setState(() => _sheet = false);
  }

  void _openSheet() {
    setState(() => _sheet = true);
  }

  void _pick(String id) {
    setState(() {
      _methodId = id;
      _sheet = false;
      _cancelled = false;
    });
  }

  void _newCard() {
    setState(() {
      _sheet = false;
      _providerSheet = true;
    });
  }

  void _providerDone() {
    setState(() {
      _providerSheet = false;
      _methodId = 'card';
      _cancelled = false;
    });
  }

  void _wait(PaymentPhase next, Duration delay) {
    _waitTimer?.cancel();
    _waitTimer = Timer(delay, () {
      if (!mounted) return;
      setState(() => _phase = next);
    });
  }

  Future<void> _pay() async {
    if (_form && _method == null) {
      _openSheet();
      return;
    }
    _holdTimer?.cancel();
    _key = newPaymentKey();
    setState(() => _phase = PaymentPhase.processing);
    try {
      final attempt = await AppScope.of(
        context,
      ).payment.create(idempotencyKey: _key!);
      if (!mounted) return;
      _attempt = attempt;
      if (attempt.failed) {
        setState(() => _phase = PaymentPhase.failed);
        return;
      }
      if (attempt.successful) {
        setState(() => _phase = PaymentPhase.success);
        return;
      }
      if (attempt.clientSecret != null && attempt.publishableKey != null) {
        await StripeCheckoutPayment.present(
          attempt: attempt,
          merchantDisplayName: 'BLUE',
        );
      }
      await _finishProcessing();
    } on StripeException {
      if (!mounted) return;
      setState(() => _phase = PaymentPhase.failed);
    } on ApiException catch (error) {
      if (!mounted) return;
      if (error.statusCode == 409) {
        setState(() => _phase = PaymentPhase.confirming);
        return;
      }
      if (error.isNetwork) {
        setState(() => _phase = PaymentPhase.unknown);
        return;
      }
      setState(() => _phase = PaymentPhase.failed);
    } catch (_) {
      if (!mounted) return;
      setState(() => _phase = PaymentPhase.failed);
    }
  }

  Future<void> _finishProcessing() async {
    final uuid = _attempt?.uuid;
    if (uuid != null && uuid.isNotEmpty) {
      try {
        final latest = await AppScope.of(context).payment.get(uuid);
        if (!mounted) return;
        _attempt = latest;
      } catch (_) {}
    }
    if (!mounted) return;
    if (_attempt?.failed == true) {
      setState(() => _phase = PaymentPhase.failed);
      return;
    }
    if (_attempt?.successful == true) {
      setState(() => _phase = PaymentPhase.success);
      return;
    }
    setState(() => _phase = PaymentPhase.confirming);
  }

  Future<void> _checkStatus() async {
    setState(() => _phase = PaymentPhase.confirming);
    final uuid = _attempt?.uuid;
    if (uuid != null && uuid.isNotEmpty) {
      try {
        final attempt = await AppScope.of(context).payment.get(uuid);
        if (!mounted) return;
        _attempt = attempt;
        if (attempt.successful) {
          setState(() => _phase = PaymentPhase.success);
          return;
        }
        if (attempt.failed) {
          setState(() => _phase = PaymentPhase.failed);
          return;
        }
      } catch (_) {}
    }
    _wait(PaymentPhase.confirming, const Duration(milliseconds: 2200));
  }

  void _retry() {
    setState(() {
      _phase = PaymentPhase.form;
      _methodId ??= 'card';
      _cancelled = false;
      _holdSeconds = _snapshot.checkout.appointment?.remainingSeconds() ?? 0;
    });
    _syncHold();
  }

  void _useAnother() {
    setState(() {
      _phase = PaymentPhase.form;
      _cancelled = false;
      _sheet = true;
    });
    _syncHold();
  }

  void _home() {
    Navigator.of(context).pop(PaymentExit.home);
  }

  void _bookings() {
    Navigator.of(context).pop(PaymentExit.bookings);
  }

  void _appointment() {
    Navigator.of(context).pop(PaymentExit.appointment);
  }

  void _primary() {
    switch (_phase) {
      case PaymentPhase.form:
        _pay();
      case PaymentPhase.confirming:
      case PaymentPhase.unknown:
        _checkStatus();
      case PaymentPhase.failed:
      case PaymentPhase.initError:
        _retry();
      case PaymentPhase.holdExpired:
        _appointment();
      case PaymentPhase.priceChanged:
        _back();
      case PaymentPhase.success:
      case PaymentPhase.alreadyPaid:
        _bookings();
      case PaymentPhase.loading:
      case PaymentPhase.processing:
        break;
    }
  }

  void _secondary() {
    switch (_phase) {
      case PaymentPhase.failed:
        _useAnother();
      case PaymentPhase.initError:
      case PaymentPhase.holdExpired:
        _back();
      case PaymentPhase.success:
      case PaymentPhase.alreadyPaid:
        _home();
      default:
        break;
    }
  }

  @override
  Widget build(BuildContext context) {
    final copy = _copy();
    return PopScope(
      canPop: !_locked && !_sheet && !_providerSheet,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_providerSheet) {
          setState(() {
            _providerSheet = false;
            _cancelled = true;
            _phase = PaymentPhase.form;
          });
          return;
        }
        if (_sheet) {
          _closeSheet();
          return;
        }
        if (_locked) return;
        _back();
      },
      child: ColoredBox(
        color: BlueColors.canvas,
        child: SafeArea(
          bottom: false,
          child: Stack(
            children: [
              BlueEnter(
                duration: BlueMotion.rise,
                offset: const Offset(0, 0.018),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    SizedBox(
                      height: 52,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(
                          horizontal: BlueDimens.checkoutGutter,
                        ),
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: _showBack
                              ? PaymentBackButton(onPressed: _back)
                              : (_locked
                                    ? PaymentLockedBack(label: copy.locked)
                                    : const SizedBox.shrink()),
                        ),
                      ),
                    ),
                    Expanded(child: _body(copy)),
                    if (_showActions)
                      PaymentActions(
                        cta: copy.cta,
                        enabled: copy.enabled,
                        busy: copy.busy,
                        onPrimary: _primary,
                        secondary: copy.secondary,
                        onSecondary: copy.secondary == null ? null : _secondary,
                        foot: copy.foot,
                      ),
                  ],
                ),
              ),
              PaymentMethodSheet(
                open: _sheet,
                selectedId: _methodId,
                onClose: _closeSheet,
                onPick: _pick,
                onNewCard: _newCard,
              ),
              PaymentProviderSheet(open: _providerSheet, onDone: _providerDone),
            ],
          ),
        ),
      ),
    );
  }

  Widget _body(_PaymentCopy copy) {
    return AnimatedSwitcher(
      duration: paymentResultFade(context),
      switchInCurve: Curves.easeOut,
      switchOutCurve: Curves.easeOut,
      child: KeyedSubtree(
        key: ValueKey(_phase),
        child: switch (_phase) {
          PaymentPhase.loading => const SingleChildScrollView(
            padding: EdgeInsets.only(top: 6, bottom: 24),
            child: PaymentSkeleton(),
          ),
          PaymentPhase.form => _formBody(),
          _ => SingleChildScrollView(
            padding: const EdgeInsets.only(bottom: 24),
            child: PaymentResult(
              phase: _phase,
              title: copy.title,
              body: copy.body,
              top: copy.top,
              titleSize: copy.titleSize,
              receipt: copy.receipt,
              note: copy.note,
              warmNote: copy.warmNote,
            ),
          ),
        },
      ),
    );
  }

  Widget _formBody() {
    return ListView(
      physics: const BouncingScrollPhysics(
        parent: AlwaysScrollableScrollPhysics(),
      ),
      padding: const EdgeInsets.fromLTRB(0, 6, 0, 24),
      children: [
        if (_cancelled) const PaymentCancelledBanner(),
        PaymentAmountBlock(amount: _snapshot.money),
        const PaymentHairline(),
        PaymentSummaryBlock(lines: _snapshot.summary, onDetails: _back),
        if (_snapshot.checkout.appointment != null)
          PaymentHoldBanner(seconds: _holdSeconds, warn: _holdSeconds < 120),
        const PaymentHairline(),
        PaymentMethodBlock(method: _method, onChange: _openSheet),
      ],
    );
  }

  _PaymentCopy _copy() {
    final money = _snapshot.money;
    final method = _method;
    final provider = paymentProviderName;
    switch (_phase) {
      case PaymentPhase.loading:
        return const _PaymentCopy(cta: '', enabled: false);
      case PaymentPhase.form:
        return _PaymentCopy(
          cta: method == null ? 'Select payment method' : 'Pay $money',
          enabled: method != null,
          foot: method == null
              ? null
              : 'Tapping Pay charges $money to ${method.title}.',
        );
      case PaymentPhase.processing:
        return _PaymentCopy(
          title: 'Processing payment',
          body:
              "Please keep this screen open. We're waiting for $provider to confirm the charge — this usually takes a few seconds.",
          top: 108,
          titleSize: 21,
          locked: 'Payment in progress',
          cta: 'Processing…',
          enabled: false,
          busy: true,
        );
      case PaymentPhase.confirming:
        return _PaymentCopy(
          title: 'Confirming your payment',
          body:
              "Your payment went through to $provider and we're waiting for final confirmation. Don't pay again — we'll update this screen as soon as we know.",
          top: 108,
          titleSize: 21,
          locked: 'Confirming payment',
          cta: 'Check status',
          note:
              "Leaving is safe. If confirmation arrives while you're away, your booking appears in Bookings.",
          foot: "Checking again won't create a second payment.",
        );
      case PaymentPhase.success:
        return _PaymentCopy(
          title: 'Payment successful',
          body:
              "Your booking is confirmed. We've emailed the details and the technician will arrive inside your window.",
          top: 78,
          titleSize: 24,
          cta: 'View booking',
          secondary: 'Back to home',
          receipt: [
            PaymentReceiptRow(label: 'Booking', value: _bookingId()),
            PaymentReceiptRow(label: 'Paid', value: money),
            PaymentReceiptRow(label: 'Service', value: _snapshot.serviceName),
            PaymentReceiptRow(label: 'Appointment', value: _snapshot.whenShort),
            PaymentReceiptRow(label: 'Method', value: method?.title ?? ''),
          ],
        );
      case PaymentPhase.failed:
        return const _PaymentCopy(
          title: "Payment wasn't completed",
          body:
              'Your bank declined the charge, so nothing was taken. This is usually a limit or a bank block rather than a problem with your card.',
          top: 100,
          titleSize: 21,
          cta: 'Try again',
          secondary: 'Use another method',
          note:
              'Your appointment is still reserved while the hold lasts. If it expires you can pick a new time without losing your cart.',
        );
      case PaymentPhase.unknown:
        return _PaymentCopy(
          title: "We're confirming your payment",
          body:
              "The connection dropped after you paid, so we can't tell yet whether the charge went through. Please don't try again — that could charge you twice.",
          top: 96,
          titleSize: 21,
          locked: 'Confirming payment',
          cta: 'Check status',
          note:
              "We'll keep checking with $provider. If the payment did land, your booking is already confirmed and will show in Bookings.",
          warmNote: true,
          foot: "Checking again won't create a second payment.",
        );
      case PaymentPhase.holdExpired:
        final slot = _snapshot.checkout.appointment?.slot;
        final window = slot == null
            ? _snapshot.whenShort
            : formatPaymentWindow(slot);
        return _PaymentCopy(
          title: 'Your reserved time has expired',
          body:
              'Nothing was charged. The $window window is no longer held, so pick another time to continue — your cart and location are saved.',
          top: 100,
          titleSize: 21,
          cta: 'Choose another appointment',
          secondary: 'Back to checkout',
        );
      case PaymentPhase.initError:
        return const _PaymentCopy(
          title: "We couldn't prepare payment",
          body:
              'Nothing was charged and nothing was lost — your cart, location and appointment are all still saved on your account.',
          top: 100,
          titleSize: 21,
          cta: 'Try again',
          secondary: 'Back to checkout',
        );
      case PaymentPhase.alreadyPaid:
        return _PaymentCopy(
          title: 'This booking is already paid',
          body:
              "We received your payment for this booking, so there's nothing more to pay. Opening payment again won't charge you.",
          top: 78,
          titleSize: 22,
          cta: 'View booking',
          secondary: 'Back to home',
          receipt: [
            PaymentReceiptRow(label: 'Booking', value: _bookingId()),
            PaymentReceiptRow(label: 'Paid', value: money),
            PaymentReceiptRow(
              label: 'Paid on',
              value: formatPaymentPaidOn(DateTime.now()),
            ),
          ],
        );
      case PaymentPhase.priceChanged:
        return _PaymentCopy(
          title: 'Your total has changed',
          body:
              "The amount for this booking is no longer what you reviewed, so we've stopped before charging anything. Take a look and continue when it's right.",
          top: 100,
          titleSize: 21,
          cta: 'Review checkout',
          receipt: [
            PaymentReceiptRow(
              label: 'Total you reviewed',
              value: _reviewedTotal ?? money,
            ),
            PaymentReceiptRow(
              label: 'Updated total',
              value: _updatedTotal ?? money,
            ),
          ],
        );
    }
  }

  String _bookingId() {
    final ref = _attempt?.checkoutReference?.trim() ?? '';
    if (ref.isNotEmpty) return ref;
    final id = _snapshot.checkout.cartUuid ?? '';
    if (id.length >= 8) {
      return 'BLU-${id.substring(0, 4).toUpperCase()}-${id.substring(4, 6).toUpperCase()}';
    }
    return 'BLU-4827-QK';
  }
}

class _PaymentCopy {
  const _PaymentCopy({
    this.title = '',
    this.body = '',
    this.top = 100,
    this.titleSize = 21,
    this.locked = 'Confirming payment',
    required this.cta,
    this.enabled = true,
    this.busy = false,
    this.secondary,
    this.foot,
    this.note,
    this.warmNote = false,
    this.receipt = const [],
  });

  final String title;
  final String body;
  final double top;
  final double titleSize;
  final String locked;
  final String cta;
  final bool enabled;
  final bool busy;
  final String? secondary;
  final String? foot;
  final String? note;
  final bool warmNote;
  final List<PaymentReceiptRow> receipt;
}
