import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/input/latin_digits.dart';
import 'widgets/blue_back_button.dart';
import 'widgets/blue_brand.dart';
import 'widgets/blue_motion.dart';
import 'widgets/blue_primary_button.dart';
import 'widgets/blue_step_progress.dart';
import 'widgets/blue_text_link.dart';
import 'widgets/error_hint.dart';
import 'widgets/lock_note.dart';

class OtpVerifyPage extends StatefulWidget {
  const OtpVerifyPage({
    super.key,
    required this.phoneDigits,
    required this.onBack,
    required this.onVerify,
    required this.onResend,
    this.stepLabel,
    this.stepTitle = 'Verify phone',
    this.headline = 'Verify your phone',
    this.progressStep = 2,
    this.progressTotal = 2,
    this.compactBrand = false,
    this.showEditPhone = true,
  });

  final String phoneDigits;
  final VoidCallback onBack;
  final Future<void> Function(String code) onVerify;
  final Future<void> Function() onResend;
  final String? stepLabel;
  final String stepTitle;
  final String headline;
  final int progressStep;
  final int progressTotal;
  final bool compactBrand;
  final bool showEditPhone;

  @override
  State<OtpVerifyPage> createState() => _OtpVerifyPageState();
}

class _OtpVerifyPageState extends State<OtpVerifyPage> {
  final _controller = TextEditingController();
  final _focus = FocusNode();
  bool _busy = false;
  bool _err = false;
  String _errMsg = 'That code was invalid. Check the code and try again.';
  bool _verified = false;
  int _left = 60;
  Timer? _clock;

