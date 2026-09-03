import '../../auth/data/reference_data.dart';
import '../../home/presentation/widgets/home_icons.dart';

class PropertyNamedRef {
  const PropertyNamedRef({
    this.id = 0,
    required this.name,
    this.code = '',
    this.cityName = '',
    this.countryName = '',
  });

  final int id;
  final String name;
  final String code;
  final String cityName;
  final String countryName;

  bool get isEmpty => name.isEmpty && code.isEmpty && id == 0;

  factory PropertyNamedRef.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const PropertyNamedRef(name: '');
    return PropertyNamedRef(
      id: (json['id'] as num?)?.toInt() ?? 0,
      name: json['name'] as String? ?? '',
      code: json['code'] as String? ?? '',
      cityName: json['city_name'] as String? ?? '',
      countryName: json['country_name'] as String? ?? '',
    );
  }
}

class SavedProperty {
  const SavedProperty({
    required this.uuid,
    required this.label,
    required this.relationship,
    required this.propertyType,
    required this.area,
    this.otherPropertyTypeName,
    this.streetName = '',
    this.addressLine = '',
    this.building = '',
    this.floorNumber,
    this.unitNumber,
    this.nearbyLandmark,
    this.notes,
    this.visitPhone = '',
    this.isActive = true,
  });

  final String uuid;
  final String label;
  final PropertyNamedRef relationship;
  final PropertyNamedRef propertyType;
  final PropertyNamedRef area;
  final String? otherPropertyTypeName;
  final String streetName;
  final String addressLine;
  final String building;
  final String? floorNumber;
  final String? unitNumber;
  final String? nearbyLandmark;
  final String? notes;
  final String visitPhone;
  final bool isActive;

  bool get isOther => propertyType.code == 'OTHER';

  bool get hasType =>
      propertyType.code.isNotEmpty || propertyType.name.isNotEmpty;

  String get identity {
    if (isOther) {
      final custom = otherPropertyTypeName?.trim() ?? '';
      if (custom.isNotEmpty) return custom;
    }
    if (hasType) {
      final name = propertyType.name.trim();
      if (name.isNotEmpty) return name;
    }
    return area.name.trim();
  }

  String get placeLine {
    if (hasType) {
      final city = area.cityName.trim();
      final areaName = area.name.trim();
      if (areaName.isEmpty) return city;
      if (city.isEmpty) return areaName;
      return '$areaName · $city';
    }
    return area.cityName.trim();
  }

  String get detailLine {
    final shownBuilding = displayBuilding;
    final unit = unitNumber?.trim() ?? '';
    final floor = floorNumber?.trim() ?? '';
    if (shownBuilding.isNotEmpty && unit.isNotEmpty) {
      return '$shownBuilding · Unit $unit';
    }
    if (shownBuilding.isNotEmpty && floor.isNotEmpty) {
      return '$shownBuilding · Floor $floor';
    }
    if (shownBuilding.isNotEmpty) return shownBuilding;
    if (unit.isNotEmpty) return 'Unit $unit';
    if (floor.isNotEmpty) return 'Floor $floor';
    return '';
  }

  String get displayBuilding {
    final value = building.trim();
    if (value.isEmpty || value == '-' || value == '—') return '';
    return value;
  }

  String get relationshipLabel =>
      propertyRelationshipLabel(relationship.code, relationship.name);

  BlueGlyph get glyph {
    if (!hasType) return BlueGlyph.pin;
    switch (propertyType.code) {
      case 'VILLA':
      case 'HOUSE':
        return BlueGlyph.home;
      case 'OFFICE':
        return BlueGlyph.office;
      case 'APARTMENT':
      case 'BUILDING':
        return BlueGlyph.building;
      case 'OTHER':
        return BlueGlyph.pin;
      default:
        return BlueGlyph.building;
    }
  }

  String get a11y {
    final parts = <String>[identity];
    if (placeLine.isNotEmpty) {
      parts.add(placeLine.replaceAll(' · ', ', '));
    }
    if (detailLine.isNotEmpty) {
      parts.add(detailLine.replaceAll(' · ', ', '));
    }
    if (relationshipLabel.isNotEmpty) {
      parts.add('Your relationship: $relationshipLabel');
    }
    parts.add('Opens this property to edit.');
    return parts.join('. ');
  }

  factory SavedProperty.fromJson(Map<String, dynamic> json) {
    return SavedProperty(
      uuid: json['uuid'] as String? ?? '',
      label: json['label'] as String? ?? '',
      relationship: PropertyNamedRef.fromJson(
        json['relationship_type'] as Map<String, dynamic>?,
      ),
      propertyType: PropertyNamedRef.fromJson(
        json['property_type'] as Map<String, dynamic>?,
      ),
      otherPropertyTypeName: json['other_property_type_name'] as String?,
      area: PropertyNamedRef.fromJson(json['area'] as Map<String, dynamic>?),
      streetName: json['street_name'] as String? ?? '',
      addressLine: json['address_line'] as String? ?? '',
      building: json['building_name_or_number'] as String? ?? '',
      floorNumber: json['floor_number'] as String?,
      unitNumber: json['unit_number'] as String?,
      nearbyLandmark: json['nearby_landmark'] as String?,
      notes: json['additional_location_notes'] as String?,
      visitPhone: json['visit_contact_phone'] as String? ?? '',
      isActive: json['is_active'] as bool? ?? true,
    );
  }
}

