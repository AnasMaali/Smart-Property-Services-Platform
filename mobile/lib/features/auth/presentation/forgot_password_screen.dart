import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/components/blue_buttons.dart';
import '../../../core/design/components/blue_fields.dart';
import '../../../core/design/tokens/blue_spacing.dart';
import '../../../core/design/tokens/blue_typography.dart';

/// Blueprint §2.6: always returns 200 with an identical message whether or
/// not the phone number exists (non-enumerating) - this screen must never
/// imply "phone number not found." It always proceeds to Verify Reset OTP.
class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key, this.debugInitialLoading = false});

  final bool debugInitialLoading;

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _phoneController = TextEditingController();
  late bool _isLoading = widget.debugInitialLoading;

  @override
  void dispose() {
    _phoneController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _isLoading = true);
    await Future<void>.delayed(const Duration(milliseconds: 600));
    if (!mounted) return;
    setState(() => _isLoading = false);
    context.pushNamed('verifyResetOtp', extra: _phoneController.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    final canSubmit = _phoneController.text.trim().isNotEmpty;

    return Scaffold(
      appBar: AppBar(),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.symmetric(
            horizontal: BlueSpacing.pageGutter,
          ),
          children: [
            Text('Forgot password?', style: BlueTypography.pageTitle),
            const SizedBox(height: BlueSpacing.space8),
            Text(
              "Enter the phone number on your account and we'll send a verification code to reset your password.",
              style: BlueTypography.supporting,
            ),
            const SizedBox(height: BlueSpacing.space24),
            BlueTextField(
              controller: _phoneController,
              label: 'Phone number',
              hint: '+971 5X XXX XXXX',
              keyboardType: TextInputType.phone,
              prefixIcon: Icons.call_outlined,
              autofillHints: const [AutofillHints.telephoneNumber],
              onChanged: (_) => setState(() {}),
              onSubmitted: (_) => canSubmit ? _submit() : null,
            ),
            const SizedBox(height: BlueSpacing.space24),
            PrimaryButton(
              label: 'Send code',
              isLoading: _isLoading,
              onPressed: canSubmit ? _submit : null,
            ),
          ],
        ),
      ),
    );
  }
}

@Preview(name: 'Forgot Password', group: 'Auth', size: Size(390, 844))
Widget forgotPasswordScreenPreview() {
  return const ForgotPasswordScreen();
}
