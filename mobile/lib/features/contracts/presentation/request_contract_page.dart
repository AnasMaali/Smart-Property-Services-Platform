import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../checkout/presentation/widgets/checkout_location_widgets.dart';
import '../../home/data/catalog_service.dart';
import '../../properties/data/property_models.dart';
import '../../properties/presentation/my_properties_page.dart';
import '../../home/presentation/widgets/home_icons.dart';
import '../../support/presentation/widgets/support_widgets.dart';
import 'widgets/contract_detail_widgets.dart';
import 'widgets/contracts_widgets.dart';

class RequestContractPage extends StatefulWidget {
  const RequestContractPage({super.key});

  @override
  State<RequestContractPage> createState() => _RequestContractPageState();
}

class _RequestContractPageState extends State<RequestContractPage> {
  final _note = TextEditingController();
  final _noteFocus = FocusNode();

  List<SavedProperty> _properties = const [];
  List<CatalogService> _services = const [];
  SavedProperty? _property;
  bool _allServices = true;
  final _selectedServiceUuids = <String>{};
  DateTime? _startDate;
  bool _loading = true;
  bool _loadingServices = false;
  String? _loadError;
  bool _busy = false;
  bool _toast = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _note.dispose();
    _noteFocus.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });
    try {
      final scope = AppScope.of(context);
      final properties = await scope.properties.list();
      final active = properties.where((row) => row.isActive).toList();
      if (!mounted) return;
      setState(() {
        _properties = active;
        _property = active.length == 1 ? active.first : _property;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _loadError = 'Could not load your properties.';
      });
    }
  }

  Future<void> _loadServices() async {
    if (_services.isNotEmpty || _loadingServices) return;
    setState(() => _loadingServices = true);
    try {
      final result = await AppScope.of(context).catalog.search(
        capability: 'SUBSCRIPTION',
      );
      if (!mounted) return;
      setState(() {
        _services = result.services
            .where(
              (row) =>
                  row.enabled && row.capabilities.contains('SUBSCRIPTION'),
            )
            .toList();
        _loadingServices = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loadingServices = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Could not load services.')),
      );
    }
  }

  String _propertyLabel(SavedProperty property) {
    final label = property.label.trim();
    if (label.isNotEmpty) return label;
    return property.identity;
  }

  String _propertySubtitle(SavedProperty property) {
    final parts = <String>[];
    final place = property.placeLine.trim();
    if (place.isNotEmpty) parts.add(place);
    final detail = property.detailLine.trim();
    if (detail.isNotEmpty) parts.add(detail);
    return parts.join(' · ');
  }

  Future<void> _pickProperty() async {
    if (_properties.isEmpty || _busy) return;
    final picked = await showLocationSearchSheet<SavedProperty>(
      context: context,
      title: 'Select property',
      searchHint: 'Search saved properties',
      items: _properties,
      labelOf: (property) {
        final subtitle = _propertySubtitle(property);
        if (subtitle.isEmpty) return _propertyLabel(property);
        return '${_propertyLabel(property)} · $subtitle';
      },
      selected: (property) => property.uuid == _property?.uuid,
    );
    if (!mounted || picked == null) return;
    setState(() => _property = picked);
  }

  Future<void> _pickStartDate() async {
    if (_busy) return;
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final picked = await showDatePicker(
      context: context,
      initialDate: _startDate ?? today,
      firstDate: today,
      lastDate: today.add(const Duration(days: 365)),
      builder: (context, child) {
        return Theme(
          data: Theme.of(context).copyWith(
            colorScheme: const ColorScheme.light(
              primary: BlueColors.ink,
              onPrimary: BlueColors.white,
              surface: BlueColors.white,
              onSurface: BlueColors.ink,
            ),
          ),
          child: child ?? const SizedBox.shrink(),
        );
      },
    );
    if (!mounted) return;
    setState(() => _startDate = picked);
  }

  void _setAllServices(bool value) {
    setState(() {
      _allServices = value;
      if (value) {
        _selectedServiceUuids.clear();
      } else {
        _loadServices();
      }
    });
  }

  void _toggleService(String uuid) {
    setState(() {
      if (_selectedServiceUuids.contains(uuid)) {
        _selectedServiceUuids.remove(uuid);
      } else {
        _selectedServiceUuids.add(uuid);
      }
    });
  }

  bool get _canSubmit {
    if (_busy || _property == null) return false;
    if (!_allServices && _selectedServiceUuids.isEmpty) return false;
    final note = _note.text.trim();
    if (note.isNotEmpty && note.length < 2) return false;
    return true;
  }

  String? _formatApiDate(DateTime? date) {
    if (date == null) return null;
    final y = date.year.toString().padLeft(4, '0');
    final m = date.month.toString().padLeft(2, '0');
    final d = date.day.toString().padLeft(2, '0');
    return '$y-$m-$d';
  }

  String _displayDate(DateTime date) {
    const months = [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];
    return '${months[date.month - 1]} ${date.day}, ${date.year}';
  }

  Future<void> _submit() async {
    if (!_canSubmit || _property == null) return;
    setState(() => _busy = true);
    try {
      final created = await AppScope.of(context).contracts.request(
        propertyUuid: _property!.uuid,
        allServices: _allServices,
        serviceUuids: _allServices ? null : _selectedServiceUuids.toList(),
        desiredStartDate: _formatApiDate(_startDate),
        customerNote: _note.text.trim().isEmpty ? null : _note.text.trim(),
      );
      if (!mounted) return;
      setState(() => _toast = true);
      await Future<void>.delayed(supportToastHold);
      if (!mounted) return;
      Navigator.of(context).pop(created);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _busy = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(error.message)),
      );
    } catch (_) {
      if (!mounted) return;
      setState(() => _busy = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Failed to send request. Please try again.')),
      );
    }
  }

  Future<void> _openAddProperty() async {
    await Navigator.of(context).push<void>(
      BluePageRoute(builder: (_) => const MyPropertiesPage()),
    );
    if (!mounted) return;
    await _load();
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
                      padding: EdgeInsets.fromLTRB(
                        BlueDimens.homeGutter,
                        2,
                        BlueDimens.homeGutter,
                        0,
                      ),
                      child: ContractDetailBackButton(
                        onPressed: _busy
                            ? () {}
                            : () => Navigator.of(context).pop(),
                      ),
                    ),
                    Expanded(
                      child: _loading
                          ? const Center(
                              child: CircularProgressIndicator(
                                color: BlueColors.ink,
                              ),
                            )
                          : _loadError != null
                          ? Center(
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Text(_loadError!),
                                  const SizedBox(height: 12),
                                  TextButton(
                                    onPressed: _load,
                                    child: const Text('Retry'),
                                  ),
                                ],
                              ),
                            )
                          : ListView(
                              keyboardDismissBehavior:
                                  ScrollViewKeyboardDismissBehavior.onDrag,
                              physics: const BouncingScrollPhysics(
                                parent: AlwaysScrollableScrollPhysics(),
                              ),
                              padding: EdgeInsets.fromLTRB(gutter, 6, gutter, 34),
                              children: [
                                const SupportTitle(
                                  title: 'Request a contract',
                                  subtitle:
                                      'Tell us where and what you need covered. We will send a quote to review.',
                                  gold: true,
                                ),
                                const SizedBox(height: 32),
                                const SupportFieldLabel('Property'),
                                const SizedBox(height: 8),
                                if (_properties.isEmpty) ...[
                                  const SupportHelper(
                                    'Add a saved property before requesting a contract.',
                                  ),
                                  const SizedBox(height: 12),
                                  ContractsInkButton(
                                    label: 'Add a property',
                                    height: 48,
                                    horizontal: 22,
                                    onPressed: _busy ? () {} : _openAddProperty,
                                  ),
                                ] else ...[
                                  _PickerField(
                                    label: _property == null
                                        ? 'Select property'
                                        : _propertyLabel(_property!),
                                    subtitle: _property == null
                                        ? ''
                                        : _propertySubtitle(_property!),
                                    enabled: !_busy,
                                    onPressed: _pickProperty,
                                  ),
                                ],
                                const SizedBox(height: 24),
                                const SupportFieldLabel('Coverage'),
                                const SizedBox(height: 10),
                                Wrap(
                                  spacing: 8,
                                  runSpacing: 8,
                                  children: [
                                    _CoverageChip(
                                      label: 'All eligible services',
                                      selected: _allServices,
                                      enabled: !_busy,
                                      onPressed: () => _setAllServices(true),
                                    ),
                                    _CoverageChip(
                                      label: 'Choose services',
                                      selected: !_allServices,
                                      enabled: !_busy,
                                      onPressed: () => _setAllServices(false),
                                    ),
                                  ],
                                ),
                                if (!_allServices) ...[
                                  const SizedBox(height: 14),
                                  if (_loadingServices)
                                    const Padding(
                                      padding: EdgeInsets.symmetric(vertical: 12),
                                      child: Center(
                                        child: CircularProgressIndicator(
                                          color: BlueColors.ink,
                                          strokeWidth: 2,
                                        ),
                                      ),
                                    )
                                  else if (_services.isEmpty)
                                    const SupportHelper(
                                      'No services are available to select right now.',
                                    )
                                  else
                                    for (final service in _services)
                                      _ServiceToggleRow(
                                        name: service.name,
                                        description: service.shortDescription,
                                        selected: _selectedServiceUuids.contains(
                                          service.uuid,
                                        ),
                                        enabled: !_busy,
                                        onPressed: () => _toggleService(
                                          service.uuid,
                                        ),
                                      ),
                                ],
                                const SizedBox(height: 24),
                                const SupportFieldLabel(
                                  'Preferred start date (optional)',
                                ),
                                const SizedBox(height: 8),
                                _PickerField(
                                  label: _startDate == null
                                      ? 'No preference'
                                      : _displayDate(_startDate!),
                                  subtitle: _startDate == null
                                      ? 'Optional — we will agree dates in your quote.'
                                      : 'Tap to change or clear.',
                                  enabled: !_busy,
                                  trailing: _startDate == null
                                      ? null
                                      : _ClearDateAction(
                                          onPressed: () =>
                                              setState(() => _startDate = null),
                                        ),
                                  onPressed: _pickStartDate,
                                ),
                                const SizedBox(height: 20),
                                const SupportFieldLabel('Anything else? (optional)'),
                                const SizedBox(height: 8),
                                SupportOutlinedField(
                                  key: const Key('contract-request-note'),
                                  controller: _note,
                                  focusNode: _noteFocus,
                                  hint:
                                      'Visit frequency, access notes, or services you have in mind.',
                                  enabled: !_busy,
                                  minLines: 4,
                                  maxLines: 8,
                                  onChanged: (_) => setState(() {}),
                                ),
                                const SizedBox(height: 8),
                                const SupportHelper(
                                  'We review every request and reply with a quote you can accept in the app.',
                                ),
                              ],
                            ),
                    ),
                    if (!_loading && _loadError == null)
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
                            key: const Key('contract-request-submit'),
                            enabled: _canSubmit,
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

