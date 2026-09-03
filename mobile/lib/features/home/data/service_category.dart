class ServiceCategory {
  const ServiceCategory({
    required this.id,
    required this.code,
    required this.name,
    this.description = '',
  });

  final int id;
  final String code;
  final String name;
  final String description;

  factory ServiceCategory.fromJson(Map<String, dynamic> json) {
    return ServiceCategory(
      id: (json['id'] as num).toInt(),
      code: json['code'] as String? ?? '',
      name: json['name'] as String,
      description: json['description'] as String? ?? '',
    );
  }
}
