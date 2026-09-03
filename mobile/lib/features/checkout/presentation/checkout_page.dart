import 'dart:async';

import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/shell_controller.dart';
import '../../../app/theme/blue_theme.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../payment/data/payment_models.dart';
import '../../payment/presentation/payment_page.dart';
import '../../services/data/service_detail.dart';
import '../../services/presentation/services_page.dart';
import '../../shell/presentation/widgets/blue_bottom_nav.dart';
import '../data/checkout_models.dart';
import '../data/checkout_repository.dart';
import 'checkout_appointment_page.dart';
import 'checkout_location_page.dart';
import 'widgets/checkout_widgets.dart';

enum _CheckoutBody { loading, ready, error }

class CheckoutPage extends StatefulWidget {
  const CheckoutPage({super.key});

  @override
  State<CheckoutPage> createState() => _CheckoutPageState();
}

class _CheckoutPageState extends State<CheckoutPage> {
  _CheckoutBody _body = _CheckoutBody.loading;
  CheckoutSnapshot _checkout = CheckoutSnapshot.empty();
  final _details = <String, ServiceDetail>{};
  CheckoutAppointmentKind _appointment = CheckoutAppointmentKind.none;
  CheckoutAppointment? _expiredSlot;
  int _holdSeconds = 0;
  ShellController? _shell;
  CheckoutRepository? _checkoutRepo;
  Timer? _holdTimer;
  int _seq = 0;
  CheckoutLocationDraft? _locationDraft;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      AppScope.of(context).shell.hideNav.value = true;
      _load();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _shell ??= AppScope.of(context).shell;
    _checkoutRepo ??= AppScope.of(context).checkout;
  }

  @override
  void dispose() {
    _holdTimer?.cancel();
    _shell?.hideNav.value = false;
    if (_checkout.appointment != null) {
      unawaited(
        _checkoutRepo?.releaseHold().catchError((_) => CheckoutSnapshot.empty()),
      );
    }
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    final seq = ++_seq;
    if (!silent) {
      setState(() => _body = _CheckoutBody.loading);
    }
    try {
      final checkout = await AppScope.of(context).checkout.get();
      if (!mounted || seq != _seq) return;
      await _hydrate(checkout);
      if (!mounted || seq != _seq) return;
      _apply(checkout);
    } catch (_) {
      if (!mounted || seq != _seq) return;
      setState(() => _body = _CheckoutBody.error);
    }
  }

  Future<void> _hydrate(CheckoutSnapshot checkout) async {
    final catalog = AppScope.of(context).catalog;
    final missing = <String>{};
    for (final item in checkout.items) {
      final slug = item.service.slug;
      if (slug.isEmpty || _details.containsKey(slug)) continue;
      missing.add(slug);
    }
    await Future.wait(
      missing.map((slug) async {
        try {
          _details[slug] = await catalog.getDetails(slug);
        } catch (_) {}
      }),
    );
  }

  void _apply(CheckoutSnapshot checkout) {
    var appointment = _appointment;
    var expired = _expiredSlot;
    if (checkout.appointment != null) {
      appointment = CheckoutAppointmentKind.held;
      expired = checkout.appointment;
    } else if (appointment != CheckoutAppointmentKind.expired) {
      appointment = CheckoutAppointmentKind.none;
    }
    setState(() {
      _checkout = checkout;
      _appointment = appointment;
      _expiredSlot = expired;
      _holdSeconds = checkout.appointment?.remainingSeconds() ?? 0;
      _body = _CheckoutBody.ready;
    });
    _syncHoldTimer();
  }

  void _syncHoldTimer() {
    _holdTimer?.cancel();
    if (_appointment != CheckoutAppointmentKind.held) return;
    final expires = _checkout.appointment?.expiresAt;
    if (expires == null) return;
    _holdTimer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      final left = expires.difference(DateTime.now()).inSeconds;
      if (left <= 0) {
        _holdTimer?.cancel();
        setState(() {
          _holdSeconds = 0;
          _appointment = CheckoutAppointmentKind.expired;
        });
        return;
      }
      if (left != _holdSeconds) {
        setState(() => _holdSeconds = left);
      }
    });
  }

  CheckoutReviewView get _view {
    return CheckoutReviewView.build(
      loading: _body == _CheckoutBody.loading,
      error: _body == _CheckoutBody.error,
      checkout: _checkout,
      appointment: _appointment,
      holdSeconds: _holdSeconds,
      details: _details,
      expiredSlot: _expiredSlot,
    );
  }

  void _back() {
    unawaited(_releaseHold());
    Navigator.of(context).pop();
  }

  Future<void> _releaseHold() async {
    if (_checkout.appointment == null) return;
    try {
      await AppScope.of(context).checkout.releaseHold();
    } catch (_) {}
  }

  void _browse() {
    Navigator.of(
      context,
    ).push(BluePageRoute<void>(builder: (_) => const ServicesPage()));
  }

  Future<void> _openLocation() async {
    final result = await Navigator.of(context).push<Object?>(
      BluePageRoute(
        builder: (_) => CheckoutLocationPage(
          location: _checkout.location,
          draft: _locationDraft,
        ),
      ),
    );
    if (!mounted) return;
    if (result == true) {
      _locationDraft = null;
      _load(silent: true);
    } else if (result is CheckoutLocationDraft) {
      _locationDraft = result;
    } else if (result == false) {
      _locationDraft = null;
    }
  }

  Future<void> _openAppointment() async {
    final saved = await Navigator.of(context).push<bool>(
      BluePageRoute(
        builder: (_) => CheckoutAppointmentPage(checkout: _checkout),
      ),
    );
    if (saved == true && mounted) {
      _appointment = CheckoutAppointmentKind.none;
      await _load(silent: true);
    }
  }

  Future<void> _openPayment() async {
    if (_view.ctaOff) return;
    final result = await Navigator.of(context).push<PaymentExit>(
      BluePageRoute(
        builder: (_) => PaymentPage(checkout: _checkout, details: _details),
      ),
    );
    if (!mounted) return;
    switch (result) {
      case PaymentExit.appointment:
        await _openAppointment();
      case PaymentExit.home:
        AppScope.of(context).shell.openTab(BlueTab.home);
        Navigator.of(context).popUntil((route) => route.isFirst);
      case PaymentExit.bookings:
        AppScope.of(context).shell.openTab(BlueTab.bookings);
        Navigator.of(context).popUntil((route) => route.isFirst);
      case PaymentExit.checkout:
      case null:
        await _load(silent: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final view = _view;
    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        bottom: false,
        child: BlueEnter(
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
                    child: CheckoutBackButton(onPressed: _back),
                  ),
                ),
              ),
              Expanded(child: _scroll(view)),
              if (view.showBar) CheckoutPayBar(view: view, onPay: _openPayment),
            ],
          ),
        ),
      ),
    );
  }

  Widget _scroll(CheckoutReviewView view) {
    return ListView(
      physics: const BouncingScrollPhysics(
        parent: AlwaysScrollableScrollPhysics(),
      ),
      padding: const EdgeInsets.fromLTRB(0, 6, 0, 26),
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: BlueDimens.checkoutGutter,
          ),
          child: CheckoutTitle(subtitle: view.subtitle),
        ),
        AnimatedSwitcher(
          duration: checkoutReviewFade(context),
          switchInCurve: Curves.easeOut,
          switchOutCurve: Curves.easeOut,
          child: switch (_body) {
            _CheckoutBody.loading => const CheckoutSkeleton(
              key: ValueKey('loading'),
            ),
            _CheckoutBody.error => CheckoutErrorState(
              key: const ValueKey('error'),
              onRetry: () => _load(),
              onBack: _back,
            ),
            _CheckoutBody.ready =>
              view.showEmpty
                  ? CheckoutEmptyState(
                      key: const ValueKey('empty'),
                      onBrowse: _browse,
                    )
                  : _review(view),
          },
        ),
      ],
    );
  }

  Widget _review(CheckoutReviewView view) {
    return Column(
      key: const ValueKey('review'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const CheckoutHairline(),
        CheckoutLocationBlock(
          view: view,
          onAdd: _openLocation,
          onChange: _openLocation,
        ),
        const CheckoutHairline(),
        CheckoutAppointmentBlock(
          view: view,
          onAdd: _openAppointment,
          onChange: _openAppointment,
        ),
        const CheckoutHairline(),
        CheckoutOrderBlock(
          view: view,
          onEditCart: _back,
          onChangeLocation: _openLocation,
        ),
        const CheckoutHairline(),
        CheckoutTotalBlock(view: view),
      ],
    );
  }
}