class _PickerField extends StatefulWidget {
  const _PickerField({
    required this.label,
    required this.subtitle,
    required this.enabled,
    required this.onPressed,
    this.trailing,
  });

  final String label;
  final String subtitle;
  final bool enabled;
  final VoidCallback onPressed;
  final Widget? trailing;

  @override
  State<_PickerField> createState() => _PickerFieldState();
}

class _PickerFieldState extends State<_PickerField> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    final muted = widget.label == 'Select property' ||
        widget.label == 'No preference';
    return GestureDetector(
      onTapDown: widget.enabled ? (_) => setState(() => _down = true) : null,
      onTapUp: widget.enabled ? (_) => setState(() => _down = false) : null,
      onTapCancel: widget.enabled ? () => setState(() => _down = false) : null,
      onTap: widget.enabled
          ? () {
              BlueMotion.tap();
              widget.onPressed();
            }
          : null,
      child: AnimatedContainer(
        duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
        curve: BlueMotion.curve,
        width: double.infinity,
        padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
        decoration: BoxDecoration(
          color: _down ? BlueColors.selectPress : BlueColors.white,
          borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
          border: Border.all(color: BlueColors.border),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    widget.label,
                    style: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15,
                      height: 1.3,
                      fontWeight: FontWeight.w600,
                      color: muted ? BlueColors.placeholder : BlueColors.ink,
                    ),
                  ),
                  if (widget.subtitle.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      widget.subtitle,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 12.5,
                        height: 1.45,
                        fontWeight: FontWeight.w400,
                        color: BlueColors.muted,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            if (widget.trailing != null) widget.trailing!,
            const BlueGlyphIcon(
              BlueGlyph.chevronRight,
              size: 17,
              color: BlueColors.rowChevron,
              strokeWidth: 2.1,
            ),
          ],
        ),
      ),
    );
  }
}

