/// The shared `{id, code, name}` lookup-object shape reused across every
/// reference-data list in the backend (cities, areas, property
/// relationship types, service categories, service interests...) - one
/// Dart model instead of a near-identical one per domain.
class ReferenceOption {
  const ReferenceOption({
    required this.id,
    required this.code,
    required this.name,
  });

  final int id;
  final String code;
  final String name;

  @override
  bool operator ==(Object other) => other is ReferenceOption && other.id == id;

  @override
  int get hashCode => id.hashCode;
}

class City {
  const City({
    required this.id,
    required this.code,
    required this.name,
    required this.areas,
  });

  final int id;
  final String code;
  final String name;
  final List<ReferenceOption> areas;
}

/// The full payload of `GET /v1/reference-data/registration`, fetched once
/// and cached in memory for the session - shared by Register, Edit
/// Profile, Add Property, and Checkout Location.
class RegistrationReferenceData {
  const RegistrationReferenceData({
    required this.cities,
    required this.propertyRelationshipTypes,
    required this.serviceCategories,
    required this.propertyTypes,
  });

  final List<City> cities;
  final List<ReferenceOption> propertyRelationshipTypes;
  final List<ReferenceOption> serviceCategories;

  /// Not part of the registration payload itself (the blueprint notes
  /// property types come from the app's own static/lightly-enumerated
  /// catalog), included here so every form that needs a property-type
  /// picker (Register doesn't use it, but Checkout Location/Properties do)
  /// can share one source.
  final List<ReferenceOption> propertyTypes;
}
