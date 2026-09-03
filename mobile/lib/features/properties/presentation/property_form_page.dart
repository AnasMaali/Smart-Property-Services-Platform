import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/data/reference_data.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../auth/presentation/widgets/blue_outlined_field.dart';
import '../../checkout/presentation/widgets/checkout_location_widgets.dart';
import '../data/property_models.dart';
import 'widgets/properties_widgets.dart';

enum _PropField { type, other, relation, city, area }

class PropertyFormPage extends StatefulWidget {
  const PropertyFormPage({super.key, this.property, this.draft});

  final SavedProperty? property;
  final PropertyDraft? draft;

  @override
  State<PropertyFormPage> createState() => _PropertyFormPageState();
}

class _PropertyFormPageState extends State<PropertyFormPage> {
  final _other = TextEditingController();
  final _building = TextEditingController();
  final _floor = TextEditingController();
  final _unit = TextEditingController();
  final _otherFocus = FocusNode();
  final _buildingFocus = FocusNode();
  final _floorFocus = FocusNode();
  final _unitFocus = FocusNode();

  final _typeKey = GlobalKey();
  final _otherKey = GlobalKey();
  final _relationKey = GlobalKey();
  final _cityKey = GlobalKey();
  final _areaKey = GlobalKey();

  List<RefCity> _cities = const [];
  List<RefItem> _types = const [];
  List<RefItem> _relations = const [];
  RefCity? _city;
  RefItem? _area;
  RefItem? _type;
  RefItem? _relation;
  bool _loading = true;
  bool _saving = false;
  bool _removing = false;
  bool _submitted = false;
  bool _areaCleared = false;
  String? _bootError;
  String? _saveError;
  String? _openSheet;

  bool get _editing => widget.property != null;

  bool get _otherNeeded => _type?.code == 'OTHER';

  bool get _chipsFit {
    if (_types.length > 8) return false;
    return _types.every((item) => item.name.length <= 16);
  }

  @override
  void initState() {
    super.initState();
    _hydrateDraft(widget.draft);
    WidgetsBinding.instance.addPostFrameCallback((_) => _boot());
  }

  @override
  void dispose() {
    _other.dispose();
    _building.dispose();
    _floor.dispose();
    _unit.dispose();
    _otherFocus.dispose();
    _buildingFocus.dispose();
    _floorFocus.dispose();
    _unitFocus.dispose();
    super.dispose();
  }

  void _hydrateDraft(PropertyDraft? draft) {
    if (draft == null) return;
    _other.text = draft.other;
    _building.text = draft.building;
    _floor.text = draft.floor;
    _unit.text = draft.unit;
  }

  PropertyDraft _snapshot() {
    return PropertyDraft(
      propertyTypeId: _type?.id,
      relationshipId: _relation?.id,
      cityId: _city?.id,
      areaId: _area?.id,
      other: _other.text,
      building: _building.text,
      floor: _floor.text,
      unit: _unit.text,
    );
  }

