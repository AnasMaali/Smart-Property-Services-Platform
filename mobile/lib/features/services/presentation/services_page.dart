import 'dart:async';

import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../home/data/catalog_service.dart';
import '../../home/data/service_category.dart';
import '../../home/presentation/widgets/home_icons.dart';
import 'service_detail_page.dart';
import 'widgets/services_widgets.dart';

enum _ServicesBody { loading, ready, error }

class ServicesPage extends StatefulWidget {
  const ServicesPage({
    super.key,
    this.categories = const [],
    this.initialCategory,
    this.autofocusSearch = false,
  });

  final List<ServiceCategory> categories;
  final ServiceCategory? initialCategory;
  final bool autofocusSearch;

  @override
  State<ServicesPage> createState() => _ServicesPageState();
}

class _ServicesPageState extends State<ServicesPage> {
  final _search = TextEditingController();
  final _focus = FocusNode();
  final _cache = <String, List<CatalogService>>{};

  late List<ServiceCategory> _categories;
  ServiceCategory? _category;
  List<CatalogService> _services = const [];
  _ServicesBody _body = _ServicesBody.loading;
  int _seq = 0;
  Timer? _debounce;
  bool _revealRows = true;
  final _warming = <String>{};

  String get _query => _search.text.trim();

  bool get _searching => _query.isNotEmpty;

  @override
  void initState() {
    super.initState();
    _categories = widget.categories;
    _category = widget.initialCategory;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _load();
      _warmCache();
      if (widget.autofocusSearch && mounted) {
        _focus.requestFocus();
      }
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _focus.dispose();
    super.dispose();
  }

  String _cacheKey(ServiceCategory? category, [String? query]) {
    return '${category?.id ?? 'all'}|${query ?? _query}';
  }

