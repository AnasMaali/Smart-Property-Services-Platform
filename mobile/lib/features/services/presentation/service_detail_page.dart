import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../app/app_scope.dart';
import '../../../app/shell_controller.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/input/latin_digits.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../home/data/catalog_service.dart';
import '../../shell/presentation/widgets/blue_bottom_nav.dart';
import '../data/service_detail.dart';
import 'widgets/service_detail_widgets.dart';

enum _DetailBody { loading, ready, error, notFound }

enum _UiStatus { priced, quote, context, unavailable, invalid }

class ServiceDetailPage extends StatefulWidget {
  const ServiceDetailPage({
    super.key,
    required this.slug,
    this.cartItemUuid,
    this.initialOptions = const [],
  });

  final String slug;
  final String? cartItemUuid;
  final List<Map<String, dynamic>> initialOptions;

  @override
  State<ServiceDetailPage> createState() => _ServiceDetailPageState();
}

class _ServiceDetailPageState extends State<ServiceDetailPage> {
  final _page = PageController();
  final _scroll = ScrollController();
  final _keys = <String, GlobalKey>{};
  final _focus = <String, FocusNode>{};
  final _text = <String, TextEditingController>{};
  final _touched = <String>{};
  final _single = <String, String>{};
  final _multi = <String, Set<String>>{};
  final _boolean = <String, bool?>{};

