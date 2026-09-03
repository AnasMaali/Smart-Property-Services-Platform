import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/otp_verify_page.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import 'widgets/delete_account_widgets.dart';

class DeleteAccountPage extends StatefulWidget {
  const DeleteAccountPage({super.key});

  @override
  State<DeleteAccountPage> createState() => _DeleteAccountPageState();
}

class _DeleteAccountPageState extends State<DeleteAccountPage> {
  bool _sheetVisible = false;
  bool _sheetOpen = false;
  bool _busy = false;
  String? _sheetError;

  void _openSheet() {
    if (_busy) return;
    BlueMotion.warn();
    setState(() {
      _sheetError = null;
      _sheetVisible = true;
      _sheetOpen = false;
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || !_sheetVisible) return;
      setState(() => _sheetOpen = true);
    });
  }

  void _closeSheet({bool force = false}) {
    if (_busy && !force) return;
    if (!_sheetOpen && !_sheetVisible) return;
    setState(() => _sheetOpen = false);
    Future<void>.delayed(deleteSlow, () {
      if (!mounted || _sheetOpen) return;
      setState(() {
        _sheetVisible = false;
        _sheetError = null;
      });
    });
  }

  Future<void> _confirm() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _sheetError = null;
    });
    try {
      await AppScope.of(context).auth.requestAccountDeletionOtp();
      if (!mounted) return;
      _closeSheet(force: true);
      await Future<void>.delayed(deleteSlow);
      if (!mounted) return;
      setState(() => _busy = false);
      await _openOtp();
    } on ApiException catch (error) {
      if (!mounted) return;
      BlueMotion.warn();
      setState(() {
        _busy = false;
        _sheetError = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      BlueMotion.warn();
      setState(() {
        _busy = false;
        _sheetError = 'Something went wrong. Please try again.';
      });
    }
  }

  Future<void> _openOtp() async {
    final phone = AppScope.of(context).auth.current?.phoneNumber ?? '';
    final pending = await Navigator.of(context).push<bool>(
      BluePageRoute(
        builder: (_) => OtpVerifyPage(
          phoneDigits: phone,
          headline: 'Enter your code',
          compactBrand: true,
          showEditPhone: false,
          onBack: () => Navigator.of(context).pop(false),
          onVerify: (code) async {
            final result = await AppScope.of(
              context,
            ).auth.deleteAccount(otpCode: code);
            if (!mounted) return;
            if (result.pending) {
              Navigator.of(context).pop(true);
            }
          },
          onResend: () => AppScope.of(context).auth.resendAccountDeletionOtp(),
        ),
      ),
    );
    if (!mounted) return;
    if (pending == true) {
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: !_sheetVisible,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_sheetVisible) _closeSheet();
      },
      child: ColoredBox(
        color: BlueColors.canvas,
        child: Stack(
          children: [
            SafeArea(
              bottom: false,
              child: BlueEnter(
                duration: BlueMotion.rise,
                offset: const Offset(0, 0.018),
                child: Builder(
                  builder: (context) {
                    final gutter = MediaQuery.sizeOf(context).width < 359
                        ? 18.0
                        : 24.0;
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Padding(
                          padding: EdgeInsets.fromLTRB(gutter, 2, gutter, 0),
                          child: Align(
                            alignment: Alignment.centerLeft,
                            child: DeleteAccountBackButton(
                              onPressed: () {
                                final nav = Navigator.of(context);
                                if (nav.canPop()) nav.pop();
                              },
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
                              const DeleteAccountTitle(),
                              const SizedBox(height: 18),
                              const DeleteAccountFacts(),
                              const SizedBox(height: 26),
                              const DeleteAccountNotice(),
                              const SizedBox(height: 28),
                              DeleteGhostButton(
                                label: 'Keep my account',
                                onPressed: () => Navigator.of(context).pop(),
                              ),
                              const SizedBox(height: 10),
                              DeleteDangerButton(
                                key: const Key('delete-account-open'),
                                label: 'Delete account',
                                onPressed: _openSheet,
                              ),
                              const DeleteAccountHelper(),
                            ],
                          ),
                        ),
                      ],
                    );
                  },
                ),
              ),
            ),
            if (_sheetVisible)
              DeleteHostedSheet(
                open: _sheetOpen,
                onDismiss: _closeSheet,
                child: DeleteConfirmSheet(
                  busy: _busy,
                  error: _sheetError,
                  onConfirm: _confirm,
                  onKeep: _closeSheet,
                ),
              ),
          ],
        ),
      ),
    );
  }
}
