import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/input/latin_digits.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../auth/presentation/widgets/error_hint.dart';
import 'widgets/change_phone_widgets.dart';
import 'widgets/verify_phone_widgets.dart';

class VerifyPhoneOtpPage extends StatefulWidget {
  const VerifyPhoneOtpPage({
    super.key,
    required this.displayPhone,
    required this.onVerify,
    required this.onResend,
    this.resendAvailableAt,
  });

  final String displayPhone;
  final Future<void> Function(String code) onVerify;
  final Future<void> Function() onResend;
  final DateTime? resendAvailableAt;

  @override
  State<VerifyPhoneOtpPage> createState() => _VerifyPhoneOtpPageState();
}

class _VerifyPhoneOtpPageState extends State<VerifyPhoneOtpPage> {
  final _controller = TextEditingController();
  final _focus = FocusNode();
  bool _busy = false;
  bool _err = false;
  bool _toast = false;
  String _errMsg = "That code wasn't valid. Check the code and try again.";
  int _left = 60;
  Timer? _clock;

  @override
  void initState() {
    super.initState();
    _left = _secondsUntil(widget.resendAvailableAt);
    _focus.addListener(() => setState(() {}));
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _focus.requestFocus();
    });
    _startClock();
  }

  @override
  void dispose() {
    _clock?.cancel();
    _controller.dispose();
    _focus.dispose();
    super.dispose();
  }

  int _secondsUntil(DateTime? until) {
    if (until == null) return 60;
    final seconds = until.difference(DateTime.now()).inSeconds;
    if (seconds <= 0) return 0;
    return seconds;
  }

  void _startClock() {
    _clock?.cancel();
    if (_left <= 0) return;
    _clock = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;
      if (_left <= 1) {
        _clock?.cancel();
        setState(() => _left = 0);
      } else {
        setState(() => _left -= 1);
      }
    });
  }

  String get _code {
    final digits = LatinDigits.only(_controller.text);
    return digits.length <= 6 ? digits : digits.substring(0, 6);
  }

  void _onChanged(String value) {
    final next = LatinDigits.only(value);
    final clipped = next.substring(0, next.length.clamp(0, 6));
    if (clipped != value) {
      _controller.value = TextEditingValue(
        text: clipped,
        selection: TextSelection.collapsed(offset: clipped.length),
      );
    }
    setState(() => _err = false);
  }

  Future<void> _verify() async {
    final code = _code;
    if (_busy || _toast || code.length < 6) return;
    setState(() {
      _busy = true;
      _err = false;
    });
    _focus.unfocus();
    try {
      await widget.onVerify(code);
      if (!mounted) return;
      HapticFeedback.lightImpact();
      _clock?.cancel();
      setState(() {
        _busy = false;
        _toast = true;
      });
      final hold = MediaQuery.disableAnimationsOf(context)
          ? Duration.zero
          : const Duration(milliseconds: 1400);
      await Future<void>.delayed(hold);
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (error) {
      if (!mounted) return;
      BlueMotion.warn();
      setState(() {
        _busy = false;
        _err = true;
        _errMsg = error.displayMessage;
      });
      _focus.requestFocus();
    } catch (_) {
      if (!mounted) return;
      BlueMotion.warn();
      setState(() {
        _busy = false;
        _err = true;
        _errMsg = 'Something went wrong. Please try again.';
      });
      _focus.requestFocus();
    }
  }

  Future<void> _resend() async {
    if (_busy || _toast || _left > 0) return;
    setState(() => _busy = true);
    try {
      await widget.onResend();
      if (!mounted) return;
      setState(() {
        _controller.clear();
        _err = false;
        _busy = false;
        _left = 60;
      });
      _focus.requestFocus();
      _startClock();
    } on ApiException catch (error) {
      if (!mounted) return;
      BlueMotion.warn();
      setState(() {
        _busy = false;
        _err = true;
        _errMsg = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      BlueMotion.warn();
      setState(() {
        _busy = false;
        _err = true;
        _errMsg = 'Something went wrong. Please try again.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final gutter = MediaQuery.sizeOf(context).width < 359 ? 18.0 : 24.0;
    final complete = _code.length == 6;
    final keyboard = MediaQuery.viewInsetsOf(context).bottom;

    return ColoredBox(
      color: BlueColors.canvas,
      child: Stack(
        children: [
          SafeArea(
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
                        onPressed: () => Navigator.of(context).pop(false),
                      ),
                    ),
                  ),
                  Expanded(
                    child: CustomScrollView(
                      physics: const BouncingScrollPhysics(
                        parent: AlwaysScrollableScrollPhysics(),
                      ),
                      slivers: [
                        SliverPadding(
                          padding: EdgeInsets.fromLTRB(gutter, 6, gutter, 0),
                          sliver: SliverList(
                            delegate: SliverChildListDelegate([
                              VerifyPhoneTitle(
                                displayPhone: widget.displayPhone,
                              ),
                              const SizedBox(height: 2),
                              VerifyPhoneEditLink(
                                onPressed: () =>
                                    Navigator.of(context).pop(false),
                              ),
                              const SizedBox(height: 20),
                              SizedBox(
                                height: BlueDimens.otpBoxHeight,
                                child: Stack(
                                  children: [
                                    VerifyPhoneOtpBoxes(
                                      code: _code,
                                      focused:
                                          _focus.hasFocus && !_busy && !_toast,
                                      invalid: _err,
                                    ),
                                    Positioned.fill(
                                      child: Opacity(
                                        opacity: 0,
                                        child: TextField(
                                          controller: _controller,
                                          focusNode: _focus,
                                          enabled: !_busy && !_toast,
                                          keyboardType: TextInputType.number,
                                          textInputAction: TextInputAction.go,
                                          maxLength: 6,
                                          autofillHints: const [
                                            AutofillHints.oneTimeCode,
                                          ],
                                          inputFormatters: [
                                            LatinDigits.formatter,
                                            FilteringTextInputFormatter
                                                .digitsOnly,
                                          ],
                                          decoration: const InputDecoration(
                                            border: InputBorder.none,
                                            counterText: '',
                                          ),
                                          onChanged: _onChanged,
                                          onSubmitted: (_) => _verify(),
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              AnimatedSize(
                                duration: BlueMotion.of(
                                  context,
                                  changePhoneBase,
                                ),
                                curve: changePhoneEase,
                                alignment: Alignment.topCenter,
                                child: _err
                                    ? Padding(
                                        padding: const EdgeInsets.only(top: 8),
                                        child: BlueErrorHint(message: _errMsg),
                                      )
                                    : const SizedBox.shrink(),
                              ),
                              const SizedBox(height: 26),
                              ChangePhoneContinueButton(
                                key: const Key('change-phone-verify'),
                                label: 'Verify',
                                busyLabel: 'Verifying…',
                                enabled: complete,
                                busy: _busy,
                                onPressed: _verify,
                              ),
                              const SizedBox(height: 8),
                              VerifyPhoneResend(
                                secondsLeft: _left,
                                onResend: _resend,
                              ),
                            ]),
                          ),
                        ),
                        SliverFillRemaining(
                          hasScrollBody: false,
                          child: Padding(
                            padding: EdgeInsets.fromLTRB(
                              gutter,
                              20,
                              gutter,
                              34 +
                                  MediaQuery.paddingOf(context).bottom +
                                  keyboard,
                            ),
                            child: const Align(
                              alignment: Alignment.bottomCenter,
                              child: VerifyPhoneLockNote(),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
          Align(
            alignment: Alignment.bottomCenter,
            child: VerifyPhoneToast(visible: _toast),
          ),
        ],
      ),
    );
  }
}