  Future<void> _boot() async {
    setState(() {
      _loading = true;
      _bootError = null;
    });
    try {
      final refs = await AppScope.of(context).referenceData.load();
      if (!mounted) return;
      final draft = widget.draft;
      final property = widget.property;
      var resolved = draft;
      if (resolved == null && property != null) {
        resolved = PropertyDraft.fromProperty(
          property,
          refs.cities,
          refs.propertyTypes,
          refs.propertyRelationships,
        );
        _hydrateDraft(resolved);
      }
      RefCity? city;
      RefItem? area;
      RefItem? type;
      RefItem? relation;
      if (resolved != null) {
        for (final item in refs.cities) {
          if (item.id == resolved.cityId) city = item;
        }
        if (city != null) {
          for (final item in city.areas) {
            if (item.id == resolved.areaId) area = item;
          }
        }
        for (final item in refs.propertyTypes) {
          if (item.id == resolved.propertyTypeId) type = item;
        }
        for (final item in refs.propertyRelationships) {
          if (item.id == resolved.relationshipId) relation = item;
        }
      }
      setState(() {
        _cities = refs.cities;
        _types = refs.propertyTypes;
        _relations = refs.propertyRelationships;
        _city = city;
        _area = area;
        _type = type;
        _relation = relation;
        _loading = false;
      });
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _bootError = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _bootError = "Can't load property options. Try again.";
      });
    }
  }

  void _onBack() {
    if (_saving || _removing) return;
    Navigator.of(
      context,
    ).pop(PropertyFormPop(saved: false, draft: _snapshot()));
  }

  String? _errorFor(_PropField field) {
    if (!_submitted) return null;
    switch (field) {
      case _PropField.type:
        return _type == null ? 'Choose a property type.' : null;
      case _PropField.other:
        if (!_otherNeeded) return null;
        return _other.text.trim().length < 2
            ? 'Tell us what kind of property this is.'
            : null;
      case _PropField.relation:
        return _relation == null
            ? 'Tell us how this property relates to you.'
            : null;
      case _PropField.city:
        return _city == null ? 'Select a city.' : null;
      case _PropField.area:
        if (_city == null) return null;
        return _area == null ? 'Select an area.' : null;
    }
  }

  Future<void> _reveal(_PropField field) async {
    final key = switch (field) {
      _PropField.type => _typeKey,
      _PropField.other => _otherKey,
      _PropField.relation => _relationKey,
      _PropField.city => _cityKey,
      _PropField.area => _areaKey,
    };
    final ctx = key.currentContext;
    if (ctx != null) {
      await Scrollable.ensureVisible(
        ctx,
        duration: BlueMotion.snap,
        curve: BlueMotion.curve,
        alignment: 0.12,
      );
    }
    if (field == _PropField.other) _otherFocus.requestFocus();
  }

  PropertyWrite _writePayload() {
    final type = _type!;
    final area = _area!;
    final city = _city!;
    final relation = _relation!;
    final identity = type.code == 'OTHER'
        ? _other.text.trim()
        : type.name.trim();
    final label = identity.length >= 2 ? identity : 'Property';
    final areaName = area.name.trim();
    final cityName = city.name.trim();
    final building = _building.text.trim();
    final storedBuilding = building.isEmpty ? '-' : building;
    var address = building.isNotEmpty ? building : '$areaName, $cityName';
    if (address.trim().length < 5) address = 'Saved property';
    var street = areaName;
    if (street.length < 2) street = cityName;
    if (street.length < 2) street = 'Address';
    final phone =
        AppScope.of(context).auth.current?.phoneNumber.trim() ??
        AppScope.of(context).profile.cached?.phoneNumber.trim() ??
        '';
    final visitPhone = phone.length >= 8 ? phone : '+971500000000';
    final floor = _floor.text.trim();
    final unit = _unit.text.trim();
    return PropertyWrite(
      label: label,
      relationshipTypeId: relation.id,
      propertyTypeId: type.id,
      otherPropertyTypeName: type.code == 'OTHER' ? _other.text.trim() : null,
      areaId: area.id,
      streetName: street,
      addressLine: address,
      building: storedBuilding,
      floorNumber: floor.isEmpty ? null : floor,
      unitNumber: unit.isEmpty ? null : unit,
      visitPhone: visitPhone,
    );
  }

  Future<void> _save() async {
    if (_saving || _removing) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _submitted = true;
      _saveError = null;
    });
    for (final field in _PropField.values) {
      if (_errorFor(field) != null) {
        await _reveal(field);
        return;
      }
    }
    setState(() => _saving = true);
    BlueMotion.tap();
    try {
      final repo = AppScope.of(context).properties;
      final existing = widget.property;
      final SavedProperty saved;
      if (existing == null) {
        saved = await repo.create(_writePayload());
      } else {
        saved = await repo.update(existing.uuid, _writePayload());
      }
      if (!mounted) return;
      Navigator.of(
        context,
      ).pop(PropertyFormPop(saved: true, savedUuid: saved.uuid));
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _saveError = error.statusCode >= 500 || error.isNetwork
            ? "We couldn't save this property. Everything you entered is still here — try again."
            : error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _saveError =
            "We couldn't save this property. Everything you entered is still here — try again.";
      });
    }
  }

  Future<void> _remove() async {
    if (_saving || _removing) return;
    final existing = widget.property;
    if (existing == null) return;
    final confirmed = await confirmPropertyRemove(context);
    if (!confirmed || !mounted) return;
    BlueMotion.warn();
    setState(() => _removing = true);
    try {
      await AppScope.of(context).properties.remove(existing.uuid);
      if (!mounted) return;
      Navigator.of(
        context,
      ).pop(PropertyFormPop(saved: true, savedUuid: existing.uuid));
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() => _removing = false);
      if (error.statusCode == 409) {
        await showPropertyRemoveBlocked(context, reason: error.displayMessage);
        return;
      }
      setState(() {
        _saveError = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _removing = false;
        _saveError =
            "We couldn't save this property. Everything you entered is still here — try again.";
      });
    }
  }

  void _setType(RefItem item) {
    setState(() {
      _type = item;
      if (item.code != 'OTHER') _other.clear();
    });
  }

  Future<void> _pickRelation() async {
    setState(() => _openSheet = 'relation');
    final picked = await showPropertyChoiceSheet<RefItem>(
      context: context,
      title: 'Your relationship to it',
      items: _relations,
      labelOf: (item) => propertyRelationshipLabel(item.code, item.name),
      selected: (item) => _relation?.id == item.id,
    );
    if (!mounted) return;
    setState(() => _openSheet = null);
    if (picked == null) return;
    setState(() => _relation = picked);
  }

  Future<void> _pickCity() async {
    setState(() => _openSheet = 'city');
    final picked = await showLocationSearchSheet<RefCity>(
      context: context,
      title: 'Select city',
      searchHint: 'Search cities',
      items: _cities,
      labelOf: (item) => item.name,
      selected: (item) => _city?.id == item.id,
    );
    if (!mounted) return;
    setState(() => _openSheet = null);
    if (picked == null) return;
    setState(() {
      final changed = _city?.id != picked.id;
      _city = picked;
      if (changed && _area != null) {
        _area = null;
        _areaCleared = true;
      }
    });
  }

  Future<void> _pickArea() async {
    final city = _city;
    if (city == null) {
      await _pickCity();
      return;
    }
    if (city.areas.isEmpty) return;
    setState(() => _openSheet = 'area');
    final picked = await showLocationSearchSheet<RefItem>(
      context: context,
      title: 'Select area',
      searchHint: 'Search areas in ${city.name}',
      items: city.areas,
      labelOf: (item) => item.name,
      selected: (item) => _area?.id == item.id,
    );
    if (!mounted) return;
    setState(() => _openSheet = null);
    if (picked == null) return;
    setState(() {
      _area = picked;
      _areaCleared = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final keyboard = MediaQuery.viewInsetsOf(context).bottom;
    final keyboardOpen = keyboard > 80;
    final cta = _saving
        ? 'Saving...'
        : (_editing ? 'Save changes' : 'Save property');
    final subtitle = _editing
        ? 'Changes apply to this saved property only.'
        : 'Save a place you book services for.';

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (didPop) return;
        _onBack();
      },
      child: ColoredBox(
        color: BlueColors.canvas,
        child: SafeArea(
          bottom: false,
          child: BlueEnter(
            duration: BlueMotion.rise,
            offset: const Offset(0, 0.018),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SizedBox(
                  height: 52,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                      horizontal: BlueDimens.checkoutGutter,
                    ),
                    child: Align(
                      alignment: Alignment.centerLeft,
                      child: PropertiesBackButton(onPressed: _onBack),
                    ),
                  ),
                ),
                Expanded(
                  child: ListView(
                    keyboardDismissBehavior:
                        ScrollViewKeyboardDismissBehavior.onDrag,
                    physics: const BouncingScrollPhysics(
                      parent: AlwaysScrollableScrollPhysics(),
                    ),
                    padding: EdgeInsets.fromLTRB(
                      BlueDimens.checkoutGutter,
                      6,
                      BlueDimens.checkoutGutter,
                      keyboardOpen ? keyboard + 24 : 28,
                    ),
                    children: [
                      PropertiesTitle(
                        title: _editing ? 'Edit property' : 'Add property',
                        subtitle: subtitle,
                      ),
                      const SizedBox(height: 22),
                      if (_loading)
                        const Padding(
                          padding: EdgeInsets.only(top: 40),
                          child: Center(
                            child: SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.2,
                                color: BlueColors.ink,
                              ),
                            ),
                          ),
                        )
                      else if (_bootError != null)
                        _BootError(message: _bootError!, onRetry: _boot)
                      else ...[
                        _propertyGroup(),
                        const Padding(
                          padding: EdgeInsets.symmetric(vertical: 22),
                          child: Divider(
                            height: 1,
                            thickness: 1,
                            color: BlueColors.navLine,
                          ),
                        ),
                        _whereGroup(),
                        const Padding(
                          padding: EdgeInsets.symmetric(vertical: 22),
                          child: Divider(
                            height: 1,
                            thickness: 1,
                            color: BlueColors.navLine,
                          ),
                        ),
                        _apartGroup(),
                        if (_editing) ...[
                          const Padding(
                            padding: EdgeInsets.symmetric(vertical: 22),
                            child: Divider(
                              height: 1,
                              thickness: 1,
                              color: BlueColors.navLine,
                            ),
                          ),
                          PropertiesRemoveLink(onPressed: _remove),
                        ],
                      ],
                    ],
                  ),
                ),
                if (!keyboardOpen && !_loading && _bootError == null)
                  LocationSaveBar(
                    label: cta,
                    busy: _saving || _removing,
                    onPressed: _save,
                    failure: _saveError,
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _propertyGroup() {
    final typeError = _errorFor(_PropField.type);
    final otherError = _errorFor(_PropField.other);
    final relationError = _errorFor(_PropField.relation);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PropertiesSectionHead(title: 'Property'),
        const SizedBox(height: 16),
        KeyedSubtree(
          key: _typeKey,
          child: LocationFieldLabel(
            label: 'Property type',
            required: true,
            error: typeError != null,
          ),
        ),
        const SizedBox(height: 10),
        if (_types.length <= 4)
          Row(
            children: [
              for (var i = 0; i < _types.length; i++) ...[
                if (i > 0) const SizedBox(width: 8),
                Expanded(
                  child: LocationTypeChip(
                    label: _types[i].name,
                    selected: _type?.id == _types[i].id,
                    error: typeError != null,
                    onPressed: () => _setType(_types[i]),
                  ),
                ),
              ],
            ],
          )
        else if (_chipsFit)
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final item in _types)
                LocationTypeChip(
                  label: item.name,
                  selected: _type?.id == item.id,
                  error: typeError != null,
                  onPressed: () => _setType(item),
                ),
            ],
          )
        else
          LocationPickerField(
            value: _type?.name ?? 'Select property type',
            placeholder: _type == null,
            error: typeError != null,
            onPressed: _saving
                ? null
                : () async {
                    final picked = await showLocationSearchSheet<RefItem>(
                      context: context,
                      title: 'Select property type',
                      searchHint: 'Search property types',
                      items: _types,
                      labelOf: (item) => item.name,
                      selected: (item) => _type?.id == item.id,
                    );
                    if (picked == null || !mounted) return;
                    _setType(picked);
                  },
          ),
        if (typeError != null) LocationFieldError(message: typeError),
        AnimatedSize(
          duration: BlueMotion.of(context, locationOtherReveal),
          curve: BlueMotion.curve,
          alignment: Alignment.topCenter,
          child: _otherNeeded
              ? TweenAnimationBuilder<double>(
                  key: _otherKey,
                  tween: Tween(begin: 0, end: 1),
                  duration: BlueMotion.of(context, locationOtherReveal),
                  curve: BlueMotion.curve,
                  builder: (context, t, child) {
                    return Opacity(
                      opacity: t,
                      child: Transform.translate(
                        offset: Offset(0, 4 * (1 - t)),
                        child: child,
                      ),
                    );
                  },
                  child: Padding(
                    padding: const EdgeInsets.only(top: 16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        LocationFieldLabel(
                          label: 'What kind of property?',
                          required: true,
                          error: otherError != null,
                        ),
                        const SizedBox(height: 10),
                        BlueOutlinedField(
                          controller: _other,
                          focusNode: _otherFocus,
                          hint: 'Staff accommodation, warehouse…',
                          error: otherError != null,
                          enabled: true,
                          onChanged: (_) {},
                          textCapitalization: TextCapitalization.sentences,
                          textInputAction: TextInputAction.next,
                          inputFormatters: [
                            LengthLimitingTextInputFormatter(120),
                          ],
                        ),
                        if (otherError != null)
                          LocationFieldError(message: otherError),
                      ],
                    ),
                  ),
                )
              : const SizedBox.shrink(),
        ),
        const SizedBox(height: 16),
        KeyedSubtree(
          key: _relationKey,
          child: LocationFieldLabel(
            label: 'Your relationship to it',
            required: true,
            error: relationError != null,
          ),
        ),
        const SizedBox(height: 10),
        LocationPickerField(
          value: _relation == null
              ? 'Select relationship'
              : propertyRelationshipLabel(_relation!.code, _relation!.name),
          placeholder: _relation == null,
          error: relationError != null,
          expanded: _openSheet == 'relation',
          onPressed: _saving ? null : _pickRelation,
        ),
        if (relationError != null) LocationFieldError(message: relationError),
      ],
    );
  }

  Widget _whereGroup() {
    final cityError = _errorFor(_PropField.city);
    final areaError = _errorFor(_PropField.area);
    final areaLocked = _city == null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PropertiesSectionHead(title: 'Where it is'),
        const SizedBox(height: 16),
        KeyedSubtree(
          key: _cityKey,
          child: LocationFieldLabel(
            label: 'City',
            required: true,
            error: cityError != null,
          ),
        ),
        const SizedBox(height: 10),
        LocationPickerField(
          value: _city?.name ?? 'Select city',
          placeholder: _city == null,
          error: cityError != null,
          expanded: _openSheet == 'city',
          onPressed: _saving ? null : _pickCity,
        ),
        if (cityError != null) LocationFieldError(message: cityError),
        const SizedBox(height: 16),
        KeyedSubtree(
          key: _areaKey,
          child: LocationFieldLabel(
            label: 'Area',
            required: true,
            error: areaError != null,
            dimmed: areaLocked,
          ),
        ),
        const SizedBox(height: 10),
        AnimatedSwitcher(
          duration: BlueMotion.of(context, locationAreaFade),
          child: LocationPickerField(
            key: ValueKey(_area?.id ?? (_city?.id ?? 0)),
            value: areaLocked
                ? 'Select city first'
                : (_area?.name ?? 'Select area'),
            placeholder: _area == null,
            locked: areaLocked,
            error: areaError != null,
            expanded: _openSheet == 'area',
            onPressed: _saving ? null : _pickArea,
          ),
        ),
        if (areaLocked)
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text(
              'Areas depend on the city.',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.4,
                fontWeight: FontWeight.w500,
                color: BlueColors.muted,
              ),
            ),
          )
        else if (_areaCleared && _area == null)
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text(
              'Area cleared — areas depend on the city you just chose.',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.4,
                fontWeight: FontWeight.w500,
                color: BlueColors.muted,
              ),
            ),
          )
        else if (areaError != null)
          LocationFieldError(message: areaError),
      ],
    );
  }

  Widget _apartGroup() {
    final stack = MediaQuery.textScalerOf(context).scale(14) > 18;
    final floorField = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const LocationFieldLabel(label: 'Floor', required: false),
        const SizedBox(height: 10),
        BlueOutlinedField(
          controller: _floor,
          focusNode: _floorFocus,
          hint: '12',
          error: false,
          enabled: true,
          onChanged: (_) {},
          textInputAction: TextInputAction.next,
          textCapitalization: TextCapitalization.sentences,
          inputFormatters: [LengthLimitingTextInputFormatter(30)],
        ),
      ],
    );
    final unitField = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const LocationFieldLabel(label: 'Unit', required: false),
        const SizedBox(height: 10),
        BlueOutlinedField(
          controller: _unit,
          focusNode: _unitFocus,
          hint: '1204',
          error: false,
          enabled: true,
          onChanged: (_) {},
          textInputAction: TextInputAction.done,
          textCapitalization: TextCapitalization.sentences,
          inputFormatters: [LengthLimitingTextInputFormatter(50)],
        ),
      ],
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const PropertiesSectionHead(
          title: 'Help us tell it apart',
          optional: true,
        ),
        const SizedBox(height: 16),
        const LocationFieldLabel(
          label: 'Building or villa name',
          required: false,
        ),
        const SizedBox(height: 10),
        BlueOutlinedField(
          controller: _building,
          focusNode: _buildingFocus,
          hint: 'Building or villa name',
          error: false,
          enabled: true,
          onChanged: (_) {},
          textCapitalization: TextCapitalization.words,
          textInputAction: TextInputAction.next,
          inputFormatters: [LengthLimitingTextInputFormatter(120)],
        ),
        const SizedBox(height: 16),
        if (stack) ...[
          floorField,
          const SizedBox(height: 16),
          unitField,
        ] else
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(child: floorField),
              const SizedBox(width: 12),
              Expanded(child: unitField),
            ],
          ),
        const SizedBox(height: 10),
        const Text(
          'Only used to recognise this property in your list and to help the team find it.',
          style: TextStyle(
            fontFamily: BlueFonts.jakarta,
            fontSize: 12.5,
            height: 1.4,
            fontWeight: FontWeight.w500,
            color: BlueColors.muted,
          ),
        ),
      ],
    );
  }
}

class _BootError extends StatelessWidget {
  const _BootError({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 28),
      child: Column(
        children: [
          Text(
            message,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 14,
              height: 1.45,
              fontWeight: FontWeight.w500,
              color: BlueColors.muted,
            ),
          ),
          const SizedBox(height: 16),
          LocationSaveButton(
            label: 'Try again',
            busy: false,
            onPressed: onRetry,
          ),
        ],
      ),
    );
  }
}