class _ClearDateAction extends StatelessWidget {
  const _ClearDateAction({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: BluePressable(
        onPressed: onPressed,
        scale: 0.94,
        child: const Text(
          'Clear',
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13,
            fontWeight: FontWeight.w700,
            color: BlueColors.chipInk,
          ),
        ),
      ),
    );
  }
}

class _CoverageChip extends StatelessWidget {
  const _CoverageChip({
    required this.label,
    required this.selected,
    required this.enabled,
    required this.onPressed,
  });

  final String label;
  final bool selected;
  final bool enabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      enabled: enabled,
      onPressed: enabled ? onPressed : null,
      scale: 0.96,
      child: AnimatedContainer(
        duration: BlueMotion.snap,
        curve: BlueMotion.curve,
        constraints: const BoxConstraints(minHeight: 38),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? BlueColors.ink : BlueColors.white,
          borderRadius: BorderRadius.circular(999),
          border: Border.all(
            color: selected ? BlueColors.ink : BlueColors.border,
          ),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13.5,
            height: 1.2,
            fontWeight: FontWeight.w600,
            letterSpacing: 13.5 * 0.005,
            color: selected ? BlueColors.white : BlueColors.ink,
          ),
        ),
      ),
    );
  }
}

class _ServiceToggleRow extends StatefulWidget {
  const _ServiceToggleRow({
    required this.name,
    required this.description,
    required this.selected,
    required this.enabled,
    required this.onPressed,
  });

