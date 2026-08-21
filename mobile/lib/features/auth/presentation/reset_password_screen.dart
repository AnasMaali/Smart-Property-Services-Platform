import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/components/blue_buttons.dart';
import '../../../core/design/components/blue_feedback.dart';
import '../../../core/design/components/blue_fields.dart';
import '../../../core/design/tokens/blue_spacing.dart';
import '../../../core/design/tokens/blue_typography.dart';

/// `POST /v1/auth/reset-password` (blueprint §2.6) - on success, **every**
/// session for the account is revoked, so the correct next step is Login,
/// never an auto-authenticated Home. [resetToken] is held only in memory
/// for the lifetime of this screen, never persisted.
class ResetPasswordScreen extends StatefulWidget {
  const ResetPasswordScreen({super.key, required this.resetToken});

  final String resetToken;

  @override
  State<ResetPasswordScreen> createState() => _ResetPasswordScreenState();
}

class _ResetPasswordScreenState extends State<ResetPasswordScreen> {
  final _passwordController = TextEditingController();
  final _confirmController = TextEditingController();
  bool _isLoading = false;

  @override
  void dispose() {
    _passwordController.dispose();
    _confirmController.dispose();
    super.dispose();
  }

  bool get _meetsPolicy {
    final value = _passwordController.text;
    return value.length >= 8 &&
        RegExp(r'[A-Za-z]').hasMatch(value) &&
        RegExp(r'[0-9]').hasMatch(value);
  }

  bool get _matches =>
      _confirmController.text.isNotEmpty &&
      _confirmController.text == _passwordController.text;

  Future<void> _submit() async {
    setState(() => _isLoading = true);
    await Future<void>.delayed(const Duration(milliseconds: 700));
    if (!mounted) return;
    setState(() => _isLoading = false);
    context.goNamed('login');
    showBlueSnackBar(
      context,
      message: 'Your password has been reset. Please log in again.',
      tone: BlueSnackBarTone.success,
    );
  }

  @override
  Widget build(BuildContext context) {
    final canSubmit = _meetsPolicy && _matches;

    return Scaffold(
      appBar: AppBar(),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.symmetric(
            horizontal: BlueSpacing.pageGutter,
          ),
          children: [
            Text('Create a new password', style: BlueTypography.pageTitle),
            const SizedBox(height: BlueSpacing.space8),
            Text(
              "You'll need to log in again with your new password.",
              style: BlueTypography.supporting,
            ),
            const SizedBox(height: BlueSpacing.space24),
            BlueTextField(
              controller: _passwordController,
              label: 'New password',
              isPassword: true,
              helperText: 'At least 8 characters, with a letter and a number.',
              onChanged: (_) => setState(() {}),
            ),
            const SizedBox(height: BlueSpacing.space16),
            BlueTextField(
              controller: _confirmController,
              label: 'Confirm new password',
              isPassword: true,
              errorText: _confirmController.text.isNotEmpty && !_matches
                  ? 'Passwords do not match.'
                  : null,
              onChanged: (_) => setState(() {}),
              onSubmitted: (_) => canSubmit ? _submit() : null,
            ),
            const SizedBox(height: BlueSpacing.space24),
            PrimaryButton(
              label: 'Reset password',
              isLoading: _isLoading,
              onPressed: canSubmit ? _submit : null,
            ),
          ],
        ),
      ),
    );
  }
}

@Preview(name: 'Reset Password', group: 'Auth', size: Size(390, 844))
Widget resetPasswordScreenPreview() {
  return const ResetPasswordScreen(resetToken: 'demo-reset-token');
}
