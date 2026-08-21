import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/components/blue_feedback.dart';
import 'widgets/otp_verification_view.dart';

/// Verifies the OTP issued by `POST /v1/auth/register` (blueprint §2.1).
/// Success activates the account but does **not** return tokens - the
/// customer is routed to Login with a confirmation toast, matching the
/// backend's actual contract rather than assuming an auto-login.
class VerifyPhoneOtpScreen extends StatelessWidget {
  const VerifyPhoneOtpScreen({super.key, required this.phoneNumber});

  final String phoneNumber;

  @override
  Widget build(BuildContext context) {
    return OtpVerificationView(
      title: 'Verify your phone',
      subtitle: 'Enter the 6-digit code we sent to $phoneNumber.',
      onVerify: (code) async {
        await Future<void>.delayed(const Duration(milliseconds: 500));
        if (code == '000000') {
          return 'The verification code you entered is incorrect.';
        }
        if (context.mounted) {
          context.goNamed('login');
          showBlueSnackBar(
            context,
            message: 'Phone verified - you can now log in.',
            tone: BlueSnackBarTone.success,
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

@Preview(name: 'Verify Phone OTP', group: 'Auth', size: Size(390, 844))
Widget verifyPhoneOtpScreenPreview() {
  return const VerifyPhoneOtpScreen(phoneNumber: '+971 50 000 1234');
}