  Future<void> _load({bool forceSkeleton = false}) async {
    _debounce?.cancel();
    final seq = ++_seq;
    if (forceSkeleton || _body != _ServicesBody.ready) {
      setState(() => _body = _ServicesBody.loading);
    }
    final scope = AppScope.of(context);
    try {
      final categoriesFuture = _categories.isEmpty
          ? scope.catalog.listCategories()
          : Future.value(_categories);
      final searchFuture = scope.catalog.search(
        query: _query,
        categoryId: _category?.id,
      );
      final categories = await categoriesFuture;
      final result = await searchFuture;
      if (!mounted || seq != _seq) return;
      setState(() {
        _categories = categories;
        _services = result.services;
        _cache[_cacheKey(_category)] = result.services;
        _body = _ServicesBody.ready;
      });
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _revealRows = false;
      });
      _warmCache();
    } catch (_) {
      if (!mounted || seq != _seq) return;
      setState(() => _body = _ServicesBody.error);
    }
  }

  void _warmCache() {
    if (_query.isNotEmpty) return;
    for (final category in _categories) {
      if (category.id == _category?.id) continue;
      _warm(category);
    }
    if (_category != null) _warm(null);
  }

  Future<void> _warm(ServiceCategory? category) async {
    final key = _cacheKey(category, '');
    if (_cache.containsKey(key) || !_warming.add(key)) return;
    try {
      final result = await AppScope.of(
        context,
      ).catalog.search(categoryId: category?.id);
      if (!mounted) return;
      _cache[key] = result.services;
    } catch (_) {
    } finally {
      _warming.remove(key);
    }
  }

  void _onQueryChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 280), _load);
    setState(() {});
  }

  void _clearSearch() {
    _search.clear();
    _focus.unfocus();
    _load();
  }

  void _selectCategory(ServiceCategory? category) {
    if (_category?.id == category?.id) return;
    final cached = _query.isEmpty ? _cache[_cacheKey(category, '')] : null;
    setState(() {
      _category = category;
      if (cached != null) {
        _services = cached;
        _body = _ServicesBody.ready;
      }
    });
    if (cached != null && _query.isEmpty) return;
    _load();
  }

  void _browseAll() {
    _search.clear();
    _focus.unfocus();
    if (_category == null) {
      _load();
      return;
    }
    _selectCategory(null);
  }

  String get _title {
    if (_category == null) return 'Services';
    return _category!.name;
  }

  String get _subtitle {
    if (_category == null) return 'Browse everything BLUE can help with.';
    if (_body == _ServicesBody.ready && _services.isEmpty && !_searching) {
      return 'Nothing bookable here yet.';
    }
    return 'Everything under ${_category!.name}.';
  }

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        bottom: false,
        child: BlueEnter(
          duration: BlueMotion.rise,
          offset: const Offset(0.02, 0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  BlueDimens.homeGutter,
                  6,
                  BlueDimens.homeGutter,
                  0,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    ServicesBackButton(
                      onPressed: () => Navigator.of(context).maybePop(),
                    ),
                    const SizedBox(height: 6),
                    AnimatedSwitcher(
                      duration: BlueMotion.of(context, BlueMotion.snap),
                      switchInCurve: BlueMotion.curve,
                      child: ServicesTitle(
                        key: ValueKey('$_title|$_subtitle'),
                        title: _title,
                        subtitle: _subtitle,
                      ),
                    ),
                    const SizedBox(height: 16),
                    ServicesSearchField(
                      controller: _search,
                      focusNode: _focus,
                      onChanged: _onQueryChanged,
                      onClear: _clearSearch,
                      onSubmitted: (_) => _load(),
                    ),
                  ],
                ),
              ),
              if (_body != _ServicesBody.error) ...[
                const SizedBox(height: 14),
                CategoryChipBar(
                  categories: _categories,
                  selected: _category,
                  onSelect: _selectCategory,
                ),
              ],
              Expanded(child: _bodyContent()),
            ],
          ),
        ),
      ),
    );
  }

  Widget _bodyContent() {
    return AnimatedSwitcher(
      duration: BlueMotion.of(context, BlueMotion.snap),
      switchInCurve: BlueMotion.curve,
      switchOutCurve: Curves.easeOut,
      child: switch (_body) {
        _ServicesBody.loading => const Padding(
          key: ValueKey('loading'),
          padding: EdgeInsets.fromLTRB(
            BlueDimens.homeGutter,
            8,
            BlueDimens.homeGutter,
            0,
          ),
          child: ServicesSkeleton(),
        ),
        _ServicesBody.error => Padding(
          key: const ValueKey('error'),
          padding: const EdgeInsets.symmetric(
            horizontal: BlueDimens.homeGutter,
          ),
          child: ServicesMessage(
            icon: BlueGlyph.warning,
            iconColor: BlueColors.error,
            title: "We couldn't load services",
            body:
                'Check your connection and try again. Nothing in your cart or bookings is affected.',
            action: 'Try again',
            onAction: () => _load(forceSkeleton: true),
          ),
        ),
        _ServicesBody.ready => KeyedSubtree(
          key: const ValueKey('ready'),
          child: _readyBody(),
        ),
      },
    );
  }

  Widget _readyBody() {
    return BlueFadeSwitch(
      duration: BlueMotion.snap,
      offset: const Offset(0.04, 0),
      child: KeyedSubtree(
        key: ValueKey('${_category?.id ?? 'all'}|$_query'),
        child: _readyContent(),
      ),
    );
  }

  Widget _readyContent() {
    if (_services.isEmpty && _searching) {
      return Padding(
        padding: const EdgeInsets.symmetric(horizontal: BlueDimens.homeGutter),
        child: ServicesMessage(
          icon: BlueGlyph.search,
          iconColor: BlueColors.glyph,
          title: 'No services found.',
          body:
              "Nothing matches '$_query'. Try a shorter word, or browse by category.",
          action: 'Clear search',
          onAction: _clearSearch,
        ),
      );
    }

    if (_services.isEmpty) {
      final name = _category?.name.toLowerCase();
      final body = name == null
          ? "We're not offering services in your area yet."
          : "We're not offering $name services in your area yet. Everything else is available.";
      return Padding(
        padding: const EdgeInsets.symmetric(horizontal: BlueDimens.homeGutter),
        child: ServicesMessage(
          icon: BlueGlyph.search,
          iconColor: BlueColors.glyph,
          title: 'Nothing here yet',
          body: body,
          action: 'Browse all services',
          onAction: _browseAll,
        ),
      );
    }

    return ListView.builder(
      key: PageStorageKey('services-${_category?.id ?? 'all'}'),
      physics: const BouncingScrollPhysics(
        parent: AlwaysScrollableScrollPhysics(),
      ),
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        10,
        BlueDimens.homeGutter,
        28,
      ),
      itemCount: _services.length + (_searching ? 1 : 0),
      itemBuilder: (context, index) {
        if (_searching && index == 0) {
          return Padding(
            padding: const EdgeInsets.only(bottom: 4, top: 2),
            child: ServiceResultCount(count: _services.length),
          );
        }
        final service = _services[_searching ? index - 1 : index];
        return BlueListReveal(
          index: _searching ? index - 1 : index,
          animate: _revealRows,
          child: ServiceRow(
            key: ValueKey(service.uuid),
            service: service,
            onPressed: () {
              AppScope.of(context).shell.hideNav.value = true;
              Navigator.of(context).push(
                BluePageRoute<void>(
                  builder: (_) => ServiceDetailPage(slug: service.slug),
                ),
              );
            },
          ),
        );
      },
    );
  }
}
