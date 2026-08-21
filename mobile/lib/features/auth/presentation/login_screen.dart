import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/components/blue_buttons.dart';
import '../../../core/design/components/blue_fields.dart';
import '../../../core/design/components/blue_status.dart';
import '../../../core/design/tokens/blue_spacing.dart';
import '../../../core/design/tokens/blue_typography.dart';

/// Password login (blueprint §2.2/§5). Every failure mode the backend can
/// return collapses to one generic message by design (never branch UI on
/// *why* login failed - that would let an attacker enumerate phone
/// numbers), so this screen only ever shows a single inline banner, never
/// per-field errors.
class LoginScreen extends StatefulWidget {
  const LoginScreen({
    super.key,
    this.debugInitialLoading = false,
    this.debugInitialError = false,
  });

  /// Preview-only state overrides - never read by real navigation.
  final bool debugInitialLoading;
  final bool debugInitialError;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phoneController = TextEditingController();
  final _passwordController = TextEditingController();
  late bool _isLoading = widget.debugInitialLoading;
  late bool _showError = widget.debugInitialError;

  @override
  void dispose() {
    _phoneController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _isLoading = true;
      _showError = false;
    });
    await Future<void>.delayed(const Duration(milliseconds: 700));
    if (!mounted) return;
    setState(() => _isLoading = false);
    context.goNamed('home');
  }

  @override
  Widget build(BuildContext context) {
    final canSubmit =
        _phoneController.text.trim().isNotEmpty &&
        _passwordController.text.isNotEmpty;

    return Scaffold(
      appBar: AppBar(),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.symmetric(
            horizontal: BlueSpacing.pageGutter,
          ),
          children: [
            Text('Log in', style: BlueTypography.pageTitle),
            const SizedBox(height: BlueSpacing.space8),
            Text(
              'Enter your phone number and password to continue.',
              style: BlueTypography.supporting,
            ),
            const SizedBox(height: BlueSpacing.space24),
            if (_showError) ...[
              const BlueBannerPanel(
                tone: BlueTone.error,
                message:
                    'The phone number or password you entered is incorrect.',
              ),
              const SizedBox(height: BlueSpacing.space16),
            ],
            BlueTextField(
              controller: _phoneController,
              label: 'Phone number',
              hint: '+971 5X XXX XXXX',
              keyboardType: TextInputType.phone,
              textInputAction: TextInputAction.next,
              prefixIcon: Icons.call_outlined,
              autofillHints: const [AutofillHints.telephoneNumber],
              onChanged: (_) => setState(() {}),
            ),
            const SizedBox(height: BlueSpacing.space16),
            BlueTextField(
              controller: _passwordController,
              label: 'Password',
              isPassword: true,
              textInputAction: TextInputAction.done,
              autofillHints: const [AutofillHints.password],
              onChanged: (_) => setState(() {}),
              onSubmitted: (_) => canSubmit ? _submit() : null,
            ),
            Align(
              alignment: Alignment.centerRight,
              child: TertiaryButton(
                label: 'Forgot password?',
                onPressed: () => context.pushNamed('forgotPassword'),
              ),
            ),
            const SizedBox(height: BlueSpacing.space16),
            PrimaryButton(
              label: 'Log in',
              isLoading: _isLoading,
              onPressed: canSubmit ? _submit : null,
            ),
            const SizedBox(height: BlueSpacing.space24),
            Center(
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    "Don't have an account?",
                    style: BlueTypography.supporting,
                  ),
                  TertiaryButton(
                    label: 'Register',
                    onPressed: () => context.pushNamed('register'),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

@Preview(name: 'Login - empty', group: 'Auth', size: Size(390, 844))
Widget loginScreenPreview() {
  return const LoginScreen();
}

@Preview(name: 'Login - error', group: 'Auth', size: Size(390, 844))
Widget loginScreenErrorPreview() {
  return const LoginScreen(debugInitialError: true);
}

@Preview(name: 'Login - loading', group: 'Auth', size: Size(390, 844))
Widget loginScreenLoadingPreview() {
  return const LoginScreen(debugInitialLoading: true);
}

@Preview(name: 'Login - compact phone', group: 'Auth', size: Size(360, 780))
Widget loginScreenCompactPreview() {
  return const LoginScreen();
}
