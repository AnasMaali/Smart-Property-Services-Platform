import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../data/support_models.dart';
import 'widgets/support_widgets.dart';

class SupportRequestDetailPage extends StatefulWidget {
  const SupportRequestDetailPage({super.key, required this.request});

  final SupportRequest request;

  @override
  State<SupportRequestDetailPage> createState() =>
      _SupportRequestDetailPageState();
}

class _SupportRequestDetailPageState extends State<SupportRequestDetailPage> {
  final _reply = TextEditingController();
  final _replyFocus = FocusNode();
  final _scroll = ScrollController();
  late SupportRequest _request = widget.request;

  @override
  void dispose() {
    _reply.dispose();
    _replyFocus.dispose();
    _scroll.dispose();
    super.dispose();
  }

  bool _sending = false;

  bool get _canSend => _reply.text.trim().isNotEmpty && !_sending;

  Future<void> _send() async {
    final text = _reply.text.trim();
    if (text.isEmpty || _sending) return;
    setState(() => _sending = true);
    try {
      final msg = await AppScope.of(
        context,
      ).support.sendMessage(requestUuid: _request.id, message: text);
      if (!mounted) return;
      setState(() {
        _sending = false;
        _request = _request.copyWith(
          messages: [..._request.messages, msg],
          listMeta: _request.status == SupportStatus.resolved
              ? _request.listMeta
              : 'Updated just now · ${_request.messages.length + 1} messages',
          status: _request.status == SupportStatus.resolved
              ? _request.status
              : SupportStatus.inProgress,
        );
        _reply.clear();
      });
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!_scroll.hasClients) return;
        _scroll.animateTo(
          _scroll.position.maxScrollExtent,
          duration: supportSlow,
          curve: supportEase,
        );
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _sending = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Failed to send. Please try again.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final gutter = supportGutter(context);
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        Navigator.of(context).pop(_request);
      },
      child: Scaffold(
        backgroundColor: BlueColors.canvas,
        resizeToAvoidBottomInset: true,
        body: SafeArea(
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
                      onPressed: () => Navigator.of(context).pop(_request),
                    ),
                  ),
                ),
                Expanded(
                  child: ListView(
                    controller: _scroll,
                    cacheExtent: 4000,
                    keyboardDismissBehavior:
                        ScrollViewKeyboardDismissBehavior.onDrag,
                    physics: const BouncingScrollPhysics(
                      parent: AlwaysScrollableScrollPhysics(),
                    ),
                    padding: EdgeInsets.fromLTRB(gutter, 6, gutter, 34),
                    children: [
                      Align(
                        alignment: Alignment.centerLeft,
                        child: SupportStatusBadge(status: _request.status),
                      ),
                      const SizedBox(height: 10),
                      SupportTitle(title: _request.subject, small: true),
                      SupportRequestMeta(
                        number: _request.number,
                        openedLabel: _request.openedLabel,
                      ),
                      const SizedBox(height: 26),
                      const SupportHairline(),
                      const SizedBox(height: 20),
                      for (var i = 0; i < _request.messages.length; i++) ...[
                        if (i > 0) const SizedBox(height: 14),
                        SupportMessageBubble(message: _request.messages[i]),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
        bottomNavigationBar: SupportReplyBar(
          controller: _reply,
          focusNode: _replyFocus,
          enabled: _canSend,
          onChanged: (_) => setState(() {}),
          onSend: _send,
        ),
      ),
    );
  }
}
