import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../data/rating_models.dart';
import 'rate_service_sheet.dart';
import 'rating_submitted_sheet.dart';
import 'widgets/rating_widgets.dart';

class BookingDetailRatingsPage extends StatefulWidget {
  const BookingDetailRatingsPage({
    super.key,
    required this.view,
    required this.bookingUuid,
    this.onRate,
  });

  final BookingDetailRatingsView view;
  final String bookingUuid;
  final ValueChanged<BookingServiceRatingView>? onRate;

  @override
  State<BookingDetailRatingsPage> createState() =>
      _BookingDetailRatingsPageState();
}

class _BookingDetailRatingsPageState extends State<BookingDetailRatingsPage> {
  late List<BookingServiceRatingView> _services;

  @override
  void initState() {
    super.initState();
    _services = List.of(widget.view.services);
  }

  @override
  void didUpdateWidget(covariant BookingDetailRatingsPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.view.services != widget.view.services) {
      _services = List.of(widget.view.services);
    }
  }

  Future<void> _rate(BookingServiceRatingView service) async {
    widget.onRate?.call(service);
    final result = await showRateServiceSheet(
      context: context,
      serviceName: service.name,
      dateLabel: widget.view.dateLabel,
    );
    if (!mounted || result == null) return;
    try {
      await AppScope.of(context).ratings.submit(
        bookingUuid: widget.bookingUuid,
        ratingValue: result.stars,
        comment: result.comment.isNotEmpty ? result.comment : null,
      );
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Failed to submit rating. Please try again.'),
        ),
      );
      return;
    }
    if (!mounted) return;
    setState(() {
      _services = [
        for (final item in _services)
          if (item.itemUuid == service.itemUuid)
            item.copyWith(
              state: ServiceRatingState.rated,
              stars: result.stars,
              word: result.word,
              comment: result.comment,
            )
          else
            item,
      ];
    });
    await showRatingSubmittedSheet(context: context);
  }

  @override
  Widget build(BuildContext context) {
    final gutter = ratingGutter(context);
    final view = widget.view;
    return Scaffold(
      backgroundColor: BlueColors.canvas,
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
                  child: RatingBackButton(
                    label: 'Back to bookings',
                    onPressed: () => Navigator.of(context).maybePop(),
                  ),
                ),
              ),
              Expanded(
                child: ListView(
                  physics: const BouncingScrollPhysics(
                    parent: AlwaysScrollableScrollPhysics(),
                  ),
                  padding: const EdgeInsets.fromLTRB(0, 6, 0, 34),
                  children: [
                    Padding(
                      padding: EdgeInsets.symmetric(horizontal: gutter),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          RatingStatusBadge(
                            label: view.statusLabel,
                            live: view.statusLive,
                          ),
                          const SizedBox(height: 10),
                          RatingGoldTitle(view.title),
                          RatingMeta(
                            reference: view.reference,
                            rest: view.metaRest,
                          ),
                          const SizedBox(height: 26),
                          const RatingEyebrow('Services'),
                          const SizedBox(height: 11),
                        ],
                      ),
                    ),
                    for (var i = 0; i < _services.length; i++)
                      RatingServiceBlock(
                        view: _services[i],
                        last: i == _services.length - 1,
                        onRate: () => _rate(_services[i]),
                      ),
                    Padding(
                      padding: EdgeInsets.fromLTRB(gutter, 16, gutter, 0),
                      child: RatingHelper(view.helper),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
