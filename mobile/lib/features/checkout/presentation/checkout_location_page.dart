import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../app/app_scope.dart';
import '../../../app/theme/blue_theme.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/input/latin_digits.dart';
import '../../auth/data/reference_data.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../../auth/presentation/widgets/blue_outlined_field.dart';
import '../../auth/presentation/widgets/blue_phone_field.dart';
import '../data/checkout_models.dart';
import '../data/checkout_repository.dart';
import 'widgets/checkout_location_widgets.dart';
import 'widgets/checkout_widgets.dart';

class CheckoutLocationDraft {
  const CheckoutLocationDraft({
    this.propertyTypeId,
    this.cityId,
    this.areaId,
    this.other = '',
    this.street = '',
    this.address = '',
    this.building = '',
    this.floor = '',
    this.unit = '',
    this.landmark = '',
    this.notes = '',
    this.phone = '',
  });

  final int? propertyTypeId;
  final int? cityId;
  final int? areaId;
  final String other;
  final String street;
  final String address;
  final String building;
  final String floor;
  final String unit;
  final String landmark;
  final String notes;
  final String phone;
}

class CheckoutLocationPage extends StatefulWidget {
  const CheckoutLocationPage({super.key, this.location, this.draft});

  final CheckoutLocation? location;
  final CheckoutLocationDraft? draft;

  @override
  State<CheckoutLocationPage> createState() => _CheckoutLocationPageState();
}

enum _LocField {
  type,
  other,
  city,
  area,
  street,
  address,
  building,
  landmark,
  notes,
  phone,
}

class _CheckoutLocationPageState extends State<CheckoutLocationPage> {
  final _street = TextEditingController();
  final _address = TextEditingController();
  final _building = TextEditingController();
  final _floor = TextEditingController();
  final _unit = TextEditingController();
  final _phone = TextEditingController();
  final _other = TextEditingController();
  final _landmark = TextEditingController();
  final _notes = TextEditingController();

  final _streetFocus = FocusNode();
  final _addressFocus = FocusNode();
  final _buildingFocus = FocusNode();
  final _floorFocus = FocusNode();
  final _unitFocus = FocusNode();
  final _phoneFocus = FocusNode();
  final _otherFocus = FocusNode();
  final _landmarkFocus = FocusNode();
  final _notesFocus = FocusNode();

  final _typeKey = GlobalKey();
  final _otherKey = GlobalKey();
  final _cityKey = GlobalKey();
  final _areaKey = GlobalKey();
  final _streetKey = GlobalKey();
  final _addressKey = GlobalKey();
  final _buildingKey = GlobalKey();
  final _phoneKey = GlobalKey();

  List<RefCity> _cities = const [];
  List<RefItem> _types = const [];
  RefCity? _city;
  RefItem? _area;
  RefItem? _type;
  bool _loading = true;
  bool _saving = false;
  bool _submitted = false;
  String? _bootError;
  String? _saveError;
  String? _areaLoadError;
  String? _openSheet;
  final _touched = <_LocField>{};

  bool get _editing => widget.location != null;

  bool get _otherNeeded => _type?.code == 'OTHER';

  bool get _chipsFit {
    if (_types.length > 8) return false;
    return _types.every((item) => item.name.length <= 16);
  }

  @override
  void initState() {
    super.initState();
    _hydrateText(widget.draft, widget.location);
    _streetFocus.addListener(() => _onBlur(_streetFocus, _LocField.street));
    _addressFocus.addListener(() => _onBlur(_addressFocus, _LocField.address));
    _buildingFocus.addListener(
      () => _onBlur(_buildingFocus, _LocField.building),
    );
    _otherFocus.addListener(() => _onBlur(_otherFocus, _LocField.other));
    _landmarkFocus.addListener(
      () => _onBlur(_landmarkFocus, _LocField.landmark),
    );
    _notesFocus.addListener(() => _onBlur(_notesFocus, _LocField.notes));
    _phoneFocus.addListener(() => _onBlur(_phoneFocus, _LocField.phone));
    WidgetsBinding.instance.addPostFrameCallback((_) => _boot());
  }

