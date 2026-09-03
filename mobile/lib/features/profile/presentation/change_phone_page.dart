import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_country_sheet.dart';
import '../../auth/presentation/widgets/blue_field_label.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../auth/presentation/widgets/blue_phone_field.dart';
import '../../auth/presentation/widgets/error_hint.dart';
import 'verify_phone_otp_page.dart';
import 'widgets/change_phone_widgets.dart';

class ChangePhonePage extends StatefulWidget {
  const ChangePhonePage({super.key});

  @override
  State<ChangePhonePage> createState() => _ChangePhonePageState();
}

class _ChangePhonePageState extends State<ChangePhonePage> {
  final _phone = TextEditingController();
  final _phoneFocus = FocusNode();
  BlueCountry _country = BlueCountry.uae;
  bool _busy = false;
  bool _phoneErr = false;
  String? _formErr;

  @override
  void initState() {
    super.initState();
    _phoneFocus.addListener(_onFocus);
  }

  @override
  void dispose() {
    _phoneFocus.removeListener(_onFocus);
    _phone.dispose();
    _phoneFocus.dispose();
    super.dispose();
  }

  void _onFocus() {
    if (!mounted || _phoneFocus.hasFocus) return;
    final digits = UaePhone.digits(_phone.text);
    final bad = digits.isNotEmpty && digits.length < 9;
    if (bad == _phoneErr && (bad || _formErr == null)) return;
    setState(() {
      _phoneErr = bad;
      if (bad) _formErr = null;
    });
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

  bool get _complete => UaePhone.digits(_phone.text).length >= 9;

  Future<void> _submit() async {
    if (_busy) return;
    FocusScope.of(context).unfocus();
    if (!_complete) {
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
      final requested = await AppScope.of(
        context,
      ).auth.requestPhoneNumberChange(newPhoneNumber: e164);
      if (!mounted) return;
      setState(() => _busy = false);
      await _openOtp(
        digits: digits,
        otpUuid: requested.otpVerificationUuid,
        resendAvailableAt: requested.resendAvailableAt,
      );
    } on ApiException catch (error) {
      if (!mounted) return;
      BlueMotion.warn();
      setState(() {
        _busy = false;
        _formErr = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      BlueMotion.warn();
      setState(() {
        _busy = false;
        _formErr = 'Something went wrong. Please try again.';
      });
    }
  }

  Future<void> _openOtp({
    required String digits,
    required String otpUuid,
    DateTime? resendAvailableAt,
  }) async {
    var uuid = otpUuid;
    final changed = await Navigator.of(context).push<bool>(
      BluePageRoute(
        builder: (_) => VerifyPhoneOtpPage(
          displayPhone: '${_country.dial} ${UaePhone.format(digits)}',
          resendAvailableAt: resendAvailableAt,
          onVerify: (code) async {
            final result = await AppScope.of(context).auth
                .verifyPhoneNumberChangeOtp(
                  otpVerificationUuid: uuid,
                  otpCode: code,
                );
            if (!mounted) return;
            await AppScope.of(
              context,
            ).auth.syncIdentity(phoneNumber: result.phoneNumber);
            if (!mounted) return;
            final cached = AppScope.of(context).profile.cached;
            if (cached != null) {
              AppScope.of(
                context,
              ).profile.apply(cached.copyWith(phoneNumber: result.phoneNumber));
            }
          },
          onResend: () async {
            final result = await AppScope.of(
              context,
            ).auth.resendPhoneNumberChangeOtp(otpVerificationUuid: uuid);
            uuid = result.otpVerificationUuid;
          },
        ),
      ),
    );
    if (changed == true && mounted) {
      Navigator.of(context).pop(true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final gutter = MediaQuery.sizeOf(context).width < 359 ? 18.0 : 24.0;
    final hasErr = _phoneErr || _formErr != null;
    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        bottom: false,
        child: BlueEnter(
          duration: BlueMotion.rise,
          offset: const Offset(0, 0.018),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: EdgeInsets.fromLTRB(gutter, 2, gutter, 0),
                child: Align(
                  alignment: Alignment.centerLeft,
                  child: ChangePhoneBackButton(
                    onPressed: () => Navigator.of(context).pop(),
                  ),
                ),
              ),
              Expanded(
                child: ListView(
                  physics: const BouncingScrollPhysics(
                    parent: AlwaysScrollableScrollPhysics(),
                  ),
                  padding: EdgeInsets.fromLTRB(gutter, 6, gutter, 34),
                  children: [
                    const ChangePhoneTitle(),
                    const SizedBox(height: 32),
                    const BlueFieldLabel('New phone number'),
                    const SizedBox(height: 8),
                    BluePhoneField(
                      controller: _phone,
                      focusNode: _phoneFocus,
                      error: false,
                      enabled: !_busy,
                      onChanged: _onPhoneChanged,
                      onCountryChanged: (country) {
                        setState(() => _country = country);
                      },
                      onSubmitted: (_) => _submit(),
                    ),
                    AnimatedSize(
                      duration: BlueMotion.of(context, changePhoneBase),
                      curve: changePhoneEase,
                      alignment: Alignment.topCenter,
                      child: hasErr
                          ? Padding(
                              padding: const EdgeInsets.only(top: 8),
                              child: BlueErrorHint(
                                message:
                                    _formErr ??
                                    'Enter your 9-digit UAE mobile number, for example 50 123 4567.',
                              ),
                            )
                          : const Padding(
                              padding: EdgeInsets.only(top: 8),
                              child: ChangePhoneHelper(),
                            ),
                    ),
                  ],
                ),
              ),
              ChangePhoneFooter(
                gutter: gutter,
                child: ChangePhoneContinueButton(
                  key: const Key('change-phone-continue'),
                  enabled: _complete,
                  busy: _busy,
                  onPressed: _submit,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
