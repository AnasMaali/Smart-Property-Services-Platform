import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/routing/app_router.dart';
import '../core/theme/app_theme.dart';

/// The single [GoRouter] instance for the app's lifetime, built once here
/// rather than inline in [BlueApp] so it can be overridden in widget tests.
final appRouterProvider = Provider<GoRouter>((ref) => buildAppRouter());

/// The app shell: theme + router wiring only. All business logic lives
/// behind `core/`/`features/` providers - this widget stays deliberately
/// small.
class BlueApp extends ConsumerWidget {
  const BlueApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(appRouterProvider);

    return MaterialApp.router(
      title: 'BLUE',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light,
      routerConfig: router,
    );
  }
}