  @override
  void initState() {
    super.initState();
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

  void _startClock() {
    _clock?.cancel();
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

  String get _masked {
    final d = LatinDigits.only(widget.phoneDigits);
    final prefix = d.length >= 2 ? d.substring(0, 2) : '50';
    final suffix = d.length >= 9 ? d.substring(5, 9) : '4567';
    return '+971 $prefix *** $suffix';
  }

  String get _clockLabel {
    final mm = (_left ~/ 60).toString().padLeft(2, '0');
    final ss = (_left % 60).toString().padLeft(2, '0');
    return '$mm:$ss';
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
    if (clipped.length == 6) _verify(clipped);
  }

  Future<void> _verify([String? codeArg]) async {
    final code = (codeArg ?? _code);
    if (_busy || _verified || code.length < 6) return;
    setState(() {
      _busy = true;
      _err = false;
    });
    _focus.unfocus();
    try {
      await widget.onVerify(code);
      if (!mounted) return;
      HapticFeedback.lightImpact();
      setState(() {
        _busy = false;
        _verified = true;
      });
    } on ApiException catch (error) {
      if (!mounted) return;
      HapticFeedback.vibrate();
      setState(() {
        _busy = false;
        _err = true;
        _errMsg = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      HapticFeedback.vibrate();
      setState(() {
        _busy = false;
        _err = true;
        _errMsg = 'Something went wrong. Please try again.';
      });
    }
  }

  Future<void> _resend() async {
    if (_busy || _verified) return;
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
      setState(() {
        _busy = false;
        _err = true;
        _errMsg = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _err = true;
        _errMsg = 'Something went wrong. Please try again.';
      });
    }
  }

  BoxShadow _ringFor(int i) {
    if (_err) {
      return const BoxShadow(color: BlueColors.error, spreadRadius: 2);
    }
    if (_verified) {
      return const BoxShadow(color: BlueColors.verified, spreadRadius: 2);
    }
    if (_focus.hasFocus && i == _code.length.clamp(0, 5)) {
      return const BoxShadow(
        color: BlueColors.ink,
        spreadRadius: 2,
        blurRadius: 0,
      );
    }
    return const BoxShadow(color: Colors.transparent);
  }

  @override
  Widget build(BuildContext context) {
    final complete = _code.length == 6;

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
                        Stack(
                          alignment: Alignment.topCenter,
                          children: [
                            BlueBrand(compact: widget.compactBrand),
                            Align(
                              alignment: Alignment.topLeft,
                              child: BlueBackButton(onPressed: widget.onBack),
                            ),
                          ],
                        ),
                        if (widget.stepLabel != null) ...[
                          const SizedBox(height: 24),
                          BlueStepProgress(
                            step: widget.progressStep,
                            total: widget.progressTotal,
                            label: widget.stepLabel!,
                            title: widget.stepTitle,
                          ),
                          const SizedBox(height: 22),
                        ] else
                          const SizedBox(height: 34),
                        BlueEnter(
                          child: Text(
                            widget.headline,
                            textAlign: TextAlign.center,
                            style: const TextStyle(
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
                        BlueEnter(
                          delay: const Duration(milliseconds: 70),
                          child: SizedBox(
                            width: 290,
                            child: Text.rich(
                              TextSpan(
                                style: const TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 15,
                                  height: 1.5,
                                  fontWeight: FontWeight.w400,
                                  color: BlueColors.muted,
                                ),
                                children: [
                                  const TextSpan(
                                    text: 'Enter the 6-digit code we sent to ',
                                  ),
                                  TextSpan(
                                    text: _masked,
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w700,
                                      color: BlueColors.ink,
                                    ),
                                  ),
                                ],
                              ),
                              textAlign: TextAlign.center,
                            ),
                          ),
                        ),
                        if (widget.showEditPhone)
                          BlueTextLink(
                            label: 'Edit phone number',
                            fontSize: 13.5,
                            onPressed: widget.onBack,
                          ),
                        const SizedBox(height: 22),
                        SizedBox(
                          height: BlueDimens.otpBoxHeight,
                          child: Stack(
                            children: [
                              Row(
                                children: [
                                  for (var i = 0; i < 6; i++) ...[
                                    if (i > 0) const SizedBox(width: 8),
                                    Expanded(
                                      child: _OtpBox(
                                        char: i < _code.length ? _code[i] : '',
                                        ring: _ringFor(i),
                                        focused:
                                            _focus.hasFocus &&
                                            !_err &&
                                            !_verified &&
                                            i == _code.length.clamp(0, 5),
                                      ),
                                    ),
                                  ],
                                ],
                              ),
                              Positioned.fill(
                                child: Opacity(
                                  opacity: 0,
                                  child: TextField(
                                    controller: _controller,
                                    focusNode: _focus,
                                    enabled: !_busy && !_verified,
                                    keyboardType: TextInputType.number,
                                    textInputAction: TextInputAction.go,
                                    maxLength: 6,
                                    autofillHints: const [
                                      AutofillHints.oneTimeCode,
                                    ],
                                    inputFormatters: [
                                      LatinDigits.formatter,
                                      FilteringTextInputFormatter.digitsOnly,
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
                          duration: BlueMotion.snap,
                          curve: BlueMotion.curve,
                          alignment: Alignment.topCenter,
                          child: _err
                              ? Padding(
                                  padding: const EdgeInsets.only(top: 12),
                                  child: BlueErrorHint(message: _errMsg),
                                )
                              : const SizedBox.shrink(),
                        ),
                        AnimatedSize(
                          duration: BlueMotion.snap,
                          curve: BlueMotion.curve,
                          child: SizedBox(height: _err ? 18 : 24),
                        ),
                        BluePrimaryButton(
                          label: _verified
                              ? 'Verified'
                              : (_busy ? 'Verifying…' : 'Verify'),
                          busy: _busy,
                          verified: _verified,
                          enabled: complete || _busy || _verified,
                          onPressed: _verify,
                        ),
                        const SizedBox(height: 10),
                        if (_left == 0 && !_verified)
                          BlueTextLink(label: 'Resend code', onPressed: _resend)
                        else if (_left > 0)
                          SizedBox(
                            height: 44,
                            child: Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                const Text(
                                  'Resend code in',
                                  style: TextStyle(
                                    fontFamily: BlueFonts.jakarta,
                                    fontSize: 14,
                                    fontWeight: FontWeight.w400,
                                    color: BlueColors.muted,
                                  ),
                                ),
                                const SizedBox(width: 6),
                                Text(
                                  _clockLabel,
                                  style: const TextStyle(
                                    fontFamily: BlueFonts.mono,
                                    fontSize: 13.5,
                                    fontWeight: FontWeight.w500,
                                    color: BlueColors.ink,
                                    fontFeatures: [
                                      FontFeature.tabularFigures(),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                          )
                        else
                          const SizedBox(height: 44),
                        const Expanded(child: SizedBox(height: 20)),
                        const LockNote(
                          centered: true,
                          maxWidth: 250,
                          text:
                              'Codes expire after 5 minutes. Never share it with anyone.',
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

class _OtpBox extends StatelessWidget {
  const _OtpBox({
    required this.char,
    required this.ring,
    required this.focused,
  });

  final String char;
  final BoxShadow ring;
  final bool focused;

  @override
  Widget build(BuildContext context) {
    return AnimatedScale(
      scale: focused ? 1.04 : 1,
      duration: BlueMotion.snap,
      curve: BlueMotion.curve,
      child: AnimatedContainer(
        duration: BlueMotion.snap,
        curve: BlueMotion.curve,
        height: BlueDimens.otpBoxHeight,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          color: BlueColors.white,
          borderRadius: BorderRadius.circular(BlueDimens.otpBoxRadius),
          border: Border.all(color: BlueColors.border),
          boxShadow: [
            ring,
            if (focused)
              const BoxShadow(color: BlueColors.glowInk, spreadRadius: 6),
          ],
        ),
        child: AnimatedSwitcher(
          duration: BlueMotion.tick,
          switchInCurve: BlueMotion.curve,
          transitionBuilder: (child, animation) {
            return ScaleTransition(
              scale: Tween<double>(begin: 0.55, end: 1).animate(animation),
              child: FadeTransition(opacity: animation, child: child),
            );
          },
          child: Text(
            char,
            key: ValueKey(char),
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 23,
              fontWeight: FontWeight.w700,
              letterSpacing: 23 * 0.01,
              color: BlueColors.ink,
            ),
          ),
        ),
      ),
    );
  }
}
