import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import 'otp_verify_page.dart';
import 'signup_details_page.dart';
import 'signup_property_page.dart';
import 'widgets/blue_motion.dart';

class SignupFlowPage extends StatefulWidget {
  const SignupFlowPage({
    super.key,
    required this.onClose,
    required this.onVerified,
  });

  final VoidCallback onClose;
  final void Function(String phoneDigits, String phoneE164) onVerified;

  @override
  State<SignupFlowPage> createState() => _SignupFlowPageState();
}

class _SignupFlowPageState extends State<SignupFlowPage> {
  int _step = 1;
  int _seen = 1;
  SignupDetails? _details;
  bool _registering = false;
  String? _registerError;
  String _otpUuid = '';

  void _afterFrame(VoidCallback fn) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) fn();
    });
  }

  void _go(int step) {
    setState(() {
      _seen = _seen < step ? step : _seen;
    });
    _afterFrame(() => setState(() => _step = step));
  }

  void _toStep2(SignupDetails details) {
    setState(() => _details = details);
    _go(2);
  }

  Future<void> _register(SignupProperty property) async {
    final details = _details;
    if (details == null || _registering) return;
    setState(() {
      _registering = true;
      _registerError = null;
    });
    try {
      final result = await AppScope.of(context).auth.register(
        fullName: details.name,
        phoneNumber: details.phoneE164,
        email: details.email,
        cityId: property.cityId,
        areaId: property.areaId,
        propertyRelationshipTypeId: property.relationId,
        serviceInterests: property.interestIds,
      );
      if (!mounted) return;
      setState(() {
        _registering = false;
        _otpUuid = result.otpVerificationUuid;
      });
      _go(3);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _registering = false;
        _registerError = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _registering = false;
        _registerError = 'Something went wrong. Please try again.';
      });
    }
  }

  Future<void> _verify(String code) async {
    await AppScope.of(
      context,
    ).auth.verifyPhone(otpVerificationUuid: _otpUuid, otpCode: code);
    final details = _details;
    if (details == null || !mounted) return;
    widget.onVerified(details.phone, details.phoneE164);
  }

  Future<void> _resend() async {
    final result = await AppScope.of(
      context,
    ).auth.resendOtp(otpVerificationUuid: _otpUuid);
    if (!mounted) return;
    setState(() => _otpUuid = result.otpVerificationUuid);
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_step == 3) {
          setState(() => _step = 2);
        } else if (_step == 2) {
          setState(() => _step = 1);
        } else {
          widget.onClose();
        }
      },
      child: ColoredBox(
        color: BlueColors.canvas,
        child: Stack(
          children: [
            BluePageLayer(
              visible: _step == 1,
              departLeft: _step > 1,
              child: SignupDetailsPage(
                onContinue: _toStep2,
                onBack: widget.onClose,
                onLogin: widget.onClose,
              ),
            ),
            BluePageLayer(
              visible: _step == 2,
              departLeft: _step > 2,
              child: _seen >= 2
                  ? SignupPropertyPage(
                      submitting: _registering,
                      submitError: _registerError,
                      onContinue: _register,
                      onBack: () => setState(() => _step = 1),
                    )
                  : const SizedBox.expand(),
            ),
            BluePageLayer(
              visible: _step == 3,
              departLeft: false,
              child: _seen >= 3
                  ? OtpVerifyPage(
                      key: ValueKey('${_details?.phone}-$_otpUuid'),
                      phoneDigits: _details?.phone ?? '',
                      stepLabel: 'Step 3 of 3',
                      stepTitle: 'Verify the number',
                      progressStep: 3,
                      progressTotal: 3,
                      compactBrand: true,
                      onBack: () => setState(() => _step = 2),
                      onVerify: _verify,
                      onResend: _resend,
                    )
                  : const SizedBox.expand(),
            ),
          ],
        ),
      ),
    );
  }
}