class PropertyDraft {
  const PropertyDraft({
    this.propertyTypeId,
    this.relationshipId,
    this.cityId,
    this.areaId,
    this.other = '',
    this.building = '',
    this.floor = '',
    this.unit = '',
  });

  final int? propertyTypeId;
  final int? relationshipId;
  final int? cityId;
  final int? areaId;
  final String other;
  final String building;
  final String floor;
  final String unit;

  PropertyDraft copyWith({
    int? propertyTypeId,
    int? relationshipId,
    int? cityId,
    int? areaId,
    String? other,
    String? building,
    String? floor,
    String? unit,
  }) {
    return PropertyDraft(
      propertyTypeId: propertyTypeId ?? this.propertyTypeId,
      relationshipId: relationshipId ?? this.relationshipId,
      cityId: cityId ?? this.cityId,
      areaId: areaId ?? this.areaId,
      other: other ?? this.other,
      building: building ?? this.building,
      floor: floor ?? this.floor,
      unit: unit ?? this.unit,
    );
  }

  factory PropertyDraft.fromProperty(
    SavedProperty property,
    List<RefCity> cities,
    List<RefItem> types,
    List<RefItem> relations,
  ) {
    RefCity? city;
    for (final item in cities) {
      if (item.name == property.area.cityName) {
        city = item;
        break;
      }
    }
    city ??= _cityForArea(cities, property.area.id);
    RefItem? type;
    for (final item in types) {
      if (item.code == property.propertyType.code ||
          item.name == property.propertyType.name) {
        type = item;
        break;
      }
    }
    RefItem? relation;
    for (final item in relations) {
      if (item.code == property.relationship.code ||
          item.name == property.relationship.name) {
        relation = item;
        break;
      }
    }
    return PropertyDraft(
      propertyTypeId: type?.id,
      relationshipId: relation?.id,
      cityId: city?.id,
      areaId: property.area.id == 0 ? null : property.area.id,
      other: property.otherPropertyTypeName ?? '',
      building: property.displayBuilding,
      floor: property.floorNumber ?? '',
      unit: property.unitNumber ?? '',
    );
  }
}

class PropertyWrite {
  const PropertyWrite({
    required this.label,
    required this.relationshipTypeId,
    required this.propertyTypeId,
    required this.areaId,
    required this.streetName,
    required this.addressLine,
    required this.building,
    required this.visitPhone,
    this.otherPropertyTypeName,
    this.floorNumber,
    this.unitNumber,
  });

  final String label;
  final int relationshipTypeId;
  final int propertyTypeId;
  final String? otherPropertyTypeName;
  final int areaId;
  final String streetName;
  final String addressLine;
  final String building;
  final String? floorNumber;
  final String? unitNumber;
  final String visitPhone;

  Map<String, dynamic> toCreateJson() {
    return {
      'label': label,
      'property_relationship_type_id': relationshipTypeId,
      'property_type_id': propertyTypeId,
      'other_property_type_name': otherPropertyTypeName,
      'area_id': areaId,
      'street_name': streetName,
      'address_line': addressLine,
      'building_name_or_number': building,
      'floor_number': floorNumber,
      'unit_number': unitNumber,
      'visit_contact_phone': visitPhone,
    };
  }

  Map<String, dynamic> toPatchJson() {
    return {
      'label': label,
      'property_relationship_type_id': relationshipTypeId,
      'property_type_id': propertyTypeId,
      'other_property_type_name': otherPropertyTypeName,
      'area_id': areaId,
      'building_name_or_number': building,
      'floor_number': floorNumber,
      'unit_number': unitNumber,
    };
  }
}

class PropertyFormPop {
  const PropertyFormPop({required this.saved, this.draft, this.savedUuid});

  final bool saved;
  final PropertyDraft? draft;
  final String? savedUuid;
}

String propertyRelationshipLabel(String code, String name) {
  switch (code) {
    case 'PROPERTY_OWNER':
    case 'OWNER':
      return 'Owner';
    case 'TENANT':
      return 'Tenant';
    case 'FAMILY_MEMBER':
    case 'FAMILY':
      return 'Family member';
    case 'PROPERTY_MANAGER':
    case 'MANAGER':
      return 'Property manager';
    default:
      return name.trim().isEmpty ? code : name;
  }
}

RefCity? _cityForArea(List<RefCity> cities, int areaId) {
  if (areaId == 0) return null;
  for (final city in cities) {
    for (final area in city.areas) {
      if (area.id == areaId) return city;
    }
  }
  return null;
}