  @override
  void dispose() {
    _street.dispose();
    _address.dispose();
    _building.dispose();
    _floor.dispose();
    _unit.dispose();
    _phone.dispose();
    _other.dispose();
    _landmark.dispose();
    _notes.dispose();
    _streetFocus.dispose();
    _addressFocus.dispose();
    _buildingFocus.dispose();
    _floorFocus.dispose();
    _unitFocus.dispose();
    _phoneFocus.dispose();
    _otherFocus.dispose();
    _landmarkFocus.dispose();
    _notesFocus.dispose();
    super.dispose();
  }

  void _hydrateText(CheckoutLocationDraft? draft, CheckoutLocation? location) {
    if (draft != null) {
      _other.text = draft.other;
      _street.text = draft.street;
      _address.text = draft.address;
      _building.text = draft.building;
      _floor.text = draft.floor;
      _unit.text = draft.unit;
      _landmark.text = draft.landmark;
      _notes.text = draft.notes;
      _phone.text = UaePhone.format(_phoneDigits(draft.phone));
      return;
    }
    if (location == null) return;
    _street.text = location.streetName;
    _address.text = location.addressLine;
    _building.text = location.building;
    _floor.text = location.floorNumber ?? '';
    _unit.text = location.unitNumber ?? '';
    _landmark.text = location.nearbyLandmark ?? '';
    _notes.text = location.notes ?? '';
    _phone.text = UaePhone.format(_phoneDigits(location.visitPhone));
    _other.text = location.otherPropertyTypeName ?? '';
  }

  static String _phoneDigits(String raw) {
    var digits = LatinDigits.only(raw);
    if (digits.startsWith('971') && digits.length > 9) {
      digits = digits.substring(3);
    }
    if (digits.startsWith('0') && digits.length > 9) {
      digits = digits.substring(1);
    }
    return digits;
  }

  void _onBlur(FocusNode node, _LocField field) {
    if (node.hasFocus) return;
    if (!_touched.contains(field) &&
        _controllerFor(field).text.trim().isEmpty) {
      return;
    }
    setState(() => _touched.add(field));
  }

  TextEditingController _controllerFor(_LocField field) {
    return switch (field) {
      _LocField.other => _other,
      _LocField.street => _street,
      _LocField.address => _address,
      _LocField.building => _building,
      _LocField.landmark => _landmark,
      _LocField.notes => _notes,
      _LocField.phone => _phone,
      _LocField.type || _LocField.city || _LocField.area => _street,
    };
  }

