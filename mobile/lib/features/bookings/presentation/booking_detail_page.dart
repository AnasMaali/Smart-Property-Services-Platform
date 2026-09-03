import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/shell_controller.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../ratings/data/rating_models.dart';
import '../../ratings/presentation/already_rated_page.dart';
import '../../ratings/presentation/booking_detail_ratings_page.dart';
import '../../services/presentation/service_detail_page.dart';
import '../../services/presentation/services_page.dart';
import '../data/booking_models.dart';
import 'widgets/booking_detail_widgets.dart';

enum _DetailBody { loading, ready, error, notFound }

class BookingDetailPage extends StatefulWidget {
  const BookingDetailPage({super.key, required this.bookingUuid});

  final String bookingUuid;

  @override
  State<BookingDetailPage> createState() => _BookingDetailPageState();
}

class _BookingDetailPageState extends State<BookingDetailPage> {
  _DetailBody _body = _DetailBody.loading;
  Booking? _booking;
  ShellController? _shell;
  int _seq = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      AppScope.of(context).shell.hideNav.value = true;
      _load();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _shell ??= AppScope.of(context).shell;
  }

  @override
  void dispose() {
    _shell?.hideNav.value = false;
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    final seq = ++_seq;
    if (!silent) {
      setState(() => _body = _DetailBody.loading);
    }
    try {
      final booking = await AppScope.of(
        context,
      ).bookings.get(widget.bookingUuid);
      if (!mounted || seq != _seq) return;
      setState(() {
        _booking = booking;
        _body = _DetailBody.ready;
      });
    } on ApiException catch (error) {
      if (!mounted || seq != _seq) return;
      setState(() {
        _body = error.statusCode == 404
            ? _DetailBody.notFound
            : (silent ? _body : _DetailBody.error);
      });
    } catch (_) {
      if (!mounted || seq != _seq) return;
      setState(() {
        _body = silent ? _body : _DetailBody.error;
      });
    }
  }

  void _back() {
    Navigator.of(context).maybePop();
  }

  Future<void> _bookAgain(BookingDetailView view) async {
    var slug = view.bookAgainSlug;
    if (slug.isEmpty && view.bookAgainName.isNotEmpty) {
      try {
        final result = await AppScope.of(
          context,
        ).catalog.search(query: view.bookAgainName);
        final items = _booking?.items ?? const <BookingItem>[];
        final uuid = items.isEmpty ? '' : items.first.service.uuid;
        final code = items.isEmpty ? '' : items.first.service.code;
        for (final service in result.services) {
          if (service.uuid == uuid ||
              service.code == code ||
              service.name == view.bookAgainName) {
            slug = service.slug;
            break;
          }
        }
        if (slug.isEmpty && result.services.isNotEmpty) {
          slug = result.services.first.slug;
        }
      } catch (_) {}
    }
    if (!mounted) return;
    if (slug.isEmpty) {
      await Navigator.of(
        context,
      ).push(BluePageRoute<void>(builder: (_) => const ServicesPage()));
    } else {
      await Navigator.of(context).push(
        BluePageRoute<void>(builder: (_) => ServiceDetailPage(slug: slug)),
      );
    }
    if (mounted) AppScope.of(context).shell.hideNav.value = true;
  }

  Future<void> _openRated() async {
    final booking = _booking;
    if (booking == null || !booking.hasRating) return;
    await Navigator.of(context).push(
      BluePageRoute<void>(
        builder: (_) =>
            AlreadyRatedPage(view: AlreadyRatedView.fromBooking(booking)),
      ),
    );
    if (mounted) AppScope.of(context).shell.hideNav.value = true;
  }

  Future<void> _openRatings() async {
    final booking = _booking;
    if (booking == null) return;
    await Navigator.of(context).push(
      BluePageRoute<void>(
        builder: (_) => BookingDetailRatingsPage(
          view: BookingDetailRatingsView.fromBooking(booking),
          bookingUuid: widget.bookingUuid,
        ),
      ),
    );
    if (mounted) {
      AppScope.of(context).shell.hideNav.value = true;
      _load(silent: true);
    }
  }

  Future<void> _cancelBooking() async {
    try {
      final preview = await AppScope.of(
        context,
      ).bookings.cancellationPreview(widget.bookingUuid);
      if (!mounted) return;

      if (!preview.cancellable) {
        await showDialog<void>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Cannot cancel'),
            content: Text(preview.summary),
            actions: [
              TextButton(
                onPressed: () => Navigator.of(ctx).pop(),
                child: const Text('OK'),
              ),
            ],
          ),
        );
        return;
      }

      final confirmed = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Cancel booking'),
          content: Text(
            '${preview.summary}\n\nAre you sure you want to cancel this booking?',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: const Text('Keep booking'),
            ),
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Confirm cancel'),
            ),
          ],
        ),
      );
      if (confirmed != true || !mounted) return;

      await AppScope.of(context).bookings.cancel(widget.bookingUuid);
      if (!mounted) return;
      _load(silent: true);
    } on ApiException catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error.displayMessage)),
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Failed to cancel booking. Please try again.'),
        ),
      );
    }
  }

  void _onAction(String id, BookingDetailView view) {
    switch (id) {
      case 'help':
        showBookingHelpSheet(context, view.reference);
      case 'again':
        _bookAgain(view);
      case 'rated':
        _openRated();
      case 'rate':
        _openRatings();
      case 'cancel':
        _cancelBooking();
      default:
        break;
    }
  }

  @override
  Widget build(BuildContext context) {
    final ready = _body == _DetailBody.ready && _booking != null;
    final view = ready ? _booking!.detail() : null;
    final dead = _body != _DetailBody.ready;

    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        bottom: false,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            BookingDetailAppBar(
              reference: dead ? '' : (view?.reference ?? ''),
              onBack: _back,
            ),
            Expanded(
              child: RefreshIndicator(
                color: BlueColors.ink,
                backgroundColor: BlueColors.white,
                displacement: 28,
                notificationPredicate: (_) =>
                    _body == _DetailBody.ready || _body == _DetailBody.loading,
                onRefresh: () => _load(silent: _body == _DetailBody.ready),
                child: CustomScrollView(
                  physics: const BouncingScrollPhysics(
                    parent: AlwaysScrollableScrollPhysics(),
                  ),
                  slivers: [
                    SliverPadding(
                      padding: const EdgeInsets.fromLTRB(0, 6, 0, 34),
                      sliver: SliverList.list(
                        children: [
                          if (_body == _DetailBody.loading)
                            const BookingDetailSkeleton(),
                          if (_body == _DetailBody.error)
                            BookingDetailFail(
                              notFound: false,
                              onRetry: () => _load(),
                              onBack: _back,
                            ),
                          if (_body == _DetailBody.notFound)
                            BookingDetailFail(
                              notFound: true,
                              onRetry: _back,
                              onBack: _back,
                            ),
                          if (view != null)
                            BlueEnter(
                              key: ValueKey(widget.bookingUuid),
                              duration: _fade,
                              offset: Offset.zero,
                              child: BookingDetailBody(
                                view: view,
                                onAction: (id) => _onAction(id, view),
                                onAlert: () {},
                              ),
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

const _fade = Duration(milliseconds: 180);
