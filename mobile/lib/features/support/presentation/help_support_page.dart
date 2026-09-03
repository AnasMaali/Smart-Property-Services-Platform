import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../data/support_models.dart';
import 'new_support_request_page.dart';
import 'support_request_detail_page.dart';
import 'widgets/support_widgets.dart';

class HelpSupportPage extends StatefulWidget {
  const HelpSupportPage({super.key});

  @override
  State<HelpSupportPage> createState() => _HelpSupportPageState();
}

class _HelpSupportPageState extends State<HelpSupportPage> {
  List<SupportRequest> _requests = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadRequests());
  }

  Future<void> _loadRequests() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final requests = await AppScope.of(context).support.list();
      if (!mounted) return;
      setState(() {
        _requests = requests;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = e.toString();
      });
    }
  }

  Future<void> _openNew() async {
    final created = await Navigator.of(context).push<SupportRequest>(
      BluePageRoute(builder: (_) => const NewSupportRequestPage()),
    );
    if (!mounted || created == null) return;
    setState(() => _requests = [created, ..._requests]);
  }

  Future<void> _openDetail(SupportRequest request) async {
    final updated = await Navigator.of(context).push<SupportRequest>(
      BluePageRoute(
        builder: (_) => SupportRequestDetailPage(
          key: Key('support-detail-${request.id}'),
          request: request,
        ),
      ),
    );
    if (!mounted || updated == null) return;
    setState(() {
      _requests = [
        for (final item in _requests)
          if (item.id == updated.id) updated else item,
      ];
    });
  }

  @override
  Widget build(BuildContext context) {
    final gutter = supportGutter(context);
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
                  child: SupportBackButton(
                    onPressed: () {
                      final nav = Navigator.of(context);
                      if (nav.canPop()) nav.pop();
                    },
                  ),
                ),
              ),
              Expanded(
                child: _loading
                    ? const Center(
                        child: CircularProgressIndicator(color: BlueColors.ink),
                      )
                    : _error != null
                    ? Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Text('Could not load requests.'),
                            const SizedBox(height: 12),
                            TextButton(
                              onPressed: _loadRequests,
                              child: const Text('Retry'),
                            ),
                          ],
                        ),
                      )
                    : RefreshIndicator(
                        color: BlueColors.ink,
                        backgroundColor: BlueColors.white,
                        onRefresh: _loadRequests,
                        child: ListView(
                          physics: const BouncingScrollPhysics(
                            parent: AlwaysScrollableScrollPhysics(),
                          ),
                          padding: const EdgeInsets.fromLTRB(0, 6, 0, 34),
                          children: [
                            Padding(
                              padding: EdgeInsets.symmetric(horizontal: gutter),
                              child: const SupportTitle(
                                title: 'Help & Support',
                                subtitle:
                                    "Tell us what's wrong and we'll come back to you here.",
                                gold: true,
                              ),
                            ),
                            const SizedBox(height: 26),
                            SupportCreateRow(onPressed: _openNew),
                            const SizedBox(height: 32),
                            Padding(
                              padding: EdgeInsets.symmetric(horizontal: gutter),
                              child: const SupportEyebrow('Your requests'),
                            ),
                            const SizedBox(height: 11),
                            for (final request in _requests)
                              SupportRequestRow(
                                key: Key('support-row-${request.id}'),
                                request: request,
                                last: request.id == _requests.last.id,
                                onPressed: () => _openDetail(request),
                              ),
                            Padding(
                              padding: EdgeInsets.fromLTRB(
                                gutter,
                                16,
                                gutter,
                                0,
                              ),
                              child: const SupportHelper(
                                'Resolved requests stay here for your records.',
                              ),
                            ),
                          ],
                        ),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
