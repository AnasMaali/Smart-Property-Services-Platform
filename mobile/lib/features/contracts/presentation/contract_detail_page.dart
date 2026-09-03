import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/app_scope.dart';
import '../../../app/shell_controller.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../checkout/data/checkout_models.dart';
import '../../checkout/presentation/checkout_appointment_page.dart';
import '../../shell/presentation/widgets/blue_bottom_nav.dart';
import '../data/contract_models.dart';
import 'widgets/contract_detail_widgets.dart';

enum _DetailBody { loading, ready, error, notFound }

class ContractDetailPage extends StatefulWidget {
  const ContractDetailPage({super.key, required this.contractUuid});

  final String contractUuid;

  @override
  State<ContractDetailPage> createState() => _ContractDetailPageState();
}

class _ContractDetailPageState extends State<ContractDetailPage>
    with WidgetsBindingObserver {
  _DetailBody _body = _DetailBody.loading;
  Contract? _contract;
  ShellController? _shell;
  int _seq = 0;
  bool _consented = false;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      AppScope.of(context).shell.hideNav.value = true;
      _load();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _shell ??= AppScope.of(context).shell;
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _shell?.hideNav.value = false;
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _body == _DetailBody.ready) {
      _load(silent: true);
    }
  }

  Future<void> _load({bool silent = false}) async {
    final seq = ++_seq;
    if (!silent) {
      setState(() => _body = _DetailBody.loading);
    }
    try {
      final contract = await AppScope.of(
        context,
      ).contracts.get(widget.contractUuid);
      if (!mounted || seq != _seq) return;
      setState(() {
        _contract = contract;
        _body = _DetailBody.ready;
        if (contract.status != 'PENDING_CUSTOMER_ACCEPTANCE') {
          _consented = false;
        }
      });
    } on ApiException catch (error) {
      if (!mounted || seq != _seq) return;
      setState(() {
        _body = error.statusCode == 404
            ? _DetailBody.notFound
            : (silent ? _body : _DetailBody.error);
      });
    } catch (_) {
      if (!mounted || seq != _seq) return;
      setState(() {
        _body = silent ? _body : _DetailBody.error;
      });
    }
  }

  void _back() {
    Navigator.of(context).maybePop();
  }

  Future<void> _accept() async {
    if (_busy || !_consented) return;
    setState(() => _busy = true);
    try {
      final contract = await AppScope.of(
        context,
      ).contracts.accept(widget.contractUuid);
      if (!mounted) return;
      setState(() {
        _contract = contract;
        _body = _DetailBody.ready;
        _consented = false;
        _busy = false;
      });
    } on ApiException {
      if (!mounted) return;
      setState(() => _busy = false);
      await _load(silent: true);
    } catch (_) {
      if (!mounted) return;
      setState(() => _busy = false);
    }
  }

  Future<void> _pay() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      final url = await AppScope.of(
        context,
      ).contracts.createBillingCheckout(widget.contractUuid);
      if (!mounted) return;
      setState(() => _busy = false);
      await _openCheckout(url);
    } on ApiException {
      if (!mounted) return;
      setState(() => _busy = false);
      final view = _contract?.detail();
      await showContractHelpSheet(context, view?.reference ?? '');
    } catch (_) {
      if (!mounted) return;
      setState(() => _busy = false);
    }
  }

  Future<void> _openCheckout(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null || !(uri.isScheme('https') || uri.isScheme('http'))) {
      return;
    }
    try {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {}
  }

  void _onSticky(ContractDetailView view) {
    switch (view.sticky) {
      case ContractStickyKind.accept:
        _accept();
      case ContractStickyKind.pay:
      case ContractStickyKind.update:
        _pay();
      case ContractStickyKind.none:
        break;
    }
  }

  void _openBookings() {
    Navigator.of(context).pop();
    AppScope.of(context).shell.openTab(BlueTab.bookings);
  }

  Future<void> _bookVisit(String contractItemUuid) async {
    if (_busy) return;

    final contracts = AppScope.of(context).contracts;
    final slot = await Navigator.of(context).push<CheckoutSlot>(
      MaterialPageRoute(
        builder: (_) => CheckoutAppointmentPage(
          confirmMode: AppointmentConfirmMode.pick,
          slotsLoader: contracts.listAppointmentSlots,
        ),
      ),
    );
    if (!mounted || slot == null) return;

    setState(() => _busy = true);
    try {
      await contracts.createBooking(
        contractUuid: widget.contractUuid,
        contractItemUuid: contractItemUuid,
        appointmentSlotUuid: slot.uuid,
      );
      if (!mounted) return;
      setState(() => _busy = false);
      await _load(silent: true);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Visit booked successfully.')),
      );
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _busy = false);
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(error.message)));
    } catch (_) {
      if (!mounted) return;
      setState(() => _busy = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Could not book visit. Please try again.'),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final ready = _body == _DetailBody.ready && _contract != null;
    final view = ready ? _contract!.detail() : null;
    final dead = _body != _DetailBody.ready;
    final stickyOn = view != null && view.hasSticky;

    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        bottom: !stickyOn,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ContractDetailAppBar(
              reference: dead ? '' : (view?.reference ?? ''),
              onBack: _back,
            ),
            Expanded(
              child: RefreshIndicator(
                color: BlueColors.ink,
                backgroundColor: BlueColors.white,
                displacement: 28,
                notificationPredicate: (_) =>
                    _body == _DetailBody.ready || _body == _DetailBody.loading,
                onRefresh: () => _load(silent: _body == _DetailBody.ready),
                child: CustomScrollView(
                  physics: const BouncingScrollPhysics(
                    parent: AlwaysScrollableScrollPhysics(),
                  ),
                  slivers: [
                    SliverPadding(
                      padding: EdgeInsets.fromLTRB(0, 6, 0, stickyOn ? 24 : 34),
                      sliver: SliverList.list(
                        children: [
                          if (_body == _DetailBody.loading)
                            const ContractDetailSkeleton(),
                          if (_body == _DetailBody.error)
                            ContractDetailFail(
                              notFound: false,
                              onRetry: () => _load(),
                              onBack: _back,
                            ),
                          if (_body == _DetailBody.notFound)
                            ContractDetailFail(
                              notFound: true,
                              onRetry: _back,
                              onBack: _back,
                            ),
                          if (view != null)
                            BlueEnter(
                              key: ValueKey(widget.contractUuid),
                              duration: const Duration(milliseconds: 180),
                              offset: Offset.zero,
                              child: ContractDetailBody(
                                view: view,
                                consented: _consented,
                                onConsent: (value) =>
                                    setState(() => _consented = value),
                                onHelp: () => showContractHelpSheet(
                                  context,
                                  view.reference,
                                ),
                                onBookings: _openBookings,
                                onBills: () => showContractHelpSheet(
                                  context,
                                  view.reference,
                                ),
                                onBookVisit: _bookVisit,
                              ),
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            if (view != null)
              ContractDetailSticky(
                view: view,
                enabled: view.sticky != ContractStickyKind.accept || _consented,
                busy: _busy,
                onPressed: () => _onSticky(view),
              ),
          ],
        ),
      ),
    );
  }
}
