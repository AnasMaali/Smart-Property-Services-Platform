import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../app/coming_soon_screen.dart';
import '../../app/main_shell.dart';
import '../../features/auth/presentation/forgot_password_screen.dart';
import '../../features/auth/presentation/login_screen.dart';
import '../../features/auth/presentation/register_screen.dart';
import '../../features/auth/presentation/reset_password_screen.dart';
import '../../features/auth/presentation/splash_screen.dart';
import '../../features/auth/presentation/verify_phone_otp_screen.dart';
import '../../features/auth/presentation/verify_reset_otp_screen.dart';
import '../../features/auth/presentation/welcome_screen.dart';
import '../../features/catalog/presentation/category_services_screen.dart';
import '../../features/catalog/presentation/service_detail_screen.dart';
import '../../features/home/presentation/home_screen.dart';

/// Route names/paths for the app's navigation map (blueprint §20). Named
/// routes throughout (`context.pushNamed`/`goNamed`) rather than raw
/// paths, so the path strings below are the only place a URL ever needs
/// to be typed.
class AppRoutes {
  AppRoutes._();

  static const splash = '/splash';
  static const welcome = '/welcome';
  static const register = '/register';
  static const verifyPhoneOtp = '/verify-phone-otp';
  static const login = '/login';
  static const forgotPassword = '/forgot-password';
  static const verifyResetOtp = '/verify-reset-otp';
  static const resetPassword = '/reset-password';

  static const home = '/home';
  static const categoryServices = '/home/category/:categoryId';
  static const serviceDetail = '/home/service/:slug';
  static const cart = '/cart';
  static const bookings = '/bookings';
  static const contracts = '/contracts';
  static const account = '/account';
}

final _rootNavigatorKey = GlobalKey<NavigatorState>();

GoRouter buildAppRouter() {
  return GoRouter(
    navigatorKey: _rootNavigatorKey,
    initialLocation: AppRoutes.splash,
    routes: [
      GoRoute(
        path: AppRoutes.splash,
        name: 'splash',
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: AppRoutes.welcome,
        name: 'welcome',
        builder: (context, state) => const WelcomeScreen(),
      ),
      GoRoute(
        path: AppRoutes.register,
        name: 'register',
        builder: (context, state) => const RegisterScreen(),
      ),
      GoRoute(
        path: AppRoutes.verifyPhoneOtp,
        name: 'verifyPhoneOtp',
        builder: (context, state) =>
            VerifyPhoneOtpScreen(phoneNumber: (state.extra as String?) ?? ''),
      ),
      GoRoute(
        path: AppRoutes.login,
        name: 'login',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: AppRoutes.forgotPassword,
        name: 'forgotPassword',
        builder: (context, state) => const ForgotPasswordScreen(),
      ),
      GoRoute(
        path: AppRoutes.verifyResetOtp,
        name: 'verifyResetOtp',
        builder: (context, state) =>
            VerifyResetOtpScreen(phoneNumber: (state.extra as String?) ?? ''),
      ),
      GoRoute(
        path: AppRoutes.resetPassword,
        name: 'resetPassword',
        builder: (context, state) =>
            ResetPasswordScreen(resetToken: (state.extra as String?) ?? ''),
      ),
      StatefulShellRoute.indexedStack(
        builder: (context, state, navigationShell) =>
            MainShell(navigationShell: navigationShell, cartItemCount: 2),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: AppRoutes.home,
                name: 'home',
                builder: (context, state) => const HomeScreen(),
                routes: [
                  GoRoute(
                    path: 'category/:categoryId',
                    name: 'categoryServices',
                    builder: (context, state) => CategoryServicesScreen(
                      categoryId:
                          int.tryParse(
                            state.pathParameters['categoryId'] ?? '',
                          ) ??
                          -1,
                    ),
                  ),
                  GoRoute(
                    path: 'service/:slug',
                    name: 'serviceDetail',
                    builder: (context, state) => ServiceDetailScreen(
                      slug: state.pathParameters['slug'] ?? '',
                    ),
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: AppRoutes.cart,
                name: 'cart',
                builder: (context, state) => const ComingSoonScreen(
                  title: 'Cart',
                  icon: Icons.shopping_bag_outlined,
                  message: 'Cart, checkout, and payment are next up.',
                ),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: AppRoutes.bookings,
                name: 'bookings',
                builder: (context, state) => const ComingSoonScreen(
                  title: 'Bookings',
                  icon: Icons.event_note_outlined,
                  message: 'Your bookings will appear here once this section is built.',
                ),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: AppRoutes.contracts,
                name: 'contracts',
                builder: (context, state) => const ComingSoonScreen(
                  title: 'Contracts',
                  icon: Icons.verified_user_outlined,
                  message: 'Service contracts will appear here once this section is built.',
                ),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: AppRoutes.account,
                name: 'account',
                builder: (context, state) => const ComingSoonScreen(
                  title: 'Account',
                  icon: Icons.person_outline_rounded,
                  message: 'Profile, properties, and settings will appear here once this section is built.',
                ),
              ),
            ],
          ),
        ],
      ),
    ],
  );
}