  _DetailBody _body = _DetailBody.loading;
  ServiceDetail? _detail;
  int _gallery = 0;
  bool _expanded = false;
  bool _moved = false;
  bool _toast = false;
  bool _adding = false;
  String? _submitError;
  Timer? _movedTimer;
  Timer? _toastTimer;
  Timer? _priceTimer;
  ShellController? _shell;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      AppScope.of(context).shell.hideNav.value = true;
      _load();
      _refreshCart();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _shell ??= AppScope.of(context).shell;
  }

  @override
  void dispose() {
    _movedTimer?.cancel();
    _toastTimer?.cancel();
    _priceTimer?.cancel();
    _shell?.hideNav.value = false;
    _page.dispose();
    _scroll.dispose();
    for (final node in _focus.values) {
      node.dispose();
    }
    for (final controller in _text.values) {
      controller.dispose();
    }
    super.dispose();
  }

  GlobalKey _key(String id) => _keys.putIfAbsent(id, GlobalKey.new);

  FocusNode _node(String id) {
    return _focus.putIfAbsent(id, () {
      final node = FocusNode();
      node.addListener(() {
        if (mounted) setState(() {});
      });
      return node;
    });
  }

  TextEditingController _controller(ServiceOption option) {
    return _text.putIfAbsent(option.uuid, () {
      final initial = option.kind == ServiceOptionKind.number
          ? (option.numeric?.formatDefault() ?? '')
          : '';
      final controller = TextEditingController(text: initial);
      controller.addListener(() => setState(() {}));
      return controller;
    });
  }

  Future<void> _refreshCart() async {
    final scope = AppScope.of(context);
    try {
      final count = await scope.cart.itemCount();
      if (!mounted) return;
      scope.shell.cartCount.value = count;
    } catch (_) {}
  }

  Future<void> _load() async {
    setState(() {
      _body = _DetailBody.loading;
      _submitError = null;
    });
    try {
      final detail = await AppScope.of(context).catalog.getDetails(widget.slug);
      if (!mounted) return;
      _bind(detail);
      setState(() {
        _detail = detail;
        _body = _DetailBody.ready;
        _gallery = 0;
        _expanded = false;
        _moved = false;
      });
      _refreshPrice();
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _body = error.statusCode == 404
            ? _DetailBody.notFound
            : _DetailBody.error;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _body = _DetailBody.error);
    }
  }

  void _bind(ServiceDetail detail) {
    for (final option in detail.options) {
      _key(option.uuid);
      if (option.kind == ServiceOptionKind.number ||
          option.kind == ServiceOptionKind.text) {
        _node(option.uuid);
        _controller(option);
      }
      if (option.kind == ServiceOptionKind.multiSelect) {
        _multi.putIfAbsent(option.uuid, () => <String>{});
      }
      if (option.kind == ServiceOptionKind.boolean) {
        _boolean.putIfAbsent(option.uuid, () => null);
      }
      if (option.kind == ServiceOptionKind.singleSelect &&
          !_single.containsKey(option.uuid) &&
          option.choices.isNotEmpty) {
        _single[option.uuid] = option.choices.first.uuid;
      }
    }
    _hydrate(detail);
  }

  void _hydrate(ServiceDetail detail) {
    if (widget.initialOptions.isEmpty) return;
    for (final raw in widget.initialOptions) {
      final uuid = raw['option_uuid'] as String?;
      if (uuid == null || uuid.isEmpty) continue;
      ServiceOption? option;
      for (final item in detail.options) {
        if (item.uuid == uuid) {
          option = item;
          break;
        }
      }
      if (option == null) continue;
      switch (option.kind) {
        case ServiceOptionKind.text:
          final value = (raw['text_value'] as String?)?.trim() ?? '';
          if (value.isNotEmpty) _controller(option).text = value;
        case ServiceOptionKind.number:
          final rawNumber = raw['numeric_value']?.toString();
          if (rawNumber == null || rawNumber.isEmpty) continue;
          _controller(option).text = formatCatalogMoney(
            rawNumber,
            option.numeric?.decimalPlaces ?? 0,
          );
        case ServiceOptionKind.boolean:
          if (raw['boolean_value'] is bool) {
            _boolean[uuid] = raw['boolean_value'] as bool;
          }
        case ServiceOptionKind.singleSelect:
          final choices = raw['choice_uuids'];
          if (choices is List && choices.isNotEmpty) {
            _single[uuid] = '${choices.first}';
          }
        case ServiceOptionKind.multiSelect:
          final choices = raw['choice_uuids'];
          if (choices is List) {
            _multi[uuid] = {
              for (final choice in choices)
                if ('$choice'.isNotEmpty) '$choice',
            };
          }
      }
    }
  }

  List<String> _missing(ServiceDetail detail) {
    final out = <String>[];
    for (final option in detail.options) {
      if (!option.isRequired) continue;
      if (!_filled(option)) out.add(option.uuid);
    }
    return out;
  }

  bool _filled(ServiceOption option) {
    switch (option.kind) {
      case ServiceOptionKind.text:
        return _controller(option).text.trim().isNotEmpty;
      case ServiceOptionKind.number:
        return _numberError(option) == null &&
            _controller(option).text.trim().isNotEmpty;
      case ServiceOptionKind.boolean:
        return _boolean[option.uuid] != null;
      case ServiceOptionKind.singleSelect:
        return (_single[option.uuid] ?? '').isNotEmpty;
      case ServiceOptionKind.multiSelect:
        final selected = _multi[option.uuid] ?? const <String>{};
        final min = option.minSelections ?? 1;
        return selected.length >= min;
    }
  }

  String? _numberError(ServiceOption option) {
    final raw = _controller(option).text.trim();
    if (raw.isEmpty) return option.isRequired ? _requiredMessage(option) : null;
    final value = double.tryParse(raw);
    final min = option.numeric?.min ?? 1;
    final max = option.numeric?.max ?? 10;
    if (value == null || value < min || value > max) {
      final minLabel = _prettyNum(min);
      final maxLabel = _prettyNum(max);
      return 'Enter a number between $minLabel and $maxLabel.';
    }
    return null;
  }

  String _prettyNum(double value) {
    if (value == value.roundToDouble()) return '${value.round()}';
    return formatCatalogMoney('$value', 2);
  }

  String _requiredMessage(ServiceOption option) {
    return switch (option.kind) {
      ServiceOptionKind.number => () {
        final min = option.numeric?.min ?? 1;
        final max = option.numeric?.max ?? 10;
        return 'Enter a number between ${_prettyNum(min)} and ${_prettyNum(max)}.';
      }(),
      ServiceOptionKind.singleSelect => 'Choose one to continue.',
      _ => 'This is needed to price the job.',
    };
  }

  _UiStatus _status(ServiceDetail detail) {
    final missing = _missing(detail);
    switch (detail.pricing.status) {
      case CatalogPricingStatus.unavailable:
        return _UiStatus.unavailable;
      case CatalogPricingStatus.quoteRequired:
        return _UiStatus.quote;
      case CatalogPricingStatus.missingContext:
        return missing.isEmpty ? _UiStatus.context : _UiStatus.invalid;
      case CatalogPricingStatus.priced:
        if (missing.isNotEmpty) return _UiStatus.invalid;
        return detail.pricing.isPriced ? _UiStatus.priced : _UiStatus.invalid;
    }
  }

  void _markMoved() {
    _movedTimer?.cancel();
    setState(() => _moved = true);
    _movedTimer = Timer(const Duration(milliseconds: 2600), () {
      if (mounted) setState(() => _moved = false);
    });
  }

  void _onChanged() {
    _markMoved();
    setState(() => _submitError = null);
    _priceTimer?.cancel();
    _priceTimer = Timer(const Duration(milliseconds: 280), _refreshPrice);
  }

  Future<void> _refreshPrice() async {
    final detail = _detail;
    if (detail == null || !mounted) return;
    try {
      final pricing = await AppScope.of(
        context,
      ).catalog.previewPricing(slug: detail.slug, options: _payload(detail));
      if (!mounted) return;
      setState(() {
        _detail = detail.withPricing(pricing);
      });
    } catch (_) {}
  }

  Future<void> _focusFirst(List<String> ids) async {
    if (ids.isEmpty) return;
    final id = ids.first;
    final key = _keys[id];
    final ctx = key?.currentContext;
    if (ctx != null) {
      await Scrollable.ensureVisible(
        ctx,
        duration: BlueMotion.of(context, BlueMotion.snap),
        curve: BlueMotion.curve,
        alignment: 0.18,
      );
    }
    _focus[id]?.requestFocus();
  }

  Future<void> _onCta() async {
    final detail = _detail;
    if (detail == null || _adding) return;
    final status = _status(detail);
    final missing = _missing(detail);
    final blocked =
        status == _UiStatus.unavailable ||
        status == _UiStatus.invalid ||
        (status == _UiStatus.context && missing.isNotEmpty);

    if (blocked || missing.isNotEmpty && status != _UiStatus.quote) {
      setState(() {
        for (final option in detail.options) {
          if (option.isRequired) _touched.add(option.uuid);
        }
      });
      await _focusFirst(missing);
      return;
    }

    if (status == _UiStatus.quote && missing.isNotEmpty) {
      setState(() {
        for (final option in detail.options) {
          if (option.isRequired) _touched.add(option.uuid);
        }
      });
      await _focusFirst(missing);
      return;
    }

    setState(() {
      _adding = true;
      _submitError = null;
    });
    try {
      final cartItemUuid = widget.cartItemUuid;
      if (cartItemUuid != null && cartItemUuid.isNotEmpty) {
        final cart = await AppScope.of(
          context,
        ).cart.updateItem(itemUuid: cartItemUuid, options: _payload(detail));
        if (!mounted) return;
        AppScope.of(context).shell.cartCount.value = cart.serviceCount;
        setState(() => _adding = false);
        Navigator.of(context).maybePop();
        return;
      }
      final count = await AppScope.of(
        context,
      ).cart.addItem(serviceUuid: detail.uuid, options: _payload(detail));
      if (!mounted) return;
      AppScope.of(context).shell.cartCount.value = count;
      _toastTimer?.cancel();
      setState(() {
        _adding = false;
        _toast = true;
      });
      _toastTimer = Timer(const Duration(milliseconds: 3200), () {
        if (mounted) setState(() => _toast = false);
      });
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _adding = false;
        _submitError = error.displayMessage;
        for (final option in detail.options) {
          if (option.isRequired) _touched.add(option.uuid);
        }
      });
      await _focusFirst(missing);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _adding = false;
        _submitError = 'Something went wrong. Please try again.';
      });
    }
  }

  List<Map<String, dynamic>> _payload(ServiceDetail detail) {
    final out = <Map<String, dynamic>>[];
    for (final option in detail.options) {
      switch (option.kind) {
        case ServiceOptionKind.text:
          final value = _controller(option).text.trim();
          if (value.isEmpty) continue;
          out.add({'option_uuid': option.uuid, 'text_value': value});
        case ServiceOptionKind.number:
          final raw = _controller(option).text.trim();
          if (raw.isEmpty) continue;
          final value = num.tryParse(raw);
          if (value == null) continue;
          out.add({'option_uuid': option.uuid, 'numeric_value': value});
        case ServiceOptionKind.boolean:
          final value = _boolean[option.uuid];
          if (value == null) continue;
          out.add({'option_uuid': option.uuid, 'boolean_value': value});
        case ServiceOptionKind.singleSelect:
          final choice = _single[option.uuid];
          if (choice == null || choice.isEmpty) continue;
          out.add({
            'option_uuid': option.uuid,
            'choice_uuids': [choice],
          });
        case ServiceOptionKind.multiSelect:
          final choices = (_multi[option.uuid] ?? const <String>{}).toList();
          if (choices.isEmpty) continue;
          out.add({'option_uuid': option.uuid, 'choice_uuids': choices});
      }
    }
    return out;
  }

  void _openCart() {
    AppScope.of(context).shell.openTab(BlueTab.cart);
    Navigator.of(context).maybePop();
  }

  void _popToServices() {
    AppScope.of(context).shell.hideNav.value = false;
    Navigator.of(context).maybePop();
  }

  String _unitCaption(ServiceDetail detail) {
    for (final option in detail.options) {
      if (option.kind != ServiceOptionKind.number) continue;
      final raw = _controller(option).text.trim();
      final value = int.tryParse(raw.split('.').first) ?? 1;
      var unit = option.numeric?.displayUnit ?? '';
      if (unit.isEmpty) unit = 'unit';
      if (value == 1) {
        if (unit.endsWith('s') && unit.length > 1) {
          unit = unit.substring(0, unit.length - 1);
        }
        return 'For 1 $unit';
      }
      if (unit == 'unit') unit = 'units';
      if (!unit.endsWith('s')) unit = '${unit}s';
      return 'For $value $unit';
    }
    return 'For 1 unit';
  }

  String _neededText(int count) {
    return count == 1 ? '1 detail needed' : '$count details needed';
  }

  @override
  Widget build(BuildContext context) {
    final keyboard = MediaQuery.viewInsetsOf(context).bottom > 0;
    final count = AppScope.of(context).shell.cartCount;
    return PopScope(
      onPopInvokedWithResult: (didPop, _) {
        AppScope.of(context).shell.hideNav.value = false;
      },
      child: AnnotatedRegion<SystemUiOverlayStyle>(
        value: const SystemUiOverlayStyle(
          statusBarColor: Colors.transparent,
          statusBarIconBrightness: Brightness.dark,
          statusBarBrightness: Brightness.light,
        ),
        child: Scaffold(
          backgroundColor: BlueColors.canvas,
          resizeToAvoidBottomInset: true,
          body: ValueListenableBuilder<int>(
            valueListenable: count,
            builder: (context, cartCount, _) {
              return Column(
                children: [
                  SafeArea(
                    bottom: false,
                    child: SizedBox(
                      height: 52,
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 11),
                        child: Row(
                          children: [
                            DetailHeaderButton(
                              onPressed: _popToServices,
                              label: 'Back',
                              child: const CustomPaint(
                                size: Size(20, 20),
                                painter: _BackPainter(),
                              ),
                            ),
                            const Spacer(),
                            DetailCartButton(
                              count: cartCount,
                              onPressed: _openCart,
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  Expanded(child: _bodyContent(keyboard)),
                ],
              );
            },
          ),
        ),
      ),
    );
  }

  Widget _bodyContent(bool keyboard) {
    return AnimatedSwitcher(
      duration: BlueMotion.of(context, BlueMotion.page),
      switchInCurve: BlueMotion.curve,
      child: switch (_body) {
        _DetailBody.loading => const DetailSkeleton(),
        _DetailBody.error => DetailFail(
          notFound: false,
          onRetry: _load,
          onBack: _popToServices,
        ),
        _DetailBody.notFound => DetailFail(
          notFound: true,
          onRetry: _load,
          onBack: _popToServices,
        ),
        _DetailBody.ready => _ready(keyboard),
      },
    );
  }

  Widget _ready(bool keyboard) {
    final detail = _detail!;
    final status = _status(detail);
    final missing = _missing(detail);
    final sticky = _sticky(detail, status, missing);
    return Stack(
      children: [
        Positioned.fill(
          child: ListView(
            controller: _scroll,
            physics: const BouncingScrollPhysics(
              parent: AlwaysScrollableScrollPhysics(),
            ),
            padding: EdgeInsets.fromLTRB(0, 0, 0, keyboard ? 24 : 118),
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: BlueDimens.homeGutter,
                ),
                child: DetailGallery(
                  media: detail.media,
                  page: _gallery,
                  controller: _page,
                  onPage: (index) => setState(() => _gallery = index),
                  fallbackName: detail.name,
                ),
              ),
              const SizedBox(height: 20),
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: BlueDimens.homeGutter,
                ),
                child: DetailTitle(
                  category: detail.category.name,
                  name: detail.name,
                  tagline: detail.shortDescription,
                  bestseller: detail.isBestseller,
                ),
              ),
              const SizedBox(height: 18),
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: BlueDimens.homeGutter,
                ),
                child: _price(detail, status, missing),
              ),
              const Padding(
                padding: EdgeInsets.symmetric(
                  horizontal: BlueDimens.homeGutter,
                ),
                child: DetailHairline(),
              ),
              if (detail.description.isNotEmpty) ...[
                const SizedBox(height: 20),
                Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: BlueDimens.homeGutter,
                  ),
                  child: DetailAbout(
                    description: detail.description,
                    expanded: _expanded,
                    onToggle: () => setState(() => _expanded = !_expanded),
                  ),
                ),
              ],
              ..._contentBlocks(detail),
              if (detail.options.isNotEmpty) ...[
                const Padding(
                  padding: EdgeInsets.symmetric(
                    horizontal: BlueDimens.homeGutter,
                  ),
                  child: DetailHairline(),
                ),
                const SizedBox(height: 20),
                Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: BlueDimens.homeGutter,
                  ),
                  child: DetailSectionHead(
                    title: 'Service details',
                    needed:
                        status == _UiStatus.invalid ||
                            (status == _UiStatus.context && missing.isNotEmpty)
                        ? _neededText(missing.length)
                        : null,
                    neededAlert: status == _UiStatus.invalid,
                    note: status == _UiStatus.quote
                        ? 'Used to brief the electrician before the visit.'
                        : (detail.isInspectionDeposit
                              ? 'Pay in the app to book this visit. The fee is deducted from any later repair.'
                              : 'Your answers set the price. Location and appointment come later, at checkout.'),
                  ),
                ),
                for (final option in detail.options) _option(option, missing),
              ],
              const SizedBox(height: 26),
              const Padding(
                padding: EdgeInsets.symmetric(
                  horizontal: BlueDimens.homeGutter,
                ),
                child: Text(
                  'Prices are confirmed by BLUE before payment. Location and appointment are chosen at checkout.',
                  style: TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 12,
                    height: 1.5,
                    fontWeight: FontWeight.w400,
                    color: BlueColors.placeholder,
                  ),
                ),
              ),
              if (_submitError != null) ...[
                const SizedBox(height: 10),
                Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: BlueDimens.homeGutter,
                  ),
                  child: Text(
                    _submitError!,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 12.5,
                      height: 1.45,
                      fontWeight: FontWeight.w600,
                      color: BlueColors.error,
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
        if (_toast)
          Positioned(
            left: 16,
            right: 16,
            bottom: keyboard ? 16 : 106,
            child: DetailToast(onViewCart: _openCart),
          ),
        if (!keyboard)
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: DetailStickyBar(
              price: sticky.price,
              caption: sticky.caption,
              cta: sticky.cta,
              enabled: sticky.enabled,
              priceKey: sticky.priceKey,
              unavailable: status == _UiStatus.unavailable,
              onCta: _onCta,
            ),
          ),
      ],
    );
  }

  Widget _price(ServiceDetail detail, _UiStatus status, List<String> missing) {
    final configured = status == _UiStatus.priced && missing.isEmpty;
    final money = detail.pricing.moneyLabel;
    return DetailPriceBlock(
      priced: status == _UiStatus.priced,
      priceText: configured ? money : (money.isEmpty ? '' : 'From $money'),
      priceKey: '$status-$money',
      moved: status == _UiStatus.priced && _moved,
      note: switch (status) {
        _UiStatus.priced =>
          _moved
              ? 'Updated for your selections. Confirmed at checkout.'
              : 'For the details below. Confirmed at checkout.',
        _UiStatus.quote =>
          'An electrician assesses the job on site, then BLUE sends you a fixed quote to approve. Nothing is charged before you accept.',
        _UiStatus.context =>
          "Tell us the details below and we'll show the price straight away.",
        _UiStatus.unavailable =>
          "This service isn't offered for your saved property.",
        _UiStatus.invalid =>
          'Complete the service details below to see your price.',
      },
      chipText: switch (status) {
        _UiStatus.quote => 'Quote required',
        _UiStatus.context || _UiStatus.invalid => 'Price after details',
        _UiStatus.unavailable => 'Unavailable',
        _UiStatus.priced => null,
      },
      chipColor: status == _UiStatus.unavailable
          ? BlueColors.unavailableInk
          : BlueColors.chipInk,
      chipFill: status == _UiStatus.unavailable
          ? BlueColors.unavailableSurface
          : BlueColors.chipSurface,
      chipBorder: status == _UiStatus.unavailable
          ? BlueColors.unavailableLine
          : BlueColors.border,
    );
  }

  ({String price, String caption, String cta, bool enabled, String priceKey})
  _sticky(ServiceDetail detail, _UiStatus status, List<String> missing) {
    final money = detail.pricing.moneyLabel;
    final price = switch (status) {
      _UiStatus.priced => money,
      _UiStatus.quote => 'Quote required',
      _UiStatus.context || _UiStatus.invalid => 'Price after details',
      _UiStatus.unavailable => 'Unavailable',
    };
    final caption = switch (status) {
      _UiStatus.priced => _unitCaption(detail),
      _UiStatus.quote => 'Priced after the site visit',
      _UiStatus.context =>
        missing.isEmpty ? 'Confirmed at checkout' : _neededText(missing.length),
      _UiStatus.unavailable => 'Not available for this property',
      _UiStatus.invalid => _neededText(missing.length),
    };
    final cta = switch (status) {
      _UiStatus.unavailable => 'Unavailable',
      _UiStatus.quote => 'Request quote',
      _ => 'Add to cart',
    };
    final enabled =
        status == _UiStatus.priced ||
        status == _UiStatus.quote ||
        (status == _UiStatus.context && missing.isEmpty);
    return (
      price: price,
      caption: caption,
      cta: cta,
      enabled: enabled && !_adding,
      priceKey: '$status-$price-${_unitCaption(detail)}',
    );
  }

  List<Widget> _contentBlocks(ServiceDetail detail) {
    final out = <Widget>[];
    final inspections = detail.contentSections
        .where((section) => section.type == 'INSPECTION_POLICY')
        .toList();
    final stats = detail.contentSections
        .where((section) => section.type == 'OVERVIEW_STAT')
        .toList();
    final copy = detail.contentSections
        .where(
          (section) =>
              section.type == 'INFO' ||
              section.type == 'RECOMMENDED_FOR' ||
              section.type == 'WHATS_INCLUDED' ||
              section.type == 'UX_HINT',
        )
        .toList();

    for (final section in inspections) {
      out.add(const SizedBox(height: 16));
      out.add(
        Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: BlueDimens.homeGutter,
          ),
          child: DetailInspectionBanner(
            title: section.title,
            body: section.body ?? '',
          ),
        ),
      );
    }
    if (stats.isNotEmpty) {
      out.add(const SizedBox(height: 16));
      out.add(
        Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: BlueDimens.homeGutter,
          ),
          child: DetailOverviewStats(stats: stats),
        ),
      );
    }
    for (final section in copy) {
      if ((section.body ?? '').isEmpty) continue;
      out.add(const SizedBox(height: 16));
      out.add(
        Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: BlueDimens.homeGutter,
          ),
          child: DetailInfoSection(title: section.title, body: section.body!),
        ),
      );
    }
    if (detail.checkpointCategories.isNotEmpty) {
      out.add(const SizedBox(height: 16));
      out.add(
        Padding(
          padding: const EdgeInsets.symmetric(
            horizontal: BlueDimens.homeGutter,
          ),
          child: DetailCheckpointList(categories: detail.checkpointCategories),
        ),
      );
    }
    return out;
  }

  Widget _option(ServiceOption option, List<String> missing) {
    final bad =
        option.isRequired &&
        missing.contains(option.uuid) &&
        _touched.contains(option.uuid);
    final help = option.description.trim();
    return Padding(
      key: _key(option.uuid),
      padding: const EdgeInsets.fromLTRB(
        BlueDimens.homeGutter,
        22,
        BlueDimens.homeGutter,
        0,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          DetailOptionLabel(
            label: option.name,
            requiredLabel: option.isRequired ? 'Required' : 'Optional',
            alert: bad,
            help: help.isEmpty ? null : help,
          ),
          const SizedBox(height: 11),
          switch (option.kind) {
            ServiceOptionKind.singleSelect => _singleSelect(option, bad),
            ServiceOptionKind.multiSelect => _multiSelect(option, bad),
            ServiceOptionKind.boolean => DetailBoolPair(
              value: _boolean[option.uuid],
              error: bad,
              onChanged: (value) {
                _touched.add(option.uuid);
                _boolean[option.uuid] = value;
                _onChanged();
              },
            ),
            ServiceOptionKind.number => _numberField(option, bad),
            ServiceOptionKind.text => _textField(option, bad),
          },
          if (bad) DetailOptionError(message: _requiredMessage(option)),
        ],
      ),
    );
  }

  Widget _singleSelect(ServiceOption option, bool bad) {
    final packages = option.choices.any((choice) => choice.isPackageCard);
    if (packages) {
      return Column(
        children: [
          for (var i = 0; i < option.choices.length; i++) ...[
            if (i > 0) const SizedBox(height: 10),
            DetailPackageCard(
              choice: option.choices[i],
              selected: _single[option.uuid] == option.choices[i].uuid,
              error: bad,
              onPressed: () {
                _touched.add(option.uuid);
                _single[option.uuid] = option.choices[i].uuid;
                _onChanged();
              },
            ),
          ],
        ],
      );
    }
    return Wrap(
      spacing: 9,
      runSpacing: 9,
      children: [
        for (final choice in option.choices)
          DetailChoiceTile(
            label: choice.name,
            selected: _single[option.uuid] == choice.uuid,
            error: bad,
            onPressed: () {
              _touched.add(option.uuid);
              _single[option.uuid] = choice.uuid;
              _onChanged();
            },
          ),
      ],
    );
  }

  Widget _multiSelect(ServiceOption option, bool bad) {
    final selected = _multi.putIfAbsent(option.uuid, () => <String>{});
    return Column(
      children: [
        for (var i = 0; i < option.choices.length; i++) ...[
          if (i > 0) const SizedBox(height: 9),
          DetailCheckRow(
            label: option.choices[i].name,
            selected: selected.contains(option.choices[i].uuid),
            error: bad,
            onPressed: () {
              _touched.add(option.uuid);
              if (selected.contains(option.choices[i].uuid)) {
                selected.remove(option.choices[i].uuid);
              } else {
                selected.add(option.choices[i].uuid);
              }
              _onChanged();
            },
          ),
        ],
      ],
    );
  }

  Widget _numberField(ServiceOption option, bool bad) {
    final controller = _controller(option);
    final decimals = option.numeric?.decimalPlaces ?? 0;
    if (decimals == 0) {
      final min = option.numeric?.min?.round() ?? 0;
      final max = option.numeric?.max?.round() ?? 20;
      final parsed = int.tryParse(controller.text.trim().split('.').first);
      final value = parsed ?? min;
      var unit = option.numeric?.displayUnit ?? 'unit';
      if (value == 1) {
        if (unit.endsWith('s') && unit.length > 1) {
          unit = unit.substring(0, unit.length - 1);
        }
      } else if (unit == 'unit') {
        unit = 'units';
      } else if (!unit.endsWith('s')) {
        unit = '${unit}s';
      }
      return DetailQuantityStepper(
        value: value.clamp(min, max),
        min: min,
        max: max,
        unitLabel: unit,
        error: bad,
        onChanged: (next) {
          _touched.add(option.uuid);
          controller.text = '$next';
          _onChanged();
        },
      );
    }
    final node = _node(option.uuid);
    final raw = controller.text.trim();
    final count = int.tryParse(raw.split('.').first);
    var unit = option.numeric?.displayUnit ?? '';
    if (unit.isEmpty) unit = 'unit';
    if (count != 1) {
      if (unit == 'unit') unit = 'units';
    } else if (unit.endsWith('s') && unit.length > 1) {
      unit = unit.substring(0, unit.length - 1);
    }
    return DetailFieldShell(
      focused: node.hasFocus,
      error: bad,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Row(
          children: [
            Expanded(
              child: TextField(
                controller: controller,
                focusNode: node,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                inputFormatters: [
                  LatinDigits.formatter,
                  FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
                ],
                cursorColor: BlueColors.ink,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 15.5,
                  fontWeight: FontWeight.w600,
                  color: BlueColors.ink,
                ),
                decoration: InputDecoration(
                  isCollapsed: true,
                  border: InputBorder.none,
                  hintText: option.numeric?.formatDefault().isEmpty == true
                      ? '1'
                      : option.numeric?.formatDefault(),
                  hintStyle: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 15.5,
                    fontWeight: FontWeight.w600,
                    color: BlueColors.placeholder,
                  ),
                ),
                onTap: () => _touched.add(option.uuid),
                onChanged: (_) {
                  _touched.add(option.uuid);
                  _onChanged();
                },
              ),
            ),
            const SizedBox(width: 10),
            Text(
              unit,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: BlueColors.muted,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _textField(ServiceOption option, bool bad) {
    final controller = _controller(option);
    final node = _node(option.uuid);
    return DetailFieldShell(
      focused: node.hasFocus,
      error: bad,
      minHeight: 92,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 13, 16, 13),
        child: TextField(
          controller: controller,
          focusNode: node,
          minLines: 3,
          maxLines: 6,
          inputFormatters: const [LatinDigits.formatter],
          cursorColor: BlueColors.ink,
          style: const TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 15,
            height: 1.5,
            fontWeight: FontWeight.w500,
            color: BlueColors.ink,
          ),
          decoration: const InputDecoration(
            isCollapsed: true,
            border: InputBorder.none,
            hintText: 'Enter details…',
            hintStyle: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 15,
              height: 1.5,
              fontWeight: FontWeight.w500,
              color: BlueColors.placeholder,
            ),
          ),
          onTap: () => _touched.add(option.uuid),
          onChanged: (_) {
            _touched.add(option.uuid);
            _onChanged();
          },
        ),
      ),
    );
  }
}

class _BackPainter extends CustomPainter {
  const _BackPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.ink
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.1
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawLine(Offset(19 * sx, 12 * sy), Offset(5 * sx, 12 * sy), paint);
    final path = Path()
      ..moveTo(11 * sx, 18 * sy)
      ..lineTo(5 * sx, 12 * sy)
      ..lineTo(11 * sx, 6 * sy);
    canvas.drawPath(path, paint);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
