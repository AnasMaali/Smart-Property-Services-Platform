import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../app/app_scope.dart';
import '../../../app/shell_controller.dart';
import '../../../app/theme/blue_theme.dart';
import '../../account/presentation/account_page.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../bookings/presentation/bookings_page.dart';
import '../../cart/presentation/cart_page.dart';
import '../../contracts/presentation/contracts_page.dart';
import '../../home/presentation/home_page.dart';
import 'widgets/blue_bottom_nav.dart';

class MainShell extends StatefulWidget {
  const MainShell({super.key});

  @override
  State<MainShell> createState() => _MainShellState();
}

class _MainShellState extends State<MainShell> {
  final _homeNav = GlobalKey<NavigatorState>();
  final _cartNav = GlobalKey<NavigatorState>();
  final _bookingsNav = GlobalKey<NavigatorState>();
  final _contractsNav = GlobalKey<NavigatorState>();
  final _accountNav = GlobalKey<NavigatorState>();
  late final _HideNavObserver _homeObserver;
  late final _HideNavObserver _cartObserver;
  late final _HideNavObserver _bookingsObserver;
  late final _HideNavObserver _contractsObserver;
  late final _HideNavObserver _accountObserver;
  BlueTab _tab = BlueTab.home;
  ShellController? _shell;