  Future<void> _boot() async {
    setState(() {
      _loading = true;
      _bootError = null;
    });
    try {
      final refs = await AppScope.of(context).referenceData.load();
      if (!mounted) return;
      _applyRefs(refs);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _bootError = "We couldn't load location options. Try again.";
      });
    }
  }

  void _applyRefs(RegistrationReferenceData refs) {
    final draft = widget.draft;
    final location = widget.location;
    RefCity? city;
    RefItem? area;
    RefItem? type;

    final typeId = draft?.propertyTypeId ?? location?.propertyType.id;
    final cityId = draft?.cityId ?? location?.city.id;
    final cityName = location?.city.name;
    final areaId = draft?.areaId ?? location?.area.id;
    final areaName = location?.area.name;
    final typeCode = location?.propertyType.code;

    if (typeId != null) {
      for (final item in refs.propertyTypes) {
        if (item.id == typeId || item.code == typeCode) {
          type = item;
          break;
        }
      }
    }
    if (cityId != null) {
      for (final item in refs.cities) {
        if (item.id == cityId || item.name == cityName) {
          city = item;
          break;
        }
      }
    }
    if (city != null && areaId != null) {
      for (final item in city.areas) {
        if (item.id == areaId || item.name == areaName) {
          area = item;
          break;
        }
      }
    }

    setState(() {
      _cities = refs.cities;
      _types = refs.propertyTypes;
      _city = city;
      _area = area;
      _type = type;
      _loading = false;
      _areaLoadError = city != null && city.areas.isEmpty
          ? "We couldn't load areas for ${city.name}."
          : null;
    });
  }

  CheckoutLocationDraft _snapshot() {
    return CheckoutLocationDraft(
      propertyTypeId: _type?.id,
      cityId: _city?.id,
      areaId: _area?.id,
      other: _other.text,
      street: _street.text,
      address: _address.text,
      building: _building.text,
      floor: _floor.text,
      unit: _unit.text,
      landmark: _landmark.text,
      notes: _notes.text,
      phone: UaePhone.e164(_phone.text),
    );
  }

  bool get _dirtyVsSaved {
    final location = widget.location;
    if (location == null) return false;
    final now = _snapshot();
    return now.propertyTypeId != location.propertyType.id ||
        now.cityId != location.city.id ||
        now.areaId != location.area.id ||
        now.other.trim() != (location.otherPropertyTypeName ?? '').trim() ||
        now.street.trim() != location.streetName.trim() ||
        now.address.trim() != location.addressLine.trim() ||
        now.building.trim() != location.building.trim() ||
        now.floor.trim() != (location.floorNumber ?? '').trim() ||
        now.unit.trim() != (location.unitNumber ?? '').trim() ||
        now.landmark.trim() != (location.nearbyLandmark ?? '').trim() ||
        now.notes.trim() != (location.notes ?? '').trim() ||
        _phoneDigits(now.phone) != _phoneDigits(location.visitPhone);
  }

  Future<void> _onBack() async {
    if (_saving) return;
    if (_dirtyVsSaved) {
      final discard = await confirmLocationDiscard(context);
      if (!mounted) return;
      if (!discard) return;
      Navigator.of(context).pop(false);
      return;
    }
    Navigator.of(context).pop(_snapshot());
  }

  bool _show(_LocField field) => _submitted || _touched.contains(field);

  String? _errorFor(_LocField field) {
    if (!_show(field)) return null;
    switch (field) {
      case _LocField.type:
        return _type == null ? 'Choose a property type.' : null;
      case _LocField.other:
        if (!_otherNeeded) return null;
        return _other.text.trim().length < 2
            ? 'Tell us what kind of property this is.'
            : null;
      case _LocField.city:
        return _city == null ? 'Select a city.' : null;
      case _LocField.area:
        if (_city == null) return null;
        if (_areaLoadError != null) return null;
        return _area == null ? 'Select an area.' : null;
      case _LocField.street:
        return _street.text.trim().length < 2
            ? 'Street name is too short.'
            : null;
      case _LocField.address:
        return _address.text.trim().length < 5
            ? 'Address details are too short.'
            : null;
      case _LocField.building:
        return _building.text.trim().isEmpty
            ? 'Add the building name or number.'
            : null;
      case _LocField.landmark:
        final value = _landmark.text.trim();
        if (value.isEmpty) return null;
        return value.length < 2
            ? 'Add a little more about the landmark.'
            : null;
      case _LocField.notes:
        final value = _notes.text.trim();
        if (value.isEmpty) return null;
        return value.length < 2 ? 'Add a little more about the visit.' : null;
      case _LocField.phone:
        return UaePhone.digits(_phone.text).length < 9
            ? 'Enter the full number we should call on the day.'
            : null;
    }
  }

  Future<void> _save() async {
    if (_saving) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _submitted = true;
      _saveError = null;
    });
    _LocField? first;
    for (final field in _LocField.values) {
      if (_errorFor(field) != null) {
        first = field;
        break;
      }
    }
    if (first != null) {
      await _reveal(first);
      return;
    }
    final type = _type;
    final area = _area;
    if (type == null || area == null) return;
    setState(() => _saving = true);
    try {
      await AppScope.of(context).checkout.saveLocation(
        CheckoutLocationInput(
          propertyTypeId: type.id,
          otherPropertyTypeName: _otherNeeded ? _other.text : null,
          areaId: area.id,
          streetName: _street.text,
          addressLine: _address.text,
          building: _building.text,
          floorNumber: _floor.text,
          unitNumber: _unit.text,
          nearbyLandmark: _landmark.text,
          notes: _notes.text,
          visitPhone: UaePhone.e164(_phone.text),
        ),
      );
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (error) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _saveError = error.displayMessage;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _saveError =
            "We couldn't save this location. Everything you typed is still here — try again.";
      });
    }
  }

  Future<void> _reveal(_LocField field) async {
    final key = switch (field) {
      _LocField.type => _typeKey,
      _LocField.other => _otherKey,
      _LocField.city => _cityKey,
      _LocField.area => _areaKey,
      _LocField.street => _streetKey,
      _LocField.address => _addressKey,
      _LocField.building => _buildingKey,
      _LocField.phone => _phoneKey,
      _LocField.landmark || _LocField.notes => _phoneKey,
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
    switch (field) {
      case _LocField.other:
        _otherFocus.requestFocus();
      case _LocField.street:
        _streetFocus.requestFocus();
      case _LocField.address:
        _addressFocus.requestFocus();
      case _LocField.building:
        _buildingFocus.requestFocus();
      case _LocField.phone:
        _phoneFocus.requestFocus();
      default:
        break;
    }
  }

  Future<void> _pickType() async {
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
  }

  void _setType(RefItem item) {
    setState(() {
      _type = item;
      _touched.add(_LocField.type);
      if (item.code != 'OTHER') {
        _other.clear();
        _touched.remove(_LocField.other);
      }
    });
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
      _touched.add(_LocField.city);
      if (changed) {
        _area = null;
        _touched.remove(_LocField.area);
      }
      _areaLoadError = picked.areas.isEmpty
          ? "We couldn't load areas for ${picked.name}."
          : null;
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
      _touched.add(_LocField.area);
    });
  }

  Future<void> _retryAreas() async {
    try {
      final refs = await AppScope.of(context).referenceData.load();
      if (!mounted) return;
      final selectedId = _city?.id;
      RefCity? city;
      for (final item in refs.cities) {
        if (item.id == selectedId) {
          city = item;
          break;
        }
      }
      setState(() {
        _cities = refs.cities;
        _types = refs.propertyTypes;
        _city = city ?? _city;
        if (city != null &&
            _area != null &&
            city.areas.every((item) => item.id != _area!.id)) {
          _area = null;
        }
        _areaLoadError = (city ?? _city)?.areas.isEmpty == true
            ? "We couldn't load areas for ${(city ?? _city)!.name}."
            : null;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _areaLoadError = _city == null
            ? "We couldn't load areas."
            : "We couldn't load areas for ${_city!.name}.";
      });
    }
  }

  void _onPhoneChanged(String value) {
    final formatted = UaePhone.format(value);
    if (formatted != value) {
      _phone.value = TextEditingValue(
        text: formatted,
        selection: TextSelection.collapsed(offset: formatted.length),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final keyboard = MediaQuery.viewInsetsOf(context).bottom;
    final keyboardOpen = keyboard > 80;
    final cta = _saving
        ? 'Saving...'
        : (_editing ? 'Save changes' : 'Save location');
    final subtitle = _editing
        ? 'Update where the team should go. Your appointment and cart stay as they are.'
        : 'Tell us where the team should go. Two minutes, and only the fields we actually need.';

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
                      child: CheckoutBackButton(onPressed: _onBack),
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
                      CheckoutTitle(
                        title: 'Service location',
                        subtitle: subtitle,
                        subtitleMaxWidth: 340,
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
                        _addressGroup(),
                        const Padding(
                          padding: EdgeInsets.symmetric(vertical: 22),
                          child: Divider(
                            height: 1,
                            thickness: 1,
                            color: BlueColors.navLine,
                          ),
                        ),
                        _helpGroup(),
                        const Padding(
                          padding: EdgeInsets.symmetric(vertical: 22),
                          child: Divider(
                            height: 1,
                            thickness: 1,
                            color: BlueColors.navLine,
                          ),
                        ),
                        _contactGroup(),
                      ],
                    ],
                  ),
                ),
                if (!keyboardOpen && !_loading && _bootError == null)
                  LocationSaveBar(
                    label: cta,
                    busy: _saving,
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
    final typeError = _errorFor(_LocField.type);
    final otherError = _errorFor(_LocField.other);
    final cityError = _errorFor(_LocField.city);
    final areaError = _errorFor(_LocField.area);
    final areaLocked = _city == null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const LocationGroupHead(title: 'Property'),
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
                  onPressed: () => _setType(item),
                ),
            ],
          )
        else
          LocationPickerField(
            value: _type?.name ?? 'Select property type',
            placeholder: _type == null,
            error: typeError != null,
            onPressed: _pickType,
          ),
        if (typeError != null) LocationFieldError(message: typeError),
        AnimatedSize(
          duration: BlueMotion.of(context, locationOtherReveal),
          curve: BlueMotion.curve,
          alignment: Alignment.topCenter,
          child: _otherNeeded
              ? Padding(
                  key: _otherKey,
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
                        minLines: 2,
                        maxLines: 3,
                        inputFormatters: [
                          LengthLimitingTextInputFormatter(120),
                        ],
                      ),
                      if (otherError != null)
                        LocationFieldError(message: otherError),
                    ],
                  ),
                )
              : const SizedBox.shrink(),
        ),
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
            error: areaError != null || _areaLoadError != null,
            dimmed: areaLocked,
          ),
        ),
        const SizedBox(height: 10),
        LocationPickerField(
          value: areaLocked
              ? 'Select city first'
              : (_area?.name ?? 'Select area'),
          placeholder: _area == null,
          locked: areaLocked,
          error: areaError != null,
          expanded: _openSheet == 'area',
          onPressed: _saving ? null : _pickArea,
        ),
        if (areaLocked)
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text(
              'Areas load once we know the city.',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.4,
                fontWeight: FontWeight.w500,
                color: BlueColors.muted,
              ),
            ),
          )
        else if (_areaLoadError != null)
          LocationRetryHint(message: _areaLoadError!, onRetry: _retryAreas)
        else if (areaError != null)
          LocationFieldError(message: areaError),
      ],
    );
  }

  Widget _addressGroup() {
    final streetError = _errorFor(_LocField.street);
    final addressError = _errorFor(_LocField.address);
    final buildingError = _errorFor(_LocField.building);
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
          textInputAction: TextInputAction.next,
          textCapitalization: TextCapitalization.sentences,
          inputFormatters: [LengthLimitingTextInputFormatter(50)],
        ),
      ],
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const LocationGroupHead(title: 'Address'),
        const SizedBox(height: 16),
        KeyedSubtree(
          key: _streetKey,
          child: LocationFieldLabel(
            label: 'Street name',
            required: true,
            error: streetError != null,
          ),
        ),
        const SizedBox(height: 10),
        BlueOutlinedField(
          controller: _street,
          focusNode: _streetFocus,
          hint: 'Al Marsa Street',
          error: streetError != null,
          enabled: true,
          onChanged: (_) {},
          textCapitalization: TextCapitalization.words,
          textInputAction: TextInputAction.next,
          inputFormatters: [LengthLimitingTextInputFormatter(180)],
        ),
        if (streetError != null) LocationFieldError(message: streetError),
        const SizedBox(height: 16),
        KeyedSubtree(
          key: _addressKey,
          child: LocationFieldLabel(
            label: 'Address details',
            required: true,
            error: addressError != null,
          ),
        ),
        const SizedBox(height: 10),
        BlueOutlinedField(
          controller: _address,
          focusNode: _addressFocus,
          hint: 'Marina Tower, near Marina Mall',
          error: addressError != null,
          enabled: true,
          onChanged: (_) {},
          textCapitalization: TextCapitalization.sentences,
          textInputAction: TextInputAction.next,
          minLines: 2,
          maxLines: 3,
          inputFormatters: [LengthLimitingTextInputFormatter(500)],
        ),
        if (addressError != null) LocationFieldError(message: addressError),
        const SizedBox(height: 16),
        KeyedSubtree(
          key: _buildingKey,
          child: LocationFieldLabel(
            label: 'Building name or number',
            required: true,
            error: buildingError != null,
          ),
        ),
        const SizedBox(height: 10),
        BlueOutlinedField(
          controller: _building,
          focusNode: _buildingFocus,
          hint: 'Marina Tower 4',
          error: buildingError != null,
          enabled: true,
          onChanged: (_) {},
          textCapitalization: TextCapitalization.words,
          textInputAction: TextInputAction.next,
          inputFormatters: [LengthLimitingTextInputFormatter(120)],
        ),
        if (buildingError != null) LocationFieldError(message: buildingError),
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
      ],
    );
  }

  Widget _helpGroup() {
    final landmarkError = _errorFor(_LocField.landmark);
    final notesError = _errorFor(_LocField.notes);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const LocationGroupHead(
          title: 'Help the team find you',
          subtitle:
              'Both optional — they only save the technician a phone call.',
        ),
        const SizedBox(height: 16),
        LocationFieldLabel(
          label: 'Nearby landmark',
          required: false,
          error: landmarkError != null,
        ),
        const SizedBox(height: 10),
        BlueOutlinedField(
          controller: _landmark,
          focusNode: _landmarkFocus,
          hint: 'Opposite Dubai Marina Mall',
          error: landmarkError != null,
          enabled: true,
          onChanged: (_) {},
          textCapitalization: TextCapitalization.sentences,
          textInputAction: TextInputAction.next,
          inputFormatters: [LengthLimitingTextInputFormatter(250)],
        ),
        if (landmarkError != null) LocationFieldError(message: landmarkError),
        const SizedBox(height: 16),
        LocationFieldLabel(
          label: 'Anything else for the visit',
          required: false,
          error: notesError != null,
        ),
        const SizedBox(height: 10),
        BlueOutlinedField(
          controller: _notes,
          focusNode: _notesFocus,
          hint: 'Please call before arriving.',
          error: notesError != null,
          enabled: true,
          onChanged: (_) {},
          textCapitalization: TextCapitalization.sentences,
          textInputAction: TextInputAction.newline,
          minLines: 3,
          maxLines: 5,
          inputFormatters: [LengthLimitingTextInputFormatter(1000)],
        ),
        if (notesError != null) LocationFieldError(message: notesError),
      ],
    );
  }

  Widget _contactGroup() {
    final phoneError = _errorFor(_LocField.phone);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const LocationGroupHead(title: 'Who we call on the day'),
        const SizedBox(height: 16),
        KeyedSubtree(
          key: _phoneKey,
          child: LocationFieldLabel(
            label: 'Visit contact phone',
            required: true,
            error: phoneError != null,
          ),
        ),
        const SizedBox(height: 10),
        BluePhoneField(
          controller: _phone,
          focusNode: _phoneFocus,
          error: phoneError != null,
          enabled: true,
          countryLocked: true,
          onChanged: _onPhoneChanged,
          onSubmitted: (_) => _save(),
        ),
        if (phoneError != null)
          LocationFieldError(message: phoneError)
        else
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text(
              'We only use this to reach whoever opens the door.',
              style: TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                height: 1.4,
                fontWeight: FontWeight.w500,
                color: BlueColors.muted,
              ),
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