  final String name;
  final String description;
  final bool selected;
  final bool enabled;
  final VoidCallback onPressed;

  @override
  State<_ServiceToggleRow> createState() => _ServiceToggleRowState();
}

class _ServiceToggleRowState extends State<_ServiceToggleRow> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: widget.enabled ? (_) => setState(() => _down = true) : null,
      onTapUp: widget.enabled ? (_) => setState(() => _down = false) : null,
      onTapCancel: widget.enabled ? () => setState(() => _down = false) : null,
      onTap: widget.enabled
          ? () {
              BlueMotion.tap();
              widget.onPressed();
            }
          : null,
      child: AnimatedContainer(
        duration: BlueMotion.of(context, const Duration(milliseconds: 160)),
        curve: BlueMotion.curve,
        width: double.infinity,
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
        decoration: BoxDecoration(
          color: _down
              ? BlueColors.selectPress
              : (widget.selected ? BlueColors.chipSurface : BlueColors.white),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: widget.selected ? BlueColors.ink : BlueColors.border,
          ),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    widget.name,
                    style: const TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 14.5,
                      height: 1.3,
                      fontWeight: FontWeight.w700,
                      color: BlueColors.ink,
                    ),
                  ),
                  if (widget.description.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                      widget.description,
                      style: const TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 12.5,
                        height: 1.45,
                        fontWeight: FontWeight.w400,
                        color: BlueColors.muted,
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(width: 10),
            AnimatedContainer(
              duration: BlueMotion.snap,
              width: 22,
              height: 22,
              decoration: BoxDecoration(
                color: widget.selected ? BlueColors.ink : BlueColors.white,
                borderRadius: BorderRadius.circular(7),
                border: Border.all(
                  color: widget.selected ? BlueColors.ink : BlueColors.border,
                ),
              ),
              child: widget.selected
                  ? const Center(
                      child: BlueGlyphIcon(
                        BlueGlyph.check,
                        size: 12,
                        color: BlueColors.white,
                        strokeWidth: 2.4,
                      ),
                    )
                  : null,
            ),
          ],
        ),
      ),
    );
  }
}