  @override
  void initState() {
    super.initState();
    _homeObserver = _HideNavObserver(_syncHideNav);
    _cartObserver = _HideNavObserver(_syncHideNav);
    _bookingsObserver = _HideNavObserver(_syncHideNav);
    _contractsObserver = _HideNavObserver(_syncHideNav);
    _accountObserver = _HideNavObserver(_syncHideNav);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_shell != null) return;
    _shell = AppScope.of(context).shell;
    _shell!.tab.addListener(_syncTab);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _refreshCart();
      _syncHideNav();
    });
  }

  @override
  void dispose() {
    _shell?.tab.removeListener(_syncTab);
    super.dispose();
  }

  void _syncTab() {
    final next = AppScope.of(context).shell.tab.value;
    if (next == _tab) return;
    setState(() => _tab = next);
    _syncHideNav();
  }

  NavigatorState? _navFor(BlueTab tab) {
    return switch (tab) {
      BlueTab.home => _homeNav.currentState,
      BlueTab.cart => _cartNav.currentState,
      BlueTab.bookings => _bookingsNav.currentState,
      BlueTab.contracts => _contractsNav.currentState,
      BlueTab.account => _accountNav.currentState,
    };
  }

  void _syncHideNav() {
    final shell = _shell;
    if (shell == null || !mounted) return;
    final hide = _navFor(_tab)?.canPop() ?? false;
    if (shell.hideNav.value != hide) {
      shell.hideNav.value = hide;
    }
  }

  Future<void> _refreshCart() async {
    final scope = AppScope.of(context);
    try {
      final count = await scope.cart.itemCount();
      if (!mounted) return;
      scope.shell.cartCount.value = count;
    } catch (_) {}
  }

  void _selectTab(BlueTab tab) {
    AppScope.of(context).shell.tab.value = tab;
    if (tab == BlueTab.home && _tab == BlueTab.home) {
      _homeNav.currentState?.popUntil((route) => route.isFirst);
    }
    if (tab == BlueTab.cart && _tab == BlueTab.cart) {
      _cartNav.currentState?.popUntil((route) => route.isFirst);
    }
    if (tab == BlueTab.bookings && _tab == BlueTab.bookings) {
      _bookingsNav.currentState?.popUntil((route) => route.isFirst);
    }
    if (tab == BlueTab.contracts && _tab == BlueTab.contracts) {
      _contractsNav.currentState?.popUntil((route) => route.isFirst);
    }
    if (tab == BlueTab.account && _tab == BlueTab.account) {
      _accountNav.currentState?.popUntil((route) => route.isFirst);
    }
    setState(() => _tab = tab);
    _syncHideNav();
  }

  @override
  Widget build(BuildContext context) {
    final shell = AppScope.of(context).shell;
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        final homeNav = _homeNav.currentState;
        if (_tab == BlueTab.home && homeNav != null && homeNav.canPop()) {
          homeNav.pop();
          return;
        }
        final cartNav = _cartNav.currentState;
        if (_tab == BlueTab.cart && cartNav != null && cartNav.canPop()) {
          cartNav.pop();
          return;
        }
        final bookingsNav = _bookingsNav.currentState;
        if (_tab == BlueTab.bookings &&
            bookingsNav != null &&
            bookingsNav.canPop()) {
          bookingsNav.pop();
          return;
        }
        final contractsNav = _contractsNav.currentState;
        if (_tab == BlueTab.contracts &&
            contractsNav != null &&
            contractsNav.canPop()) {
          contractsNav.pop();
          return;
        }
        final accountNav = _accountNav.currentState;
        if (_tab == BlueTab.account &&
            accountNav != null &&
            accountNav.canPop()) {
          accountNav.pop();
          return;
        }
        SystemNavigator.pop();
      },
      child: AnnotatedRegion<SystemUiOverlayStyle>(
        value: const SystemUiOverlayStyle(
          statusBarColor: Colors.transparent,
          statusBarIconBrightness: Brightness.dark,
          statusBarBrightness: Brightness.light,
          systemNavigationBarColor: BlueColors.white,
          systemNavigationBarIconBrightness: Brightness.dark,
        ),
        child: Scaffold(
          backgroundColor: BlueColors.canvas,
          body: Column(
            children: [
              Expanded(
                child: BlueIndexedFade(
                  index: _tab.index,
                  duration: BlueMotion.snap,
                  children: [
                    Navigator(
                      key: _homeNav,
                      observers: [_homeObserver],
                      onGenerateRoute: (settings) {
                        return BluePageRoute<void>(
                          settings: settings,
                          builder: (_) => const HomePage(),
                        );
                      },
                    ),
                    Navigator(
                      key: _cartNav,
                      observers: [_cartObserver],
                      onGenerateRoute: (settings) {
                        return BluePageRoute<void>(
                          settings: settings,
                          builder: (_) => const CartPage(),
                        );
                      },
                    ),
                    Navigator(
                      key: _bookingsNav,
                      observers: [_bookingsObserver],
                      onGenerateRoute: (settings) {
                        return BluePageRoute<void>(
                          settings: settings,
                          builder: (_) => const BookingsPage(),
                        );
                      },
                    ),
                    Navigator(
                      key: _contractsNav,
                      observers: [_contractsObserver],
                      onGenerateRoute: (settings) {
                        return BluePageRoute<void>(
                          settings: settings,
                          builder: (_) => const ContractsPage(),
                        );
                      },
                    ),
                    Navigator(
                      key: _accountNav,
                      observers: [_accountObserver],
                      onGenerateRoute: (settings) {
                        return BluePageRoute<void>(
                          settings: settings,
                          builder: (_) => const AccountPage(),
                        );
                      },
                    ),
                  ],
                ),
              ),
              ValueListenableBuilder<bool>(
                valueListenable: shell.hideNav,
                builder: (context, hide, _) {
                  return ClipRect(
                    child: AnimatedAlign(
                      duration: BlueMotion.of(context, BlueMotion.page),
                      curve: hide ? BlueMotion.exitCurve : BlueMotion.curve,
                      alignment: Alignment.topCenter,
                      heightFactor: hide ? 0 : 1,
                      child: IgnorePointer(
                        ignoring: hide,
                        child: ValueListenableBuilder<int>(
                          valueListenable: shell.cartCount,
                          builder: (context, count, _) {
                            return BlueBottomNav(
                              current: _tab,
                              cartCount: count,
                              onSelect: _selectTab,
                            );
                          },
                        ),
                      ),
                    ),
                  );
                },
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Keeps [ShellController.hideNav] aligned with the active tab stack depth.
class _HideNavObserver extends NavigatorObserver {
  _HideNavObserver(this.onStackChanged);

  final VoidCallback onStackChanged;

  void _notify() {
    // canPop() is only accurate after the navigator finishes the mutation.
    WidgetsBinding.instance.addPostFrameCallback((_) => onStackChanged());
  }

  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      _notify();

  @override
  void didPop(Route<dynamic> route, Route<dynamic>? previousRoute) => _notify();

  @override
  void didRemove(Route<dynamic> route, Route<dynamic>? previousRoute) =>
      _notify();

  @override
  void didReplace({Route<dynamic>? newRoute, Route<dynamic>? oldRoute}) =>
      _notify();
}
