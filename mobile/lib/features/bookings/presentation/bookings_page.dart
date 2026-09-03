import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/shell_controller.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../services/presentation/services_page.dart';
import '../../shell/presentation/widgets/blue_bottom_nav.dart';
import '../data/booking_models.dart';
import 'booking_detail_page.dart';
import 'widgets/bookings_widgets.dart';

enum _BookingsBody { loading, ready, error }

enum _BookingsTab { current, past }

class _Section {
  const _Section({
    required this.label,
    required this.rows,
    this.foot = '',
    this.gap = 0,
  });

  final String label;
  final List<BookingRowView> rows;
  final String foot;
  final double gap;
}

class BookingsPage extends StatefulWidget {
  const BookingsPage({super.key});

  @override
  State<BookingsPage> createState() => _BookingsPageState();
}

class _BookingsPageState extends State<BookingsPage> {
  _BookingsBody _body = _BookingsBody.loading;
  _BookingsTab _tab = _BookingsTab.current;
  List<Booking> _bookings = const [];
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
    if (_shell?.tab.value != BlueTab.bookings) return;
    _load(silent: _body == _BookingsBody.ready);
  }

  Future<void> _load({bool silent = false}) async {
    final seq = ++_seq;
    if (!silent) {
      setState(() {
        _body = _BookingsBody.loading;
        _refreshing = false;
      });
    }
    try {
      final bookings = await AppScope.of(context).bookings.list();
      if (!mounted || seq != _seq) return;
      setState(() {
        _bookings = bookings;
        _body = _BookingsBody.ready;
        _refreshing = false;
      });
    } on ApiException {
      if (!mounted || seq != _seq) return;
      setState(() {
        _body = silent ? _body : _BookingsBody.error;
        _refreshing = false;
      });
    } catch (_) {
      if (!mounted || seq != _seq) return;
      setState(() {
        _body = silent ? _body : _BookingsBody.error;
        _refreshing = false;
      });
    }
  }

  Future<void> _refresh() async {
    setState(() => _refreshing = true);
    await _load(silent: true);
  }

  List<Booking> _currentOf(DateTime now) {
    final rows = _bookings.where((booking) => booking.isCurrent).toList();
    rows.sort((a, b) {
      final aView = a.present(now);
      final bView = b.present(now);
      final aHot =
          aView.tone == BookingTone.today || aView.tone == BookingTone.active;
      final bHot =
          bView.tone == BookingTone.today || bView.tone == BookingTone.active;
      if (aHot != bHot) return aHot ? -1 : 1;
      if (aView.tone == BookingTone.active &&
          bView.tone != BookingTone.active) {
        return -1;
      }
      if (bView.tone == BookingTone.active &&
          aView.tone != BookingTone.active) {
        return 1;
      }
      return a.slot.startsAt.compareTo(b.slot.startsAt);
    });
    return rows;
  }

  List<Booking> get _past {
    final rows = _bookings.where((booking) => booking.isPast).toList();
    rows.sort((a, b) => b.slot.startsAt.compareTo(a.slot.startsAt));
    return rows;
  }

  List<_Section> _sections(DateTime now) {
    if (_tab == _BookingsTab.past) {
      final past = _past;
      if (past.isEmpty) return const [];
      return [
        _Section(
          label: past.length == 1 ? '1 booking' : '${past.length} bookings',
          rows: past.map((booking) => booking.present(now)).toList(),
          foot:
              'Cancelled bookings stay here too — nothing is removed from your history.',
        ),
      ];
    }

    final current = _currentOf(now);
    if (current.isEmpty) return const [];
    final views = current.map((booking) => booking.present(now)).toList();
    final soon = views
        .where(
          (row) =>
              row.tone == BookingTone.today || row.tone == BookingTone.active,
        )
        .toList();
    final later = views
        .where(
          (row) =>
              row.tone != BookingTone.today && row.tone != BookingTone.active,
        )
        .toList();
    return [
      if (soon.isNotEmpty)
        _Section(
          label: 'Happening today',
          rows: soon,
          gap: later.isNotEmpty ? 26 : 0,
        ),
      if (later.isNotEmpty)
        _Section(
          label: soon.isNotEmpty ? 'Coming up' : 'Scheduled',
          rows: later,
        ),
    ];
  }

  void _browse() {
    Navigator.of(
      context,
    ).push(BluePageRoute<void>(builder: (_) => const ServicesPage()));
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
    final current = _body == _BookingsBody.ready
        ? _currentOf(now)
        : const <Booking>[];
    final past = _body == _BookingsBody.ready ? _past : const <Booking>[];
    final empty = current.isEmpty && past.isEmpty;
    final dead =
        _body == _BookingsBody.loading ||
        _body == _BookingsBody.error ||
        (_body == _BookingsBody.ready && empty);
    final showTabs = !dead;
    final sections = _body == _BookingsBody.ready
        ? _sections(now)
        : const <_Section>[];

    return [
      if (_refreshing) const BookingsRefreshHint(),
      const BookingsTitle(),
      if (showTabs)
        BookingsTabs(
          currentOn: _tab == _BookingsTab.current,
          currentCount: current.length,
          onCurrent: () => setState(() => _tab = _BookingsTab.current),
          onPast: () => setState(() => _tab = _BookingsTab.past),
        ),
      if (_body == _BookingsBody.loading) const BookingsSkeleton(),
      if (_body == _BookingsBody.error)
        BookingsErrorState(onRetry: () => _load()),
      if (_body == _BookingsBody.ready && empty)
        BookingsEmptyState(onBrowse: _browse),
      if (_body == _BookingsBody.ready &&
          !empty &&
          _tab == _BookingsTab.current &&
          current.isEmpty)
        BookingsNoCurrentState(
          onBrowse: _browse,
          onSeePast: () => setState(() => _tab = _BookingsTab.past),
        ),
      if (sections.isNotEmpty) ..._list(sections),
    ];
  }

  List<Widget> _list(List<_Section> sections) {
    return [
      const SizedBox(height: 20),
      for (final section in sections) ...[
        if (section.label.isNotEmpty)
          BookingsSectionLabel(label: section.label),
        for (final row in section.rows)
          BookingsRow(
            row: row,
            onPressed: () {
              Navigator.of(context).push(
                BluePageRoute<void>(
                  builder: (_) => BookingDetailPage(bookingUuid: row.uuid),
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
        if (section.foot.isNotEmpty) BookingsSectionFoot(text: section.foot),
        if (section.gap > 0) SizedBox(height: section.gap),
      ],
    ];
  }
}
