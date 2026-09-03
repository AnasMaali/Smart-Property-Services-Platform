import 'dart:async';

import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/shell_controller.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../services/data/service_detail.dart';
import '../../checkout/presentation/checkout_page.dart';
import '../../services/presentation/service_detail_page.dart';
import '../../services/presentation/services_page.dart';
import '../../shell/presentation/widgets/blue_bottom_nav.dart';
import '../data/cart_models.dart';
import 'widgets/cart_widgets.dart';

enum _CartBody { loading, ready, error }

class _RemovedLine {
  const _RemovedLine({required this.index, required this.item});

  final int index;
  final CartItem item;
}

class CartPage extends StatefulWidget {
  const CartPage({super.key});

  @override
  State<CartPage> createState() => _CartPageState();
}

class _CartPageState extends State<CartPage> {
  _CartBody _body = _CartBody.loading;
  CartSnapshot _cart = CartSnapshot.empty();
  final _details = <String, ServiceDetail>{};
  final _expanded = <String>{};
  ShellController? _shell;
  int _seq = 0;
  Timer? _undoTimer;
  _RemovedLine? _pending;
  String? _undoName;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_shell != null) return;
    _shell = AppScope.of(context).shell;
    _shell!.tab.addListener(_onTab);
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _undoTimer?.cancel();
    _shell?.tab.removeListener(_onTab);
    final pending = _pending;
    if (pending != null) {
      unawaited(_commitRemove(pending.item.uuid));
    }
    super.dispose();
  }

  void _onTab() {
    if (_shell?.tab.value != BlueTab.cart) return;
    _load(silent: _body == _CartBody.ready);
  }

  Future<void> _load({bool silent = false}) async {
    final seq = ++_seq;
    if (!silent) {
      setState(() => _body = _CartBody.loading);
    }
    try {
      final cart = await AppScope.of(context).cart.get();
      if (!mounted || seq != _seq) return;
      await _hydrate(cart);
      if (!mounted || seq != _seq) return;
      _syncCount(cart);
      setState(() {
        _cart = cart;
        _body = _CartBody.ready;
      });
    } catch (_) {
      if (!mounted || seq != _seq) return;
      setState(() => _body = _CartBody.error);
    }
  }

  Future<void> _hydrate(CartSnapshot cart) async {
    final catalog = AppScope.of(context).catalog;
    final missing = <String>{};
    for (final item in cart.items) {
      final slug = item.service.slug;
      if (slug.isEmpty || _details.containsKey(slug)) continue;
      missing.add(slug);
    }
    await Future.wait(
      missing.map((slug) async {
        try {
          final detail = await catalog.getDetails(slug);
          _details[slug] = detail;
        } catch (_) {}
      }),
    );
  }

  void _syncCount(CartSnapshot cart) {
    AppScope.of(context).shell.cartCount.value = cart.serviceCount;
  }

  String get _subtitle {
    if (_body == _CartBody.loading) return 'Loading your items...';
    if (_body == _CartBody.error) return '';
    if (_cart.isEmpty) return 'Nothing saved yet.';
    final services = _cart.serviceCount == 1
        ? '1 service'
        : '${_cart.serviceCount} services';
    final units = _cart.unitCount == 1 ? '1 unit' : '${_cart.unitCount} units';
    return '$services · $units';
  }

  Future<void> _setQuantity(CartItem item, int quantity) async {
    if (item.quantityLocked) return;
    if (quantity < 1 || quantity > 1000 || item.pricing.isUnavailable) return;
    final previous = _cart;
    final next = _cart.items
        .map((row) => row.uuid == item.uuid ? row.withQuantity(quantity) : row)
        .toList();
    setState(() => _cart = _cart.copyWith(items: next));
    try {
      final cart = await AppScope.of(
        context,
      ).cart.updateItem(itemUuid: item.uuid, quantity: quantity);
      if (!mounted) return;
      await _hydrate(cart);
      if (!mounted) return;
      _syncCount(cart);
      setState(() => _cart = cart);
    } catch (_) {
      if (!mounted) return;
      setState(() => _cart = previous);
    }
  }

  void _remove(CartItem item) {
    final index = _cart.items.indexWhere((row) => row.uuid == item.uuid);
    if (index < 0) return;
    unawaited(_flushPending());
    final next = [..._cart.items]..removeAt(index);
    _undoTimer?.cancel();
    setState(() {
      _cart = _cart.copyWith(items: next);
      _pending = _RemovedLine(index: index, item: item);
      _undoName = item.service.name;
    });
    _syncCount(_cart);
    _undoTimer = Timer(const Duration(seconds: 5), () {
      final pending = _pending;
      if (pending == null) return;
      unawaited(_commitRemove(pending.item.uuid));
      if (!mounted) return;
      setState(() {
        _pending = null;
        _undoName = null;
      });
    });
  }

  void _undo() {
    final pending = _pending;
    if (pending == null) return;
    _undoTimer?.cancel();
    final next = [..._cart.items];
    final index = pending.index.clamp(0, next.length);
    next.insert(index, pending.item);
    setState(() {
      _cart = _cart.copyWith(items: next);
      _pending = null;
      _undoName = null;
    });
    _syncCount(_cart);
  }

  Future<void> _flushPending() async {
    final pending = _pending;
    if (pending == null) return;
    _undoTimer?.cancel();
    _pending = null;
    _undoName = null;
    await _commitRemove(pending.item.uuid);
  }

  Future<void> _commitRemove(String uuid) async {
    try {
      await AppScope.of(context).cart.removeItem(uuid);
    } on ApiException {
      // Item is already gone from the local cart; a later refresh heals it.
    } catch (_) {}
  }

  void _browse() {
    Navigator.of(
      context,
    ).push(BluePageRoute<void>(builder: (_) => const ServicesPage()));
  }

  void _changeDetails(CartItem item) {
    AppScope.of(context).shell.hideNav.value = true;
    Navigator.of(context)
        .push(
          BluePageRoute<void>(
            builder: (_) => ServiceDetailPage(
              slug: item.service.slug,
              cartItemUuid: item.uuid,
              initialOptions: item.optionPayload(),
            ),
          ),
        )
        .whenComplete(() {
          if (mounted) _load(silent: true);
        });
  }

  void _checkout() {
    if (_cart.checkoutBlocked) return;
    unawaited(_flushPending());
    AppScope.of(context).shell.hideNav.value = true;
    Navigator.of(context)
        .push(BluePageRoute<void>(builder: (_) => const CheckoutPage()))
        .whenComplete(() {
          if (mounted) _load(silent: true);
        });
  }

  Future<void> _clearCart() async {
    if (_cart.isEmpty) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Clear cart'),
        content: const Text('Are you sure you want to clear the cart?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Clear'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    try {
      await _flushPending();
      final cart = await AppScope.of(context).cart.clear();
      if (!mounted) return;
      _syncCount(cart);
      setState(() {
        _cart = cart;
        _details.clear();
        _expanded.clear();
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Cart cleared')),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Could not clear cart. Please try again.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
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
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  BlueDimens.homeGutter,
                  12,
                  BlueDimens.homeGutter,
                  0,
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: CartHeader(subtitle: _subtitle)),
                    if (_body == _CartBody.ready && !_cart.isEmpty)
                      TextButton(
                        onPressed: _clearCart,
                        child: const Text('Clear'),
                      ),
                  ],
                ),
              ),
              Expanded(child: _bodyContent()),
              if (_undoName != null)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                  child: CartUndoToast(name: _undoName!, onUndo: _undo),
                ),
              if (_body == _CartBody.ready && !_cart.isEmpty)
                CartCheckoutBar(cart: _cart, onCheckout: _checkout),
            ],
          ),
        ),
      ),
    );
  }

  Widget _bodyContent() {
    return AnimatedSwitcher(
      duration: BlueMotion.of(context, BlueMotion.snap),
      switchInCurve: BlueMotion.curve,
      switchOutCurve: Curves.easeOut,
      child: switch (_body) {
        _CartBody.loading => const CartSkeleton(key: ValueKey('loading')),
        _CartBody.error => CartErrorState(
          key: const ValueKey('error'),
          onRetry: () => _load(),
        ),
        _CartBody.ready => KeyedSubtree(
          key: const ValueKey('ready'),
          child: _readyBody(),
        ),
      },
    );
  }

  Widget _readyBody() {
    if (_cart.isEmpty) {
      return CartEmptyState(onBrowse: _browse);
    }
    return ListView.builder(
      physics: const BouncingScrollPhysics(
        parent: AlwaysScrollableScrollPhysics(),
      ),
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        10,
        BlueDimens.homeGutter,
        18,
      ),
      itemCount: _cart.items.length + 1,
      itemBuilder: (context, index) {
        if (index == _cart.items.length) {
          return CartSummary(cart: _cart);
        }
        final item = _cart.items[index];
        return DecoratedBox(
          decoration: const BoxDecoration(
            border: Border(bottom: BorderSide(color: BlueColors.sheetHairline)),
          ),
          child: CartLineItem(
            item: item,
            currencyCode: _cart.currency.code,
            decimalPlaces: _cart.currency.decimalPlaces,
            expanded: _expanded.contains(item.uuid),
            detail: _details[item.service.slug],
            onIncrement: () => _setQuantity(item, item.quantity + 1),
            onDecrement: () => _setQuantity(item, item.quantity - 1),
            onRemove: () => _remove(item),
            onToggleConfig: () {
              setState(() {
                if (_expanded.contains(item.uuid)) {
                  _expanded.remove(item.uuid);
                } else {
                  _expanded.add(item.uuid);
                }
              });
            },
            onChangeDetails: () => _changeDetails(item),
          ),
        );
      },
    );
  }
}
