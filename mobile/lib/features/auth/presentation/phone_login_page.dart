import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import 'widgets/blue_brand.dart';
import 'widgets/blue_country_sheet.dart';
import 'widgets/blue_motion.dart';
import 'widgets/blue_phone_field.dart';
import 'widgets/blue_primary_button.dart';
import 'widgets/blue_text_link.dart';
import 'widgets/error_hint.dart';
import 'widgets/lock_note.dart';

class PhoneLoginPage extends StatefulWidget {
  const PhoneLoginPage({
    super.key,
    required this.onCreateAccount,
    required this.onCodeSent,
    this.initialPhoneDigits,
    this.notice,
  });

  final VoidCallback onCreateAccount;
  final void Function(String phoneDigits, String phoneE164) onCodeSent;
  final String? initialPhoneDigits;
  final String? notice;

  @override
  State<PhoneLoginPage> createState() => _PhoneLoginPageState();
}

class _PhoneLoginPageState extends State<PhoneLoginPage> {
  late final TextEditingController _phone;
  final _phoneFocus = FocusNode();
  BlueCountry _country = BlueCountry.uae;
  bool _busy = false;
  bool _phoneErr = false;
  String? _formErr;

  @override
  void initState() {
    super.initState();
    _phone = TextEditingController(
      text: UaePhone.format(widget.initialPhoneDigits ?? ''),
    );
  }

  @override
  void didUpdateWidget(covariant PhoneLoginPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    final next = widget.initialPhoneDigits;
    if (next != null && next != oldWidget.initialPhoneDigits) {
      _phone.text = UaePhone.format(next);
    }
  }

  @override
  void dispose() {
    _phone.dispose();
    _phoneFocus.dispose();
    super.dispose();
  }

  void _onPhoneChanged(String value) {
    final formatted = UaePhone.format(value);
    if (formatted != value) {
      _phone.value = TextEditingValue(
        text: formatted,
        selection: TextSelection.collapsed(offset: formatted.length),
      );
    }
    setState(() {
      _phoneErr = false;
      _formErr = null;
    });
  }

