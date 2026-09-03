import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../bookings/data/booking_models.dart';
import '../../bookings/presentation/booking_detail_page.dart';
import '../../home/data/catalog_service.dart';
import '../../profile/data/customer_profile.dart';
import '../../services/presentation/service_detail_page.dart';
import '../../services/presentation/services_page.dart';
import '../../support/presentation/help_support_page.dart';
import '../data/service_category.dart';
import 'widgets/home_catalog.dart';
import 'widgets/home_current_booking.dart';
import 'widgets/home_hero.dart';
import 'widgets/home_sections.dart';

enum _HomeBody { loading, catalog, error }

class HomePage extends StatefulWidget {
  const HomePage({super.key});

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  _HomeBody _body = _HomeBody.loading;
  List<ServiceCategory> _categories = const [];
  List<CatalogService> _services = const [];
  CustomerProfile? _profile;
  Booking? _current;
  ServiceCategory? _filter;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) setState(() => _body = _HomeBody.loading);
    final scope = AppScope.of(context);
    try {
      final categoriesFuture = scope.catalog.listCategories();
      final servicesFuture = scope.catalog.search(categoryId: _filter?.id);
      final profileFuture = scope.profile
          .get()
          .then<CustomerProfile?>((value) => value)
          .catchError((_) => null);
      final bookingsFuture = scope.bookings
          .list()
          .then<List<Booking>>((value) => value)
          .catchError((_) => const <Booking>[]);
      final categories = await categoriesFuture;
      final services = await servicesFuture;
      final profile = await profileFuture;
      final bookings = await bookingsFuture;
      if (!mounted) return;
      setState(() {
        _categories = categories;
        _services = services.services;
        _profile = profile;
        _current = HomeCurrentBookingCard.pick(bookings);
        _body = _HomeBody.catalog;
      });
    } on ApiException {
      if (!mounted) return;
      setState(() => _body = _HomeBody.error);
    } catch (_) {
      if (!mounted) return;
      setState(() => _body = _HomeBody.error);
    }
  }

  Future<void> _selectFilter(ServiceCategory? category) async {
    setState(() => _filter = category);
    try {
      final result = await AppScope.of(
        context,
      ).catalog.search(categoryId: category?.id);
      if (!mounted) return;
      setState(() => _services = result.services);
    } catch (_) {}
  }

  String get _firstName {
    final fromProfile = _profile?.firstName;
    if (fromProfile != null && fromProfile.isNotEmpty) return fromProfile;
    final session = AppScope.of(context).auth.current;
    final name = session?.fullName.trim() ?? '';
    if (name.isEmpty) return 'there';
    return name.split(RegExp(r'\s+')).first;
  }

  void _openServices({
    ServiceCategory? category,
    bool autofocusSearch = false,
  }) {
    Navigator.of(context).push(
      BluePageRoute<void>(
        builder: (_) => ServicesPage(
          categories: _categories,
          initialCategory: category,
          autofocusSearch: autofocusSearch,
        ),
      ),
    );
  }

  void _openService(CatalogService service) {
    Navigator.of(context).push(
      BluePageRoute<void>(
        builder: (_) => ServiceDetailPage(slug: service.slug),
      ),
    );
  }

  void _openBooking(String uuid) {
    Navigator.of(context).push(
      BluePageRoute<void>(builder: (_) => BookingDetailPage(bookingUuid: uuid)),
    );
  }

  Future<void> _openFilter() async {
    final pick = await showHomeFilterSheet(
      context: context,
      categories: _categories,
      selected: _filter,
    );
    if (pick == null || !mounted) return;
    await _selectFilter(pick.category);
  }

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        bottom: false,
        top: false,
        child: BlueEnter(
          duration: BlueMotion.rise,
          offset: const Offset(0, 0.018),
          child: RefreshIndicator(
            color: BlueColors.ink,
            backgroundColor: BlueColors.white,
            onRefresh: () => _load(silent: true),
            child: CustomScrollView(
              physics: const BouncingScrollPhysics(
                parent: AlwaysScrollableScrollPhysics(),
              ),
              slivers: [
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: 24),
                    child: SizedBox(
                      height: 320 + MediaQuery.of(context).padding.top + 80,
                      child: Stack(
                        clipBehavior: Clip.none,
                        children: [
                          const Positioned(
                            top: 0,
                            left: 0,
                            right: 0,
                            bottom: 80,
                            child: HomeHeroArt(),
                          ),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              SizedBox(
                                height: MediaQuery.of(context).padding.top,
                              ),
                              Padding(
                                padding: const EdgeInsets.fromLTRB(
                                  BlueDimens.homeGutter,
                                  12,
                                  BlueDimens.homeGutter,
                                  0,
                                ),
                                child: HomeHeader(
                                  hasAlerts: _current != null,
                                  onBell: () {
                                    final current = _current;
                                    if (current != null) {
                                      _openBooking(current.uuid);
                                      return;
                                    }
                                    _openServices();
                                  },
                                ),
                              ),
                              Padding(
                                padding: const EdgeInsets.fromLTRB(
                                  BlueDimens.homeGutter,
                                  32,
                                  BlueDimens.homeGutter,
                                  0,
                                ),
                                child: Align(
                                  alignment: Alignment.centerLeft,
                                  child: FractionallySizedBox(
                                    widthFactor: 0.45,
                                    child: HomeGreeting(name: _firstName),
                                  ),
                                ),
                              ),
                            ],
                          ),
                          Positioned(
                            left: BlueDimens.homeGutter,
                            right: BlueDimens.homeGutter,
                            bottom: 0,
                            child: Container(
                              padding: const EdgeInsets.all(16),
                              decoration: BoxDecoration(
                                color: BlueColors.white,
                                borderRadius: BorderRadius.circular(24),
                                boxShadow: const [
                                  BoxShadow(
                                    color: BlueColors.cardShadow,
                                    blurRadius: 24,
                                    offset: Offset(0, 8),
                                  ),
                                ],
                              ),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  HomeSearchFilterBar(
                                    onSearch: () =>
                                        _openServices(autofocusSearch: true),
                                    onFilter: _openFilter,
                                  ),
                                  if (_body == _HomeBody.catalog) ...[
                                    const SizedBox(height: 12),
                                    HomeFilterChips(
                                      categories: _categories,
                                      selected: _filter,
                                      onSelect: _selectFilter,
                                    ),
                                  ],
                                ],
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                SliverPadding(
                  padding: const EdgeInsets.fromLTRB(
                    BlueDimens.homeGutter,
                    0,
                    BlueDimens.homeGutter,
                    32,
                  ),
                  sliver: SliverList.list(
                    children: [
                      AnimatedSwitcher(
                        duration: BlueMotion.of(context, BlueMotion.page),
                        switchInCurve: BlueMotion.curve,
                        switchOutCurve: Curves.easeOut,
                        layoutBuilder: (current, previous) {
                          return Stack(
                            alignment: Alignment.topCenter,
                            children: [...previous, ?current],
                          );
                        },
                        transitionBuilder: (child, animation) {
                          final offset =
                              Tween<Offset>(
                                begin: const Offset(0, 0.035),
                                end: Offset.zero,
                              ).animate(
                                CurvedAnimation(
                                  parent: animation,
                                  curve: BlueMotion.curve,
                                ),
                              );
                          return FadeTransition(
                            opacity: animation,
                            child: SlideTransition(
                              position: offset,
                              child: child,
                            ),
                          );
                        },
                        child: switch (_body) {
                          _HomeBody.loading => const HomeSkeleton(
                            key: ValueKey('loading'),
                          ),
                          _HomeBody.error => HomeErrorCard(
                            key: const ValueKey('error'),
                            onRetry: _load,
                          ),
                          _HomeBody.catalog => _HomeCatalog(
                            key: const ValueKey('catalog'),
                            categories: _categories,
                            services: _services,
                            booking: _current,
                            onSeeCategories: _openServices,
                            onSeeServices: () =>
                                _openServices(category: _filter),
                            onCategory: (category) =>
                                _openServices(category: category),
                            onOpenService: _openService,
                            onAddService: _openService,
                            onBookingDetails: () =>
                                _openBooking(_current!.uuid),
                            onReschedule: () {
                              Navigator.of(context).push(
                                BluePageRoute<void>(
                                  builder: (_) => const HelpSupportPage(),
                                ),
                              );
                            },
                          ),
                        },
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _HomeCatalog extends StatelessWidget {
  const _HomeCatalog({
    super.key,
    required this.categories,
    required this.services,
    required this.booking,
    required this.onSeeCategories,
    required this.onSeeServices,
    required this.onCategory,
    required this.onOpenService,
    required this.onAddService,
    required this.onBookingDetails,
    required this.onReschedule,
  });

  final List<ServiceCategory> categories;
  final List<CatalogService> services;
  final Booking? booking;
  final VoidCallback onSeeCategories;
  final VoidCallback onSeeServices;
  final ValueChanged<ServiceCategory> onCategory;
  final ValueChanged<CatalogService> onOpenService;
  final ValueChanged<CatalogService> onAddService;
  final VoidCallback onBookingDetails;
  final VoidCallback onReschedule;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SizedBox(height: 22),
        HomeSectionHeader(
          title: 'Service Categories',
          onViewAll: onSeeCategories,
        ),
        const SizedBox(height: 12),
        HomeCategoryStrip(categories: categories, onCategory: onCategory),
        if (services.any((service) => service.isBestseller)) ...[
          const SizedBox(height: 22),
          HomeSectionHeader(title: 'Best Sellers', onViewAll: onSeeServices),
          const SizedBox(height: 12),
          HomeServiceStrip(
            services: services
                .where((service) => service.isBestseller)
                .toList(),
            onOpen: onOpenService,
            onAdd: onAddService,
          ),
        ],
        const SizedBox(height: 22),
        HomeSectionHeader(
          title: 'Available Services',
          onViewAll: onSeeServices,
        ),
        const SizedBox(height: 12),
        HomeServiceStrip(
          services: services,
          onOpen: onOpenService,
          onAdd: onAddService,
        ),
        if (booking != null) ...[
          const SizedBox(height: 22),
          HomeCurrentBookingCard(
            booking: booking!,
            onDetails: onBookingDetails,
            onReschedule: onReschedule,
          ),
        ],
      ],
    );
  }
}
