class RefItem {
  const RefItem({
    required this.id,
    required this.name,
    this.code = '',
    this.description = '',
  });

  final int id;
  final String name;
  final String code;
  final String description;

  factory RefItem.fromJson(Map<String, dynamic> json) {
    return RefItem(
      id: (json['id'] as num).toInt(),
      name: json['name'] as String,
      code: json['code'] as String? ?? '',
      description: json['description'] as String? ?? '',
    );
  }
}

class RefCity {
  const RefCity({
    required this.id,
    required this.name,
    required this.areas,
    this.code = '',
  });

  final int id;
  final String name;
  final String code;
  final List<RefItem> areas;

  factory RefCity.fromJson(Map<String, dynamic> json) {
    final areas = (json['areas'] as List<dynamic>? ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(RefItem.fromJson)
        .toList();
    return RefCity(
      id: (json['id'] as num).toInt(),
      name: json['name'] as String,
      code: json['code'] as String? ?? '',
      areas: areas,
    );
  }
}

class RegistrationReferenceData {
  const RegistrationReferenceData({
    required this.cities,
    required this.propertyRelationships,
    required this.serviceCategories,
    this.propertyTypes = const [],
  });

  final List<RefCity> cities;
  final List<RefItem> propertyRelationships;
  final List<RefItem> serviceCategories;
  final List<RefItem> propertyTypes;

  factory RegistrationReferenceData.fromJson(Map<String, dynamic> json) {
    return RegistrationReferenceData(
      cities: (json['cities'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(RefCity.fromJson)
          .toList(),
      propertyRelationships:
          (json['property_relationship_types'] as List<dynamic>? ?? const [])
              .whereType<Map<String, dynamic>>()
              .map(RefItem.fromJson)
              .toList(),
      serviceCategories:
          (json['service_categories'] as List<dynamic>? ?? const [])
              .whereType<Map<String, dynamic>>()
              .map(RefItem.fromJson)
              .toList(),
      propertyTypes: (json['property_types'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(RefItem.fromJson)
          .toList(),
    );
  }
}
