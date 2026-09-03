import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import 'widgets/support_widgets.dart';

class NewSupportRequestPage extends StatefulWidget {
  const NewSupportRequestPage({super.key});

  @override
  State<NewSupportRequestPage> createState() => _NewSupportRequestPageState();
}

class _NewSupportRequestPageState extends State<NewSupportRequestPage> {
  final _subject = TextEditingController();
  final _message = TextEditingController();
  final _subjectFocus = FocusNode();
  final _messageFocus = FocusNode();
  bool _busy = false;
  bool _toast = false;

  @override
  void dispose() {
    _subject.dispose();
    _message.dispose();
    _subjectFocus.dispose();
    _messageFocus.dispose();
    super.dispose();
  }

  bool get _canSubmit {
    return _subject.text.trim().isNotEmpty &&
        _message.text.trim().length >= 10 &&
        !_busy;
  }

  Future<void> _submit() async {
    if (!_canSubmit) return;
    setState(() => _busy = true);
    try {
      final created = await AppScope.of(context).support.create(
        subject: _subject.text.trim(),
        message: _message.text.trim(),
      );
      if (!mounted) return;
      setState(() => _toast = true);
      await Future<void>.delayed(supportToastHold);
      if (!mounted) return;
      Navigator.of(context).pop(created);
    } catch (_) {
      if (!mounted) return;
      setState(() => _busy = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Failed to send. Please try again.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final gutter = supportGutter(context);
    final safe = MediaQuery.paddingOf(context).bottom;
    final inset = MediaQuery.viewInsetsOf(context).bottom;
    return PopScope(
      canPop: !_busy,
      child: ColoredBox(
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
                        child: SupportBackButton(
                          enabled: !_busy,
                          onPressed: () => Navigator.of(context).pop(),
                        ),
                      ),
                    ),
                    Expanded(
                      child: ListView(
                        keyboardDismissBehavior:
                            ScrollViewKeyboardDismissBehavior.onDrag,
                        physics: const BouncingScrollPhysics(
                          parent: AlwaysScrollableScrollPhysics(),
                        ),
                        padding: EdgeInsets.fromLTRB(gutter, 6, gutter, 34),
                        children: [
                          const SupportTitle(
                            title: 'New support request',
                            subtitle:
                                'One request per issue keeps the thread easy to follow.',
                          ),
                          const SizedBox(height: 32),
                          const SupportFieldLabel('Subject'),
                          const SizedBox(height: 8),
                          SupportOutlinedField(
                            key: const Key('support-subject'),
                            controller: _subject,
                            focusNode: _subjectFocus,
                            hint: 'Short summary of the issue',
                            enabled: !_busy,
                            textInputAction: TextInputAction.next,
                            onChanged: (_) => setState(() {}),
                          ),
                          const SizedBox(height: 20),
                          const SupportFieldLabel('Message'),
                          const SizedBox(height: 8),
                          SupportOutlinedField(
                            key: const Key('support-message'),
                            controller: _message,
                            focusNode: _messageFocus,
                            hint:
                                'What happened, and which property or booking it relates to.',
                            enabled: !_busy,
                            minLines: 6,
                            maxLines: 10,
                            onChanged: (_) => setState(() {}),
                          ),
                          const SizedBox(height: 8),
                          const SupportHelper(
                            "We reply in this request, and you'll get a notification when we do.",
                          ),
                        ],
                      ),
                    ),
                    DecoratedBox(
                      decoration: const BoxDecoration(
                        color: BlueColors.white,
                        border: Border(
                          top: BorderSide(color: BlueColors.navLine),
                        ),
                      ),
                      child: Padding(
                        padding: EdgeInsets.fromLTRB(
                          gutter,
                          12,
                          gutter,
                          inset > 0 ? inset + 12 : 30 + safe,
                        ),
                        child: SupportSubmitButton(
                          key: const Key('support-submit'),
                          enabled:
                              _subject.text.trim().isNotEmpty &&
                              _message.text.trim().length >= 10,
                          busy: _busy,
                          onPressed: _submit,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            Align(
              alignment: Alignment.bottomCenter,
              child: SupportToast(visible: _toast, label: 'Request sent'),
            ),
          ],
        ),
      ),
    );
  }
}
