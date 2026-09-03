import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import 'otp_verify_page.dart';
import 'phone_login_page.dart';
import 'signup_flow_page.dart';
import 'widgets/blue_motion.dart';

class AuthFlowPage extends StatefulWidget {
  const AuthFlowPage({super.key});

  @override
  State<AuthFlowPage> createState() => _AuthFlowPageState();
}

class _AuthFlowPageState extends State<AuthFlowPage> {
  bool _signup = false;
  bool _signupMounted = false;
  bool _loginOtp = false;
  String _loginPhoneDigits = '';
  String _loginPhoneE164 = '';
  String? _loginNotice;

  void _afterFrame(VoidCallback fn) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) fn();
    });
  }

  void _openSignup() {
    setState(() {
      _signupMounted = true;
      _loginOtp = false;
      _loginNotice = null;
    });
    _afterFrame(() => setState(() => _signup = true));
  }

  void _closeSignup() {
    setState(() => _signup = false);
  }

  void _onSignupVerified(String phoneDigits, String phoneE164) {
    setState(() {
      _signup = false;
      _loginOtp = false;
      _loginPhoneDigits = phoneDigits;
      _loginPhoneE164 = phoneE164;
      _loginNotice = 'Phone verified. Sign in with the code we will send you.';
    });
  }

  void _onCodeSent(String phoneDigits, String phoneE164) {
    setState(() {
      _loginPhoneDigits = phoneDigits;
      _loginPhoneE164 = phoneE164;
      _loginNotice = null;
      _loginOtp = true;
    });
  }

  Future<void> _verifyLogin(String code) async {
    await AppScope.of(
      context,
    ).auth.verifyLoginOtp(phoneNumber: _loginPhoneE164, otpCode: code);
  }

  Future<void> _resendLogin() async {
    await AppScope.of(
      context,
    ).auth.resendLoginOtp(phoneNumber: _loginPhoneE164);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: BlueColors.canvas,
      body: Stack(
        children: [
          BluePageLayer(
            visible: !_signup && !_loginOtp,
            departLeft: true,
            child: PhoneLoginPage(
              initialPhoneDigits: _loginPhoneDigits,
              notice: _loginNotice,
              onCreateAccount: _openSignup,
              onCodeSent: _onCodeSent,
            ),
          ),
          BluePageLayer(
            visible: _loginOtp && !_signup,
            departLeft: false,
            child: _loginOtp
                ? OtpVerifyPage(
                    key: ValueKey(_loginPhoneE164),
                    phoneDigits: _loginPhoneDigits,
                    headline: 'Enter your code',
                    onBack: () => setState(() => _loginOtp = false),
                    onVerify: _verifyLogin,
                    onResend: _resendLogin,
                  )
                : const SizedBox.expand(),
          ),
          BluePageLayer(
            visible: _signup,
            departLeft: false,
            child: _signupMounted
                ? SignupFlowPage(
                    onClose: _closeSignup,
                    onVerified: _onSignupVerified,
                  )
                : const SizedBox.expand(),
          ),
        ],
      ),
    );
  }
}
