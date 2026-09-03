import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/session/auth_session.dart';
import '../features/auth/presentation/auth_flow_page.dart';
import '../features/auth/presentation/splash_screen.dart';
import '../features/auth/presentation/widgets/blue_motion.dart';
import '../features/shell/presentation/main_shell.dart';
import 'app_scope.dart';
import 'theme/blue_theme.dart';

class BlueApp extends StatefulWidget {
  const BlueApp({super.key, required this.dependencies});

  final AppDependencies dependencies;

  @override
  State<BlueApp> createState() => _BlueAppState();
}

class _BlueAppState extends State<BlueApp> {
  bool _showSplash = true;

  void _finishSplash() {
    if (!_showSplash || !mounted) return;
    setState(() => _showSplash = false);
  }

  @override
  Widget build(BuildContext context) {
    return AppScope(
      dependencies: widget.dependencies,
      child: ValueListenableBuilder<AuthSession?>(
        valueListenable: widget.dependencies.auth.listenable,
        builder: (context, session, _) {
          return MaterialApp(
            title: 'blue',
            debugShowCheckedModeBanner: false,
            theme: buildBlueTheme(),
            themeAnimationDuration: const Duration(milliseconds: 380),
            themeAnimationCurve: const Cubic(0.22, 0.61, 0.36, 1),
            builder: (context, child) {
              return GestureDetector(
                onTap: () => FocusManager.instance.primaryFocus?.unfocus(),
                behavior: HitTestBehavior.translucent,
                child: AnnotatedRegion<SystemUiOverlayStyle>(
                  value: SystemUiOverlayStyle(
                    statusBarColor: Colors.transparent,
                    statusBarIconBrightness: Brightness.dark,
                    statusBarBrightness: Brightness.light,
                    systemNavigationBarColor: _showSplash
                        ? Colors.white
                        : BlueColors.canvas,
                    systemNavigationBarIconBrightness: Brightness.dark,
                  ),
                  child: child ?? const SizedBox.shrink(),
                ),
              );
            },
            home: BlueFadeSwitch(
              duration: BlueMotion.enter,
              offset: const Offset(0, 0.06),
              child: _showSplash
                  ? SplashScreen(
                      key: const ValueKey('splash'),
                      onFinished: _finishSplash,
                    )
                  : session == null
                      ? const AuthFlowPage(key: ValueKey('auth'))
                      : const MainShell(key: ValueKey('app')),
            ),
          );
        },
      ),
    );
  }
}
