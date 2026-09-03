import 'package:flutter/material.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/input/latin_digits.dart';
import '../data/reference_data.dart';
import 'widgets/blue_back_button.dart';
import 'widgets/blue_brand.dart';
import 'widgets/blue_chevron.dart';
import 'widgets/blue_field_label.dart';
import 'widgets/blue_motion.dart';
import 'widgets/blue_primary_button.dart';
import 'widgets/blue_sheet.dart';
import 'widgets/blue_step_progress.dart';
import 'widgets/error_hint.dart';

class SignupProperty {
  const SignupProperty({
    required this.cityId,
    required this.areaId,
    required this.relationId,
    required this.interestIds,
    required this.city,
    required this.area,
  });

  final int cityId;
  final int areaId;
  final int relationId;
  final List<int> interestIds;
  final String city;
  final String area;
}

class SignupPropertyPage extends StatefulWidget {
  const SignupPropertyPage({
    super.key,
    required this.onContinue,
    required this.onBack,
    this.submitting = false,
    this.submitError,
  });

  final ValueChanged<SignupProperty> onContinue;
  final VoidCallback onBack;
  final bool submitting;
  final String? submitError;

  @override
  State<SignupPropertyPage> createState() => _SignupPropertyPageState();
}

class _SignupPropertyPageState extends State<SignupPropertyPage> {
  List<RefCity> _cities = const [];
  List<RefItem> _relations = const [];
  List<RefItem> _interests = const [];
  RefCity? _city;
  RefItem? _area;
  RefItem? _relation;
  final List<RefItem> _picks = [];
  String? _sheet;
  bool _sheetOpen = false;
  String _query = '';
  bool _areaReset = false;
  bool _loading = true;
  String? _loadError;
  String? _formError;
  final _search = TextEditingController();
  final _searchFocus = FocusNode();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _load();
    });
  }

  @override
  void dispose() {
    _search.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });
    try {
      final data = await AppScope.of(context).referenceData.load();
      if (!mounted) return;
      setState(() {
        _cities = data.cities;
        _relations = data.propertyRelationships;
        _interests = data.serviceCategories;
        _loading = false;
      });
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _loadError = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _loadError = "Can't load cities and services. Try again.";
      });
    }
  }

  bool get _ready =>
      _city != null && _area != null && _relation != null && _picks.isNotEmpty;

  String _relationshipLabel(RefItem item) {
    switch (item.code) {
      case 'PROPERTY_OWNER':
      case 'OWNER':
        return 'Owner';
      case 'TENANT':
        return 'Tenant';
      case 'PROPERTY_MANAGER':
      case 'MANAGER':
        return 'Property manager';
      default:
        return item.name;
    }
  }

  List<RefItem> get _shownRelations {
    const preferred = {
      'PROPERTY_OWNER',
      'OWNER',
      'TENANT',
      'PROPERTY_MANAGER',
      'MANAGER',
    };
    final matched = _relations
        .where((item) => preferred.contains(item.code))
        .toList();
    return matched.isNotEmpty ? matched : _relations;
  }

  void _openSheet(String sheet) {
    _search.clear();
    _searchFocus.unfocus();
    setState(() {
      _sheet = sheet;
      _query = '';
      _sheetOpen = false;
      if (sheet == 'city') _areaReset = false;
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || _sheet != sheet) return;
      setState(() => _sheetOpen = true);
    });
  }

  void _closeSheet() {
    if (!_sheetOpen && _sheet == null) return;
    _searchFocus.unfocus();
    setState(() => _sheetOpen = false);
    Future<void>.delayed(BlueMotion.sheetOut, () {
      if (!mounted || _sheetOpen) return;
      setState(() {
        _sheet = null;
        _query = '';
      });
    });
  }

  void _pickCity(RefCity city) {
    final stillValid =
        _area != null && city.areas.any((area) => area.id == _area!.id);
    setState(() {
      _city = city;
      _areaReset = _area != null && !stillValid;
      if (!stillValid) _area = null;
    });
    _search.clear();
    _closeSheet();
  }

  void _toggleInterest(RefItem item) {
    setState(() {
      final index = _picks.indexWhere((pick) => pick.id == item.id);
      if (index >= 0) {
        _picks.removeAt(index);
      } else {
        _picks.add(item);
      }
      _formError = null;
    });
  }

  void _submit() {
    if (!_ready || _city == null || _area == null || _relation == null) {
      return;
    }
    widget.onContinue(
      SignupProperty(
        cityId: _city!.id,
        areaId: _area!.id,
        relationId: _relation!.id,
        interestIds: _picks.map((item) => item.id).toList(),
        city: _city!.name,
        area: _area!.name,
      ),
    );
  }

  List<RefItem> get _shownInterests {
    final rest = _interests.where(
      (item) => !_picks.any((pick) => pick.id == item.id),
    );
    return [..._picks, ...rest].take(5).toList();
  }

  List<RefItem> get _sheetSource {
    if (_sheet == 'city') {
      return [
        for (final city in _cities)
          RefItem(id: city.id, name: city.name, code: city.code),
      ];
    }
    if (_sheet == 'area') return _city?.areas ?? const [];
    return _interests;
  }

  List<RefItem> get _filtered {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty || _sheet == 'interests') return _sheetSource;
    return _sheetSource
        .where((item) => item.name.toLowerCase().contains(q))
        .toList();
  }

  bool _optionOn(RefItem item) {
    if (_sheet == 'city') return _city?.id == item.id;
    if (_sheet == 'area') return _area?.id == item.id;
    return _picks.any((pick) => pick.id == item.id);
  }

  void _pickOption(RefItem item) {
    if (_sheet == 'city') {
      final city = _cities.firstWhere((city) => city.id == item.id);
      _pickCity(city);
    } else if (_sheet == 'area') {
      setState(() {
        _area = item;
        _areaReset = false;
      });
      _search.clear();
      _closeSheet();
    } else {
      _toggleInterest(item);
    }
  }

  @override
  Widget build(BuildContext context) {
    final hidden = _interests.length - _shownInterests.length;
    final cityName = _city?.name ?? '';
    final areaName = _area?.name ?? '';

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        if (_sheet != null) {
          _closeSheet();
        } else {
          widget.onBack();
        }
      },
      child: ColoredBox(
        color: BlueColors.canvas,
        child: Stack(
          children: [
            SafeArea(
              child: LayoutBuilder(
                builder: (context, constraints) {
                  return SingleChildScrollView(
                    child: ConstrainedBox(
                      constraints: BoxConstraints(
                        minHeight: constraints.maxHeight,
                      ),
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(24, 26, 24, 30),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Stack(
                              alignment: Alignment.topCenter,
                              children: [
                                const BlueBrand(compact: true),
                                Align(
                                  alignment: Alignment.topLeft,
                                  child: BlueBackButton(
                                    onPressed: widget.onBack,
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 20),
                            const BlueStepProgress(
                              step: 2,
                              total: 3,
                              label: 'Step 2 of 3',
                              title: 'Your property',
                            ),
                            const SizedBox(height: 16),
                            const Text(
                              "Where's your property?",
                              style: TextStyle(
                                fontFamily: BlueFonts.jakarta,
                                fontSize: 30,
                                height: 1.15,
                                fontWeight: FontWeight.w700,
                                letterSpacing: 30 * -0.025,
                                color: BlueColors.ink,
                              ),
                            ),
                            const SizedBox(height: 6),
                            const SizedBox(
                              width: 300,
                              child: Text(
                                'So we only show services available where you live.',
                                style: TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 14.5,
                                  height: 1.5,
                                  fontWeight: FontWeight.w400,
                                  color: BlueColors.muted,
                                ),
                              ),
                            ),
                            const SizedBox(height: 18),
                            if (_loadError != null) ...[
                              BlueErrorHint(message: _loadError!),
                              const SizedBox(height: 12),
                              BluePrimaryButton(
                                label: 'Try again',
                                onPressed: _load,
                              ),
                              const SizedBox(height: 18),
                            ],
                            const BlueFieldLabel('City'),
                            const SizedBox(height: 8),
                            _SelectField(
                              label: cityName.isEmpty
                                  ? (_loading
                                        ? 'Loading cities…'
                                        : 'Select city')
                                  : cityName,
                              placeholder: cityName.isEmpty,
                              locked: _loading || _loadError != null,
                              expanded: _sheet == 'city' && _sheetOpen,
                              onPressed: _loading || _loadError != null
                                  ? null
                                  : () => _openSheet('city'),
                            ),
                            const SizedBox(height: 14),
                            Row(
                              crossAxisAlignment: CrossAxisAlignment.baseline,
                              textBaseline: TextBaseline.alphabetic,
                              children: [
                                const Expanded(child: BlueFieldLabel('Area')),
                                if (_city == null)
                                  const Text(
                                    'Choose a city first',
                                    style: TextStyle(
                                      fontFamily: BlueFonts.jakarta,
                                      fontSize: 12,
                                      fontWeight: FontWeight.w500,
                                      color: BlueColors.muted,
                                    ),
                                  ),
                              ],
                            ),
                            const SizedBox(height: 8),
                            _SelectField(
                              label: areaName.isNotEmpty
                                  ? areaName
                                  : (_city == null
                                        ? 'Select area'
                                        : 'Select area in $cityName'),
                              placeholder: areaName.isEmpty,
                              locked: _city == null,
                              expanded: _sheet == 'area' && _sheetOpen,
                              onPressed: _city == null
                                  ? null
                                  : () => _openSheet('area'),
                            ),
                            if (_areaReset && _city != null) ...[
                              const SizedBox(height: 7),
                              Text(
                                'City changed — pick an area in $cityName.',
                                style: const TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 12.5,
                                  height: 1.4,
                                  fontWeight: FontWeight.w500,
                                  color: BlueColors.muted,
                                ),
                              ),
                            ],
                            const SizedBox(height: 16),
                            const BlueFieldLabel('Your relationship to it'),
                            const SizedBox(height: 10),
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final item in _shownRelations)
                                  _ChoiceChip(
                                    label: _relationshipLabel(item),
                                    selected: _relation?.id == item.id,
                                    onPressed: () => setState(() {
                                      _relation = item;
                                      _formError = null;
                                    }),
                                  ),
                              ],
                            ),
                            const SizedBox(height: 16),
                            const Row(
                              children: [
                                Expanded(
                                  child: BlueFieldLabel(
                                    'What can we help with?',
                                  ),
                                ),
                                _OptionalBadge(),
                              ],
                            ),
                            const SizedBox(height: 10),
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                for (final item in _shownInterests)
                                  _ChoiceChip(
                                    label: item.name,
                                    selected: _picks.any(
                                      (pick) => pick.id == item.id,
                                    ),
                                    onPressed: () => _toggleInterest(item),
                                  ),
                                _MoreChip(
                                  label: hidden > 0
                                      ? '+$hidden more'
                                      : 'All services',
                                  onPressed: () => _openSheet('interests'),
                                ),
                              ],
                            ),
                            if (_formError != null ||
                                widget.submitError != null) ...[
                              const SizedBox(height: 12),
                              BlueErrorHint(
                                message: widget.submitError ?? _formError!,
                              ),
                            ],
                            const SizedBox(height: 18),
                            BluePrimaryButton(
                              label: widget.submitting
                                  ? 'Sending code…'
                                  : 'Continue',
                              busy: widget.submitting,
                              enabled: _ready || widget.submitting,
                              onPressed: _submit,
                            ),
                            const SizedBox(height: 16),
                            const Center(
                              child: Text(
                                'You can change any of this later in your profile.',
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                  fontFamily: BlueFonts.jakarta,
                                  fontSize: 12,
                                  height: 1.45,
                                  fontWeight: FontWeight.w400,
                                  color: BlueColors.muted,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  );
                },
              ),
            ),
            if (_sheet != null) _buildSheet(),
          ],
        ),
      ),
    );
  }

  Widget _buildSheet() {
    final isInterests = _sheet == 'interests';
    final title = _sheet == 'city'
        ? 'Select city'
        : (_sheet == 'area' ? 'Select area' : 'What can we help with?');
    final options = _filtered;

    return BlueHostedSheet(
      open: _sheetOpen,
      onDismiss: _closeSheet,
      child: BlueSheetPanel(
        title: title,
        onClose: _closeSheet,
        header: isInterests
            ? null
            : Padding(
                padding: const EdgeInsets.fromLTRB(20, 6, 20, 12),
                child: Container(
                  height: 50,
                  padding: const EdgeInsets.symmetric(horizontal: 14),
                  decoration: BoxDecoration(
                    color: BlueColors.canvas,
                    borderRadius: BorderRadius.circular(16),
                    border: Border.all(color: BlueColors.border),
                  ),
                  child: Row(
                    children: [
                      const CustomPaint(
                        size: Size(16, 16),
                        painter: _SearchPainter(),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: TextField(
                          controller: _search,
                          focusNode: _searchFocus,
                          onChanged: (v) => setState(() => _query = v),
                          inputFormatters: const [LatinDigits.formatter],
                          cursorColor: BlueColors.ink,
                          style: const TextStyle(
                            fontFamily: BlueFonts.jakarta,
                            fontSize: 15.5,
                            fontWeight: FontWeight.w500,
                            color: BlueColors.ink,
                          ),
                          decoration: InputDecoration(
                            isCollapsed: true,
                            border: InputBorder.none,
                            hintText: _sheet == 'city'
                                ? 'Search cities'
                                : 'Search areas',
                            hintStyle: const TextStyle(
                              fontFamily: BlueFonts.jakarta,
                              fontSize: 15.5,
                              fontWeight: FontWeight.w500,
                              color: BlueColors.placeholder,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
        footer: isInterests
            ? Padding(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                child: DecoratedBox(
                  decoration: const BoxDecoration(
                    border: Border(
                      top: BorderSide(color: BlueColors.sheetHairline),
                    ),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: BluePressable(
                      onPressed: _closeSheet,
                      child: AnimatedContainer(
                        duration: BlueMotion.snap,
                        width: double.infinity,
                        height: 54,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: BlueColors.ink,
                          borderRadius: BorderRadius.circular(18),
                        ),
                        child: AnimatedSwitcher(
                          duration: BlueMotion.tick,
                          child: Text(
                            _picks.isEmpty
                                ? 'Done'
                                : 'Done · ${_picks.length} selected',
                            key: ValueKey(_picks.length),
                            style: const TextStyle(
                              fontFamily: BlueFonts.jakarta,
                              fontSize: 16,
                              fontWeight: FontWeight.w700,
                              color: BlueColors.white,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              )
            : null,
        child: ListView(
          padding: EdgeInsets.fromLTRB(12, isInterests ? 6 : 0, 12, 8),
          shrinkWrap: true,
          children: [
            for (var i = 0; i < options.length; i++)
              BlueSheetRow(
                index: i,
                label: options[i].name,
                selected: _optionOn(options[i]),
                onPressed: () => _pickOption(options[i]),
              ),
            if (options.isEmpty)
              Padding(
                padding: const EdgeInsets.fromLTRB(12, 22, 12, 0),
                child: Text(
                  'No match for "$_query". Check the spelling or pick from the list.',
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 14,
                    height: 1.5,
                    fontWeight: FontWeight.w400,
                    color: BlueColors.muted,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _SelectField extends StatefulWidget {
  const _SelectField({
    required this.label,
    required this.placeholder,
    required this.onPressed,
    this.locked = false,
    this.expanded = false,
  });

  final String label;
  final bool placeholder;
  final bool locked;
  final bool expanded;
  final VoidCallback? onPressed;

  @override
  State<_SelectField> createState() => _SelectFieldState();
}

class _SelectFieldState extends State<_SelectField> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTapDown: widget.locked ? null : (_) => setState(() => _down = true),
      onTapUp: widget.locked ? null : (_) => setState(() => _down = false),
      onTapCancel: widget.locked ? null : () => setState(() => _down = false),
      onTap: widget.locked
          ? null
          : () {
              BlueMotion.tap();
              widget.onPressed?.call();
            },
      child: AnimatedScale(
        scale: _down ? 0.985 : 1,
        duration: BlueMotion.press,
        curve: Curves.easeOut,
        child: Opacity(
          opacity: widget.locked ? 0.72 : 1,
          child: AnimatedContainer(
            duration: BlueMotion.snap,
            curve: BlueMotion.curve,
            height: BlueDimens.fieldHeight,
            padding: const EdgeInsets.symmetric(horizontal: 17),
            decoration: BoxDecoration(
              color: widget.locked
                  ? BlueColors.areaLocked
                  : (_down || widget.expanded
                        ? BlueColors.selectPress
                        : BlueColors.white),
              borderRadius: BorderRadius.circular(BlueDimens.fieldRadius),
              border: Border.all(
                color: widget.expanded ? BlueColors.ink : BlueColors.border,
              ),
            ),
            child: Row(
              children: [
                Expanded(
                  child: AnimatedSwitcher(
                    duration: BlueMotion.tick,
                    child: Text(
                      widget.label,
                      key: ValueKey(widget.label),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontFamily: BlueFonts.jakarta,
                        fontSize: 16.5,
                        fontWeight: FontWeight.w500,
                        color: widget.placeholder
                            ? BlueColors.placeholder
                            : BlueColors.ink,
                      ),
                    ),
                  ),
                ),
                BlueChevron(
                  size: 13,
                  strokeWidth: 2.4,
                  expanded: widget.expanded,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _OptionalBadge extends StatelessWidget {
  const _OptionalBadge();

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: BlueColors.white,
        borderRadius: BorderRadius.circular(6),
        border: Border.all(color: BlueColors.badgeBorder),
      ),
      child: const Padding(
        padding: EdgeInsets.fromLTRB(7, 3, 7, 3),
        child: Text(
          'OPTIONAL',
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 9.5,
            height: 1.2,
            fontWeight: FontWeight.w600,
            letterSpacing: 0.7,
            color: BlueColors.placeholder,
          ),
        ),
      ),
    );
  }
}

class _ChoiceChip extends StatelessWidget {
  const _ChoiceChip({
    required this.label,
    required this.selected,
    required this.onPressed,
  });

  final String label;
  final bool selected;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return BluePressable(
      onPressed: onPressed,
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
        child: AnimatedDefaultTextStyle(
          duration: BlueMotion.snap,
          curve: BlueMotion.curve,
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 13.5,
            height: 1.2,
            fontWeight: FontWeight.w600,
            letterSpacing: 13.5 * 0.005,
            color: selected ? BlueColors.white : BlueColors.ink,
          ),
          child: Text(label, textAlign: TextAlign.center),
        ),
      ),
    );
  }
}

class _MoreChip extends StatefulWidget {
  const _MoreChip({required this.label, required this.onPressed});

  final String label;
  final VoidCallback onPressed;

  @override
  State<_MoreChip> createState() => _MoreChipState();
}

class _MoreChipState extends State<_MoreChip> {
  bool _down = false;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTapDown: (_) => setState(() => _down = true),
      onTapUp: (_) => setState(() => _down = false),
      onTapCancel: () => setState(() => _down = false),
      onTap: () {
        BlueMotion.tap();
        widget.onPressed();
      },
      child: AnimatedScale(
        scale: _down ? 0.96 : 1,
        duration: BlueMotion.press,
        curve: Curves.easeOut,
        child: CustomPaint(
          painter: _DashedRRectPainter(
            color: BlueColors.dash,
            radius: 999,
            fill: _down ? BlueColors.press : Colors.transparent,
          ),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 38),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              child: Center(
                child: Text(
                  widget.label,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 13.5,
                    height: 1.2,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 13.5 * 0.005,
                    color: BlueColors.ink,
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _DashedRRectPainter extends CustomPainter {
  const _DashedRRectPainter({
    required this.color,
    required this.radius,
    required this.fill,
  });

  final Color color;
  final double radius;
  final Color fill;

  @override
  void paint(Canvas canvas, Size size) {
    final pill = (size.shortestSide / 2).clamp(0.0, radius);
    final rrect = RRect.fromLTRBR(
      0.5,
      0.5,
      size.width - 0.5,
      size.height - 0.5,
      Radius.circular(pill),
    );
    if (fill.a > 0) {
      canvas.drawRRect(rrect, Paint()..color = fill);
    }
    final paint = Paint()
      ..color = color
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1;
    final path = Path()..addRRect(rrect);
    for (final metric in path.computeMetrics()) {
      var distance = 0.0;
      while (distance < metric.length) {
        final next = (distance + 4).clamp(0.0, metric.length);
        canvas.drawPath(metric.extractPath(distance, next), paint);
        distance += 8;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _DashedRRectPainter oldDelegate) =>
      oldDelegate.fill != fill;
}

class _SearchPainter extends CustomPainter {
  const _SearchPainter();
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.chevron
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.2 * (size.width / 24)
      ..strokeCap = StrokeCap.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawCircle(Offset(11 * sx, 11 * sy), 6.5 * sx, paint);
    canvas.drawLine(
      Offset(16 * sx, 16 * sy),
      Offset(20.5 * sx, 20.5 * sy),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
