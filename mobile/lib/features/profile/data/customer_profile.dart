class ServiceInterest {
  const ServiceInterest({required this.id, required this.name, this.code = ''});

  final int id;
  final String name;
  final String code;

  factory ServiceInterest.fromJson(Map<String, dynamic> json) {
    return ServiceInterest(
      id: (json['id'] as num).toInt(),
      name: json['name'] as String? ?? '',
      code: json['code'] as String? ?? '',
    );
  }

  Map<String, dynamic> toJson() {
    return {'id': id, 'name': name, 'code': code};
  }
}

class CustomerProfile {
  const CustomerProfile({
    required this.userUuid,
    required this.fullName,
    required this.email,
    required this.phoneNumber,
    required this.cityName,
    required this.areaName,
    this.serviceInterests = const [],
  });

  final String userUuid;
  final String fullName;
  final String email;
  final String phoneNumber;
  final String cityName;
  final String areaName;
  final List<ServiceInterest> serviceInterests;

  String get firstName {
    final parts = fullName.trim().split(RegExp(r'\s+'));
    return parts.isEmpty ? 'there' : parts.first;
  }

  String get placeLabel {
    if (areaName.isEmpty && cityName.isEmpty) return '';
    if (areaName.isEmpty) return cityName;
    if (cityName.isEmpty) return areaName;
    return '$areaName · $cityName';
  }

  CustomerProfile copyWith({
    String? fullName,
    String? email,
    String? phoneNumber,
    List<ServiceInterest>? serviceInterests,
  }) {
    return CustomerProfile(
      userUuid: userUuid,
      fullName: fullName ?? this.fullName,
      email: email ?? this.email,
      phoneNumber: phoneNumber ?? this.phoneNumber,
      cityName: cityName,
      areaName: areaName,
      serviceInterests: serviceInterests ?? this.serviceInterests,
    );
  }

  factory CustomerProfile.fromJson(Map<String, dynamic> json) {
    final location = json['location'] as Map<String, dynamic>? ?? const {};
    final city = location['city'] as Map<String, dynamic>? ?? const {};
    final area = location['area'] as Map<String, dynamic>? ?? const {};
    return CustomerProfile(
      userUuid: json['user_uuid'] as String? ?? '',
      fullName: json['full_name'] as String? ?? '',
      email: json['email'] as String? ?? '',
      phoneNumber: json['phone_number'] as String? ?? '',
      cityName: city['name'] as String? ?? '',
      areaName: area['name'] as String? ?? '',
      serviceInterests:
          (json['service_interests'] as List<dynamic>? ?? const [])
              .whereType<Map<String, dynamic>>()
              .map(ServiceInterest.fromJson)
              .toList(),
    );
  }
}

String blueGreetingPart([DateTime? now]) {
  final hour = (now ?? DateTime.now()).hour;
  final part = hour < 12
      ? 'morning'
      : hour < 17
      ? 'afternoon'
      : 'evening';
  return 'Good $part,';
}

String blueGreeting(String firstName, [DateTime? now]) {
  return '${blueGreetingPart(now)} $firstName';
}
