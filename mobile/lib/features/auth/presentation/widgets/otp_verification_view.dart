import 'dart:async';

import 'package:flutter/material.dart';

import '../../../../core/design/components/blue_buttons.dart';
import '../../../../core/design/components/blue_fields.dart';
import '../../../../core/design/components/blue_status.dart';
import '../../../../core/design/tokens/blue_colors.dart';
import '../../../../core/design/tokens/blue_spacing.dart';
import '../../../../core/design/tokens/blue_typography.dart';

/// Shared shape for every OTP screen in the app (registration phone
/// verification, password-reset verification, phone-number-change
/// verification - blueprint §2.1/§2.6/§2.8 all follow the identical
/// 6-digit/5-minute/60-second-cooldown policy). Each concrete screen wires
/// its own [onVerify]/[onResend] against the right endpoint pair; this
/// view owns only the shared UI: code entry, countdown, resend, error
/// display.
class OtpVerificationView extends StatefulWidget {
  const OtpVerificationView({
    super.key,
    required this.title,
    required this.subtitle,
    required this.onVerify,
    required this.onResend,
    this.resendCooldownSeconds = 60,
    this.debugInitialError,
  });

  final String title;
  final String subtitle;

  /// Returns `null` on success, or a customer-facing error message on
  /// failure (e.g. "verification code you entered is incorrect").
  final Future<String?> Function(String code) onVerify;

  /// Returns the new cooldown to restart the countdown with, mirroring
  /// the backend's fresh `resend_available_at`.
  final Future<void> Function() onResend;
  final int resendCooldownSeconds;

  final String? debugInitialError;

  @override
  State<OtpVerificationView> createState() => _OtpVerificationViewState();
}

class _OtpVerificationViewState extends State<OtpVerificationView> {
  Timer? _timer;
  late int _secondsRemaining = widget.resendCooldownSeconds;
  bool _isVerifying = false;
  bool _isResending = false;
  late String? _error = widget.debugInitialError;
  int _otpFieldResetKey = 0;

  @override
  void initState() {
    super.initState();
    _startCountdown();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  void _startCountdown() {
    _timer?.cancel();
    setState(() => _secondsRemaining = widget.resendCooldownSeconds);
    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) return;
      if (_secondsRemaining <= 1) {
        timer.cancel();
        setState(() => _secondsRemaining = 0);
      } else {
        setState(() => _secondsRemaining -= 1);
      }
    });
  }

  Future<void> _handleCompleted(String code) async {
    setState(() {
      _isVerifying = true;
      _error = null;
    });
    final error = await widget.onVerify(code);
    if (!mounted) return;
    setState(() {
      _isVerifying = false;
      _error = error;
      if (error != null) _otpFieldResetKey++;
    });
  }

  Future<void> _handleResend() async {
    setState(() => _isResending = true);
    await widget.onResend();
    if (!mounted) return;
    setState(() => _isResending = false);
    _startCountdown();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.symmetric(
            horizontal: BlueSpacing.pageGutter,
          ),
          children: [
            Text(widget.title, style: BlueTypography.pageTitle),
            const SizedBox(height: BlueSpacing.space8),
            Text(widget.subtitle, style: BlueTypography.supporting),
            const SizedBox(height: BlueSpacing.space24),
            if (_error != null) ...[
              BlueBannerPanel(tone: BlueTone.error, message: _error!),
              const SizedBox(height: BlueSpacing.space16),
            ],
            OtpCodeField(
              key: ValueKey(_otpFieldResetKey),
              hasError: _error != null,
              onCompleted: _handleCompleted,
              onChanged: (_) {
                if (_error != null) setState(() => _error = null);
              },
            ),
            const SizedBox(height: BlueSpacing.space24),
            if (_isVerifying)
              const Center(
                child: SizedBox(
                  width: 24,
                  height: 24,
                  child: CircularProgressIndicator(strokeWidth: 2.4),
                ),
              )
            else
              Center(
                child: _secondsRemaining > 0
                    ? Text(
                        'Resend code in 0:${_secondsRemaining.toString().padLeft(2, '0')}',
                        style: BlueTypography.supporting,
                      )
                    : TertiaryButton(
                        label: _isResending ? 'Sending...' : 'Resend code',
                        onPressed: _isResending ? null : _handleResend,
                      ),
              ),
            const SizedBox(height: BlueSpacing.space16),
            Center(
              child: Text(
                'Code expires 5 minutes after it was sent.',
                style: BlueTypography.caption.copyWith(
                  color: BlueColors.textTertiary,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
