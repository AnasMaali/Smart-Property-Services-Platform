import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

/// F1 foundation screens only. Each one exists solely to prove the router
/// boots and can navigate between destinations - none of this is final UI,
/// none of it calls the backend, and none of it should be extended in
/// place. A later phase (F3 for auth, F4 for home) replaces these routes
/// entirely rather than building on top of them.
class _PlaceholderScaffold extends StatelessWidget {
  const _PlaceholderScaffold({required this.title, required this.body});

  final String title;
  final Widget body;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'F1 placeholder - not final UI',
                style: Theme.of(context).textTheme.labelLarge,
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              body,
            ],
          ),
        ),
      ),
    );
  }
}

class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _PlaceholderScaffold(
      title: 'BLUE',
      body: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const CircularProgressIndicator(),
          const SizedBox(height: 24),
          FilledButton(
            onPressed: () => context.goNamed('loginPlaceholder'),
            child: const Text('Continue to login placeholder'),
          ),
        ],
      ),
    );
  }
}

class LoginPlaceholderScreen extends StatelessWidget {
  const LoginPlaceholderScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _PlaceholderScaffold(
      title: 'Login (placeholder)',
      body: FilledButton(
        onPressed: () => context.goNamed('homePlaceholder'),
        child: const Text('Continue to home placeholder'),
      ),
    );
  }
}

class HomePlaceholderScreen extends StatelessWidget {
  const HomePlaceholderScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return _PlaceholderScaffold(
      title: 'Home (placeholder)',
      body: FilledButton(
        onPressed: () => context.goNamed('splash'),
        child: const Text('Back to splash'),
      ),
    );
  }
}
