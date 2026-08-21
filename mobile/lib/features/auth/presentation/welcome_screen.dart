import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/components/blue_buttons.dart';
import '../../../core/design/tokens/blue_spacing.dart';
import '../../../core/design/tokens/blue_typography.dart';
import 'widgets/auth_hero.dart';

/// Frontend-only onboarding screen (blueprint §5/§20) preceding
/// Register/Login - no backend call. Its only job is to make the "what is
/// this app for" question answerable in one glance before asking for any
/// personal information.
class WelcomeScreen extends StatelessWidget {
  const WelcomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Column(
        children: [
          const AuthHero(tagline: 'Trusted home services,\nbooked in minutes.'),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(
                BlueSpacing.space24,
                BlueSpacing.space32,
                BlueSpacing.space24,
                BlueSpacing.space24,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'AC, cleaning, pest control, and more - for your home',
                    style: BlueTypography.pageTitle,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: BlueSpacing.space12),
                  Text(
                    'Browse services, book an appointment, and track the work - all from one app.',
                    style: BlueTypography.supporting,
                    textAlign: TextAlign.center,
                  ),
                  const Spacer(),
                  PrimaryButton(
                    label: 'Get started',
                    onPressed: () => context.pushNamed('register'),
                  ),
                  const SizedBox(height: BlueSpacing.space12),
                  TertiaryButton(
                    label: 'I already have an account',
                    onPressed: () => context.pushNamed('login'),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

@Preview(name: 'Welcome', group: 'Auth', size: Size(390, 844))
Widget welcomeScreenPreview() {
  return const WelcomeScreen();
}

@Preview(name: 'Welcome - large phone', group: 'Auth', size: Size(430, 932))
Widget welcomeScreenLargePreview() {
  return const WelcomeScreen();
}
