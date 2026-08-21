import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/tokens/blue_colors.dart';
import 'widgets/auth_hero.dart';

/// The app-launch decision screen (blueprint §4): briefly shown while the
/// app establishes whether a stored session is still valid, then routes
/// to either the Auth stack or Main app. This phase has no real session
/// store wired in yet, so it demonstrates the "no session" branch only -
/// the visual/navigation shape a real `AuthNotifier` will drive later.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _timer = Timer(const Duration(milliseconds: 900), () {
      if (mounted) context.goNamed('welcome');
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: BlueColors.brandPrimaryDark,
      body: Stack(
        children: [
          const Positioned.fill(child: AuthHero(height: double.infinity)),
          Positioned(
            left: 0,
            right: 0,
            bottom: 64,
            child: Center(
              child: SizedBox(
                width: 28,
                height: 28,
                child: CircularProgressIndicator(
                  strokeWidth: 2.6,
                  valueColor: AlwaysStoppedAnimation(
                    BlueColors.textInverseSecondary,
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

@Preview(name: 'Splash', group: 'Auth', size: Size(390, 844))
Widget splashScreenPreview() {
  return const SplashScreen();
}
