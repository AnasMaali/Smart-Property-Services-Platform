import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import 'widgets/otp_verification_view.dart';

/// Verifies `POST /v1/auth/verify-password-reset-otp` (blueprint §2.6) -
/// keyed by phone number + code (never a UUID, unlike registration OTP).
/// Success returns a short-lived `reset_token`, which this screen hands
/// straight to Reset Password rather than storing it anywhere durable.
class VerifyResetOtpScreen extends StatelessWidget {
  const VerifyResetOtpScreen({super.key, required this.phoneNumber});

  final String phoneNumber;

  @override
  Widget build(BuildContext context) {
    return OtpVerificationView(
      title: 'Enter verification code',
      subtitle: 'Enter the 6-digit code we sent to $phoneNumber.',
      onVerify: (code) async {
        await Future<void>.delayed(const Duration(milliseconds: 500));
        if (code == '000000') {
          return 'Invalid or expired verification code.';
        }
        if (context.mounted) {
          context.pushReplacementNamed(
            'resetPassword',
            extra: 'demo-reset-token-$phoneNumber',
          );
        }
        return null;
      },
      onResend: () async {
        await Future<void>.delayed(const Duration(milliseconds: 400));
      },
    );
  }
}

@Preview(name: 'Verify Reset OTP', group: 'Auth', size: Size(390, 844))
Widget verifyResetOtpScreenPreview() {
  return const VerifyResetOtpScreen(phoneNumber: '+971 50 000 1234');
}