  Future<void> _submit() async {
    if (_busy) return;
    FocusScope.of(context).unfocus();
    final phoneErr = UaePhone.digits(_phone.text).length < 9;
    if (phoneErr) {
      setState(() {
        _phoneErr = true;
        _formErr = null;
      });
      return;
    }
    setState(() {
      _phoneErr = false;
      _formErr = null;
      _busy = true;
    });
    final digits = UaePhone.digits(_phone.text);
    final e164 = UaePhone.e164(_phone.text, dial: _country.dial);
    try {
      await AppScope.of(context).auth.requestLoginOtp(phoneNumber: e164);
      if (!mounted) return;
      setState(() => _busy = false);
      widget.onCodeSent(digits, e164);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _formErr = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _formErr = 'Something went wrong. Please try again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        child: LayoutBuilder(
          builder: (context, constraints) {
            return SingleChildScrollView(
              child: ConstrainedBox(
                constraints: BoxConstraints(minHeight: constraints.maxHeight),
                child: IntrinsicHeight(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(
                      BlueDimens.screenGutter,
                      BlueDimens.contentTop,
                      BlueDimens.screenGutter,
                      BlueDimens.contentBottom,
                    ),
                    child: Column(
                      children: [
                        Column(
                          children: [
                            const BlueEnter(child: BlueBrand()),
                            const SizedBox(height: 34),
                            const BlueEnter(
                              delay: Duration(milliseconds: 60),
                              child: Text(
                                'Welcome back',
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 34,
                                  height: 1.12,
                                  fontWeight: FontWeight.w700,
                                  letterSpacing: 34 * -0.025,
                                  color: BlueColors.ink,
                                ),
                              ),
                            ),
                            const SizedBox(height: 11),
                            const BlueEnter(
                              delay: Duration(milliseconds: 110),
                              child: SizedBox(
                                width: 288,
                                child: Text(
                                  "Enter your phone number and we'll send a 6-digit sign-in code.",
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    fontFamily: BlueFonts.jakarta,
                                    fontSize: 15,
                                    height: 1.5,
                                    fontWeight: FontWeight.w400,
                                    color: BlueColors.muted,
                                  ),
                                ),
                              ),
                            ),
                            const SizedBox(height: 36),
                            BlueEnter(
                              delay: const Duration(milliseconds: 160),
                              child: Column(
                                children: [
                                  if (widget.notice != null) ...[
                                    Align(
                                      alignment: Alignment.centerLeft,
                                      child: Text(
                                        widget.notice!,
                                        style: const TextStyle(
                                          fontFamily: BlueFonts.jakarta,
                                          fontSize: 13,
                                          height: 1.4,
                                          fontWeight: FontWeight.w500,
                                          color: BlueColors.ink,
                                        ),
                                      ),
                                    ),
                                    const SizedBox(height: 16),
                                  ],
                                  const Align(
                                    alignment: Alignment.centerLeft,
                                    child: Text(
                                      'Phone number',
                                      style: TextStyle(
                                        fontFamily: BlueFonts.jakarta,
                                        fontSize: 13,
                                        fontWeight: FontWeight.w600,
                                        letterSpacing: 13 * 0.005,
                                        color: BlueColors.muted,
                                      ),
                                    ),
                                  ),
                                  const SizedBox(height: 8),
                                  BluePhoneField(
                                    controller: _phone,
                                    focusNode: _phoneFocus,
                                    error: _phoneErr,
                                    enabled: !_busy,
                                    onChanged: _onPhoneChanged,
                                    onCountryChanged: (country) {
                                      setState(() => _country = country);
                                    },
                                    onSubmitted: (_) => _submit(),
                                  ),
                                  AnimatedSize(
                                    duration: BlueMotion.snap,
                                    curve: BlueMotion.curve,
                                    alignment: Alignment.topCenter,
                                    child: _phoneErr
                                        ? const Padding(
                                            padding: EdgeInsets.only(top: 9),
                                            child: BlueErrorHint(
                                              message:
                                                  'Enter your 9-digit UAE mobile number, for example 50 123 4567.',
                                            ),
                                          )
                                        : const SizedBox.shrink(),
                                  ),
                                  AnimatedSize(
                                    duration: BlueMotion.snap,
                                    curve: BlueMotion.curve,
                                    alignment: Alignment.topCenter,
                                    child: _formErr != null
                                        ? Padding(
                                            padding: const EdgeInsets.only(
                                              top: 9,
                                            ),
                                            child: BlueErrorHint(
                                              message: _formErr!,
                                            ),
                                          )
                                        : const SizedBox.shrink(),
                                  ),
                                ],
                              ),
                            ),
                            AnimatedSize(
                              duration: BlueMotion.snap,
                              curve: BlueMotion.curve,
                              child: SizedBox(
                                height: (_phoneErr || _formErr != null)
                                    ? 20
                                    : 28,
                              ),
                            ),
                            BlueEnter(
                              delay: const Duration(milliseconds: 210),
                              child: BluePrimaryButton(
                                label: _busy ? 'Sending code…' : 'Continue',
                                busy: _busy,
                                enabled: true,
                                onPressed: _submit,
                              ),
                            ),
                            const SizedBox(height: 16),
                            const BlueEnter(
                              delay: Duration(milliseconds: 250),
                              child: Padding(
                                padding: EdgeInsets.symmetric(horizontal: 2),
                                child: LockNote(
                                  text:
                                      'Codes expire after 5 minutes. Never share it with anyone.',
                                ),
                              ),
                            ),
                          ],
                        ),
                        const Expanded(child: SizedBox(height: 24)),
                        const BlueEnter(
                          delay: Duration(milliseconds: 300),
                          child: ColoredBox(
                            color: BlueColors.border,
                            child: SizedBox(height: 1, width: double.infinity),
                          ),
                        ),
                        const SizedBox(height: 12),
                        BlueEnter(
                          delay: const Duration(milliseconds: 320),
                          child: Wrap(
                            alignment: WrapAlignment.center,
                            crossAxisAlignment: WrapCrossAlignment.center,
                            spacing: 7,
                            children: [
                              const Text(
                                "Don't have an account?",
                                style: TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 14,
                                  fontWeight: FontWeight.w400,
                                  color: BlueColors.muted,
                                ),
                              ),
                              BlueTextLink(
                                label: 'Create Account',
                                onPressed: widget.onCreateAccount,
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}
