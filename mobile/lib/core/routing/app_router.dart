import 'package:go_router/go_router.dart';

import '../../app/placeholder_screens.dart';

/// F1 foundation only: three placeholder routes that prove the router
/// boots and can navigate (blueprint §13/§20 target navigation map, not yet
/// built). Deliberately named with a `-placeholder` suffix for
/// `login`/`home` so nobody mistakes them for the real screens a later
/// phase builds - the real `/login`/`/home` routes are expected to replace
/// these, not extend them.
///
/// No `redirect` callback yet: session-based route guarding needs
/// `features/auth` and a populated [sessionStatusProvider], neither of
/// which exists in F1. Keeping the route table in its own function (rather
/// than inlined into `MaterialApp.router`) is what makes adding `redirect`
/// later a localized change instead of a restructure.
class AppRoutes {
  static const splash = '/splash';
  static const loginPlaceholder = '/login-placeholder';
  static const homePlaceholder = '/home-placeholder';
}

GoRouter buildAppRouter() {
  return GoRouter(
    initialLocation: AppRoutes.splash,
    routes: [
      GoRoute(
        path: AppRoutes.splash,
        name: 'splash',
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: AppRoutes.loginPlaceholder,
        name: 'loginPlaceholder',
        builder: (context, state) => const LoginPlaceholderScreen(),
      ),
      GoRoute(
        path: AppRoutes.homePlaceholder,
        name: 'homePlaceholder',
        builder: (context, state) => const HomePlaceholderScreen(),
      ),
    ],
  );
}
