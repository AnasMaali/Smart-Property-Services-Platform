import 'package:flutter/material.dart';

import '../../../app/theme/blue_theme.dart';
import 'widgets/blue_back_button.dart';
import 'widgets/blue_brand.dart';
import 'widgets/blue_country_sheet.dart';
import 'widgets/blue_field_label.dart';
import 'widgets/blue_motion.dart';
import 'widgets/blue_outlined_field.dart';
import 'widgets/blue_phone_field.dart';
import 'widgets/blue_primary_button.dart';
import 'widgets/blue_step_progress.dart';
import 'widgets/blue_text_link.dart';
import 'widgets/error_hint.dart';

class SignupDetails {
  const SignupDetails({
    required this.name,
    required this.email,
    required this.phone,
    required this.phoneE164,
  });

  final String name;
  final String email;
  final String phone;
  final String phoneE164;
}

class SignupDetailsPage extends StatefulWidget {
  const SignupDetailsPage({
    super.key,
    required this.onContinue,
    required this.onBack,
    required this.onLogin,
  });

  final ValueChanged<SignupDetails> onContinue;
  final VoidCallback onBack;
  final VoidCallback onLogin;

  @override
  State<SignupDetailsPage> createState() => _SignupDetailsPageState();
}

class _SignupDetailsPageState extends State<SignupDetailsPage> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _nameFocus = FocusNode();
  final _emailFocus = FocusNode();
  final _phoneFocus = FocusNode();
  BlueCountry _country = BlueCountry.uae;
  bool _nameErr = false;
  bool _emailErr = false;
  bool _phoneErr = false;

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _nameFocus.dispose();
    _emailFocus.dispose();
    _phoneFocus.dispose();
    super.dispose();
  }

  bool _nameOk(String value) =>
      value.trim().split(RegExp(r'\s+')).where((w) => w.isNotEmpty).length >= 2;

  bool _emailOk(String value) => RegExp(
    r'^[^\s@]+@[^\s@]+\.[a-z]{2,}$',
    caseSensitive: false,
  ).hasMatch(value.trim());

  void _onPhoneChanged(String value) {
    final formatted = UaePhone.format(value);
    if (formatted != value) {
      _phone.value = TextEditingValue(
        text: formatted,
        selection: TextSelection.collapsed(offset: formatted.length),
      );
    }
    setState(() => _phoneErr = false);
  }

  void _blurName() {
    setState(() {
      _nameErr = _name.text.isNotEmpty && !_nameOk(_name.text);
    });
  }

  void _blurEmail() {
    setState(() {
      _emailErr = _email.text.isNotEmpty && !_emailOk(_email.text);
    });
  }

  void _blurPhone() {
    setState(() {
      _phoneErr =
          _phone.text.isNotEmpty && UaePhone.digits(_phone.text).length < 9;
    });
  }

  void _submit() {
    FocusScope.of(context).unfocus();
    final nameErr = !_nameOk(_name.text);
    final emailErr = !_emailOk(_email.text);
    final phoneErr = UaePhone.digits(_phone.text).length < 9;
    if (nameErr || emailErr || phoneErr) {
      setState(() {
        _nameErr = nameErr;
        _emailErr = emailErr;
        _phoneErr = phoneErr;
      });
      return;
    }
    widget.onContinue(
      SignupDetails(
        name: _name.text.trim(),
        email: _email.text.trim(),
        phone: UaePhone.digits(_phone.text),
        phoneE164: UaePhone.e164(_phone.text, dial: _country.dial),
      ),
    );
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
                    padding: const EdgeInsets.fromLTRB(24, 26, 24, 30),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Stack(
                          alignment: Alignment.topCenter,
                          children: [
                            const BlueBrand(compact: true),
                            Align(
                              alignment: Alignment.topLeft,
                              child: BlueBackButton(onPressed: widget.onBack),
                            ),
                          ],
                        ),
                        const SizedBox(height: 24),
                        const BlueStepProgress(
                          step: 1,
                          total: 3,
                          label: 'Step 1 of 3',
                          title: 'Your details',
                        ),
                        const SizedBox(height: 18),
                        const BlueEnter(
                          child: Text(
                            'Create your account',
                            style: TextStyle(
                              fontFamily: BlueFonts.jakarta,
                              fontSize: 30,
                              height: 1.15,
                              fontWeight: FontWeight.w700,
                              letterSpacing: 30 * -0.025,
                              color: BlueColors.ink,
                            ),
                          ),
                        ),
                        const SizedBox(height: 8),
                        const SizedBox(
                          width: 300,
                          child: Text(
                            "A few details and you're set — no password to remember.",
                            style: TextStyle(
                              fontFamily: BlueFonts.jakarta,
                              fontSize: 14.5,
                              height: 1.5,
                              fontWeight: FontWeight.w400,
                              color: BlueColors.muted,
                            ),
                          ),
                        ),
                        const SizedBox(height: 24),
                        const BlueFieldLabel('Full name'),
                        const SizedBox(height: 8),
                        Focus(
                          onFocusChange: (has) {
                            if (!has) _blurName();
                          },
                          child: BlueOutlinedField(
                            controller: _name,
                            focusNode: _nameFocus,
                            hint: 'Aisha Al Mansoori',
                            error: _nameErr,
                            enabled: true,
                            textCapitalization: TextCapitalization.words,
                            textInputAction: TextInputAction.next,
                            autofillHints: const [AutofillHints.name],
                            onChanged: (_) => setState(() => _nameErr = false),
                          ),
                        ),
                        AnimatedSize(
                          duration: BlueMotion.snap,
                          curve: BlueMotion.curve,
                          alignment: Alignment.topCenter,
                          child: _nameErr
                              ? const Padding(
                                  padding: EdgeInsets.only(top: 8),
                                  child: BlueErrorHint(
                                    message:
                                        'Enter your full name as it appears on your ID.',
                                  ),
                                )
                              : const SizedBox.shrink(),
                        ),
                        AnimatedSize(
                          duration: BlueMotion.snap,
                          curve: BlueMotion.curve,
                          child: SizedBox(height: _nameErr ? 12 : 18),
                        ),
                        const BlueFieldLabel('Email address'),
                        const SizedBox(height: 8),
                        Focus(
                          onFocusChange: (has) {
                            if (!has) _blurEmail();
                          },
                          child: BlueOutlinedField(
                            controller: _email,
                            focusNode: _emailFocus,
                            hint: 'you@example.com',
                            error: _emailErr,
                            enabled: true,
                            keyboardType: TextInputType.emailAddress,
                            textInputAction: TextInputAction.next,
                            autocorrect: false,
                            enableSuggestions: false,
                            autofillHints: const [AutofillHints.email],
                            onChanged: (_) => setState(() => _emailErr = false),
                          ),
                        ),
                        AnimatedSize(
                          duration: BlueMotion.snap,
                          curve: BlueMotion.curve,
                          alignment: Alignment.topCenter,
                          child: _emailErr
                              ? const Padding(
                                  padding: EdgeInsets.only(top: 8),
                                  child: BlueErrorHint(
                                    message:
                                        'Check the email address — it looks incomplete.',
                                  ),
                                )
                              : const SizedBox.shrink(),
                        ),
                        AnimatedSize(
                          duration: BlueMotion.snap,
                          curve: BlueMotion.curve,
                          child: SizedBox(height: _emailErr ? 12 : 18),
                        ),
                        const BlueFieldLabel('Phone number'),
                        const SizedBox(height: 8),
                        Focus(
                          onFocusChange: (has) {
                            if (!has) _blurPhone();
                          },
                          child: BluePhoneField(
                            controller: _phone,
                            focusNode: _phoneFocus,
                            error: _phoneErr,
                            enabled: true,
                            onChanged: _onPhoneChanged,
                            onCountryChanged: (country) {
                              setState(() => _country = country);
                            },
                            onSubmitted: (_) => _submit(),
                          ),
                        ),
                        AnimatedSize(
                          duration: BlueMotion.snap,
                          curve: BlueMotion.curve,
                          alignment: Alignment.topCenter,
                          child: Padding(
                            padding: const EdgeInsets.only(top: 8),
                            child: _phoneErr
                                ? const BlueErrorHint(
                                    message:
                                        'Enter your 9-digit mobile number. For example 50 123 4567.',
                                  )
                                : const Text(
                                    "We'll send a 6-digit code to confirm this number.",
                                    style: TextStyle(
                                      fontFamily: BlueFonts.jakarta,
                                      fontSize: 12.5,
                                      height: 1.4,
                                      fontWeight: FontWeight.w400,
                                      color: BlueColors.muted,
                                    ),
                                  ),
                          ),
                        ),
                        const SizedBox(height: 24),
                        BluePrimaryButton(
                          label: 'Continue',
                          enabled: true,
                          onPressed: _submit,
                        ),
                        const Expanded(child: SizedBox(height: 24)),
                        const ColoredBox(
                          color: BlueColors.border,
                          child: SizedBox(height: 1, width: double.infinity),
                        ),
                        const SizedBox(height: 10),
                        Center(
                          child: Wrap(
                            alignment: WrapAlignment.center,
                            crossAxisAlignment: WrapCrossAlignment.center,
                            spacing: 7,
                            children: [
                              const Text(
                                'Already have an account?',
                                style: TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 14,
                                  fontWeight: FontWeight.w400,
                                  color: BlueColors.muted,
                                ),
                              ),
                              BlueTextLink(
                                label: 'Login',
                                onPressed: widget.onLogin,
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
