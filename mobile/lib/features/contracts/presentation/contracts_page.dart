import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/shell_controller.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../services/presentation/services_page.dart';
import '../../shell/presentation/widgets/blue_bottom_nav.dart';
import '../data/contract_models.dart';
import 'contract_detail_page.dart';
import 'request_contract_page.dart';
import 'widgets/contracts_widgets.dart';

enum _ContractsBody { loading, ready, error }

enum _ContractsTab { current, past }

class _Section {
  const _Section({
    required this.label,
    required this.rows,
    this.foot = '',
    this.gap = 0,
  });

  final String label;
  final List<ContractRowView> rows;
  final String foot;
  final double gap;
}

class ContractsPage extends StatefulWidget {
  const ContractsPage({super.key});

  @override
  State<ContractsPage> createState() => _ContractsPageState();
}

class _ContractsPageState extends State<ContractsPage> {
  _ContractsBody _body = _ContractsBody.loading;
  _ContractsTab _tab = _ContractsTab.current;
  List<Contract> _contracts = const [];
  ShellController? _shell;
  int _seq = 0;
  bool _refreshing = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_shell != null) return;
    _shell = AppScope.of(context).shell;
    _shell!.tab.addListener(_onTab);
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _shell?.tab.removeListener(_onTab);
    super.dispose();
  }

  void _onTab() {
    if (_shell?.tab.value != BlueTab.contracts) return;
    _load(silent: _body == _ContractsBody.ready);
  }

  Future<void> _load({bool silent = false}) async {
    final seq = ++_seq;
    if (!silent) {
      setState(() {
        _body = _ContractsBody.loading;
        _refreshing = false;
      });
    }
    try {
      final contracts = await AppScope.of(context).contracts.list();
      if (!mounted || seq != _seq) return;
      setState(() {
        _contracts = contracts;
        _body = _ContractsBody.ready;
        _refreshing = false;
      });
    } on ApiException {
      if (!mounted || seq != _seq) return;
      setState(() {
        _body = silent ? _body : _ContractsBody.error;
        _refreshing = false;
      });
    } catch (_) {
      if (!mounted || seq != _seq) return;
      setState(() {
        _body = silent ? _body : _ContractsBody.error;
        _refreshing = false;
      });
    }
  }

  Future<void> _refresh() async {
    setState(() => _refreshing = true);
    await _load(silent: true);
  }

  List<Contract> get _current {
    final rows = _contracts.where((contract) => contract.isCurrent).toList();
    rows.sort((a, b) {
      final aHot = a.present().needsAttention;
      final bHot = b.present().needsAttention;
      if (aHot != bHot) return aHot ? -1 : 1;
      return b.createdAt.compareTo(a.createdAt);
    });
    return rows;
  }

  List<Contract> get _past {
    final rows = _contracts.where((contract) => contract.isPast).toList();
    rows.sort((a, b) {
      final aAt = a.updatedAt ?? a.endsAt ?? a.createdAt;
      final bAt = b.updatedAt ?? b.endsAt ?? b.createdAt;
      return bAt.compareTo(aAt);
    });
    return rows;
  }

  List<_Section> _sections(DateTime now) {
    if (_tab == _ContractsTab.past) {
      final past = _past;
      if (past.isEmpty) return const [];
      return [
        _Section(
          label: past.length == 1 ? '1 contract' : '${past.length} contracts',
          rows: past.map((contract) => contract.present(now)).toList(),
          foot: 'Ended and cancelled contracts stay here for your records.',
        ),
      ];
    }

    final current = _current;
    if (current.isEmpty) return const [];
    final views = current.map((contract) => contract.present(now)).toList();
    final needs = views.where((row) => row.needsAttention).toList();
    final running = views.where((row) => !row.needsAttention).toList();
    return [
      if (needs.isNotEmpty)
        _Section(
          label: needs.length == 1
              ? 'Needs your attention'
              : 'Need your attention',
          rows: needs,
          gap: running.isNotEmpty ? 26 : 0,
        ),
      if (running.isNotEmpty)
        _Section(
          label: needs.isNotEmpty
              ? 'Running'
              : (running.length == 1 ? 'Your contract' : 'Your contracts'),
          rows: running,
        ),
    ];
  }

  String _subtitle(int attention) {
    if (_tab == _ContractsTab.past) return '';
    if (attention == 1) return 'One contract needs something from you.';
    if (attention > 1) {
      return '$attention contracts need something from you.';
    }
    return "What's covered at your property, and for how long.";
  }

  void _explore() {
    Navigator.of(
      context,
    ).push(BluePageRoute<void>(builder: (_) => const ServicesPage()));
  }

  Future<void> _openRequest() async {
    final created = await Navigator.of(context).push<Contract>(
      BluePageRoute(builder: (_) => const RequestContractPage()),
    );
    if (!mounted || created == null) return;
    setState(() {
      _contracts = [
        created,
        ..._contracts.where((row) => row.uuid != created.uuid),
      ];
      _tab = _ContractsTab.current;
      _body = _ContractsBody.ready;
    });
  }

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        bottom: false,
        child: BlueEnter(
          duration: BlueMotion.rise,
          offset: const Offset(0, 0.018),
          child: RefreshIndicator(
            color: BlueColors.ink,
            backgroundColor: BlueColors.white,
            displacement: 28,
            onRefresh: _refresh,
            child: CustomScrollView(
              physics: const BouncingScrollPhysics(
                parent: AlwaysScrollableScrollPhysics(),
              ),
              slivers: [
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(0, 14, 0, 26),
                  sliver: SliverList.list(children: _slivers()),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _slivers() {
    final now = DateTime.now();
    final current = _body == _ContractsBody.ready
        ? _current
        : const <Contract>[];
    final past = _body == _ContractsBody.ready ? _past : const <Contract>[];
    final empty = current.isEmpty && past.isEmpty;
    final dead =
        _body == _ContractsBody.loading ||
        _body == _ContractsBody.error ||
        (_body == _ContractsBody.ready && empty);
    final showTabs = !dead;
    final sections = _body == _ContractsBody.ready
        ? _sections(now)
        : const <_Section>[];
    final attention = current
        .where((c) => c.present(now).needsAttention)
        .length;
    final subtitle = showTabs ? _subtitle(attention) : '';

    return [
      if (_refreshing) const ContractsRefreshHint(),
      const ContractsTitle(),
      if (subtitle.isNotEmpty) ContractsSubtitle(text: subtitle),
      if (showTabs)
        ContractsTabs(
          currentOn: _tab == _ContractsTab.current,
          currentCount: current.length,
          onCurrent: () => setState(() => _tab = _ContractsTab.current),
          onPast: () => setState(() => _tab = _ContractsTab.past),
        ),
      if (_body == _ContractsBody.ready && !empty) ...[
        const SizedBox(height: 20),
        ContractsCreateRow(onPressed: _openRequest),
      ],
      if (_body == _ContractsBody.loading) const ContractsSkeleton(),
      if (_body == _ContractsBody.error)
        ContractsErrorState(onRetry: () => _load()),
      if (_body == _ContractsBody.ready && empty)
        ContractsEmptyState(onExplore: _explore, onRequest: _openRequest),
      if (_body == _ContractsBody.ready &&
          !empty &&
          _tab == _ContractsTab.current &&
          current.isEmpty)
        ContractsNoCurrentState(
          onExplore: _explore,
          onSeePast: () => setState(() => _tab = _ContractsTab.past),
        ),
      if (sections.isNotEmpty) ..._list(sections),
    ];
  }

  List<Widget> _list(List<_Section> sections) {
    return [
      const SizedBox(height: 20),
      for (final section in sections) ...[
        if (section.label.isNotEmpty)
          ContractsSectionLabel(label: section.label),
        for (final row in section.rows)
          ContractsRow(
            row: row,
            onPressed: () {
              Navigator.of(context).push(
                BluePageRoute<void>(
                  builder: (_) => ContractDetailPage(contractUuid: row.uuid),
                ),
              );
            },
          ),
        const DecoratedBox(
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: BlueColors.navLine)),
          ),
          child: SizedBox(width: double.infinity, height: 0),
        ),
        if (section.foot.isNotEmpty) ContractsSectionFoot(text: section.foot),
        if (section.gap > 0) SizedBox(height: section.gap),
      ],
    ];
  }
}
