import '../../home/data/catalog_service.dart';
import '../../home/data/service_category.dart';

enum ServiceOptionKind {
  text,
  number,
  boolean,
  singleSelect,
  multiSelect;

  static ServiceOptionKind parse(String? raw) {
    return switch (raw) {
      'TEXT' => ServiceOptionKind.text,
      'NUMBER' => ServiceOptionKind.number,
      'BOOLEAN' => ServiceOptionKind.boolean,
      'SINGLE_SELECT' => ServiceOptionKind.singleSelect,
      'MULTI_SELECT' => ServiceOptionKind.multiSelect,
      _ => ServiceOptionKind.text,
    };
  }
}

class ServiceMedia {
  const ServiceMedia({
    required this.uuid,
    required this.storageKey,
    this.altText = '',
    this.isPrimary = false,
  });

  final String uuid;
  final String storageKey;
  final String altText;
  final bool isPrimary;

  factory ServiceMedia.fromJson(Map<String, dynamic> json) {
    return ServiceMedia(
      uuid: json['uuid'] as String? ?? '',
      storageKey: json['storage_key'] as String? ?? '',
      altText: json['alt_text'] as String? ?? '',
      isPrimary: json['is_primary'] == true,
    );
  }

  String? get networkUrl {
    final key = storageKey.trim();
    if (key.startsWith('http://') || key.startsWith('https://')) return key;
    return null;
  }
}

class DetailPricing {
  const DetailPricing({
    required this.status,
    this.currencyCode,
    this.unitTotal,
    this.lineTotal,
    this.quantity = 1,
    this.requiredContext = const [],
  });

  final CatalogPricingStatus status;
  final String? currencyCode;
  final String? unitTotal;
  final String? lineTotal;
  final int quantity;
  final List<String> requiredContext;

  factory DetailPricing.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return const DetailPricing(status: CatalogPricingStatus.unavailable);
    }
    final currency = json['currency'];
    String? code;
    if (currency is String && currency.trim().isNotEmpty) {
      code = currency.trim();
    } else if (currency is Map<String, dynamic>) {
      code = currency['code'] as String?;
    }
    return DetailPricing(
      status: CatalogPricingStatus.parse(json['pricing_status'] as String?),
      currencyCode: code,
      unitTotal: json['unit_total'] as String?,
      lineTotal: json['line_total'] as String?,
      quantity: (json['quantity'] as num?)?.toInt() ?? 1,
      requiredContext: (json['required_context'] as List<dynamic>? ?? const [])
          .map((item) => '$item')
          .where((item) => item.isNotEmpty)
          .toList(),
    );
  }

  bool get isPriced {
    if (status != CatalogPricingStatus.priced) return false;
    final amount = double.tryParse(unitTotal?.trim() ?? '');
    return amount != null && amount > 0;
  }

  String get moneyLabel {
    final raw = unitTotal;
    if (raw == null || raw.isEmpty) return '';
    final money = formatCatalogMoney(raw, 2);
    final code = currencyCode?.trim();
    if (code == null || code.isEmpty) return money;
    return '$code $money';
  }
}

class OptionChoice {
  const OptionChoice({
    required this.uuid,
    required this.code,
    required this.name,
    this.description,
    this.attributes = const {},
  });

  final String uuid;
  final String code;
  final String name;
  final String? description;
  final Map<String, String> attributes;

  factory OptionChoice.fromJson(Map<String, dynamic> json) {
    final raw = json['attributes'];
    final attributes = <String, String>{};
    if (raw is Map) {
      raw.forEach((key, value) {
        if ('$key'.isEmpty) return;
        attributes['$key'] = '$value';
      });
    }
    return OptionChoice(
      uuid: json['uuid'] as String? ?? '',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      description: json['description'] as String?,
      attributes: attributes,
    );
  }

  String? get displayedPrice => attributes['displayed_price'];

  String? get durationMinutes => attributes['duration_minutes'];

  String? get oilType => attributes['oil_type'];

  String? get oilGrade => attributes['oil_grade'];

  bool get isPackageCard =>
      displayedPrice != null || durationMinutes != null || oilType != null;
}

class NumericRule {
  const NumericRule({
    this.minValue,
    this.maxValue,
    this.stepValue,
    this.defaultValue,
    this.decimalPlaces = 0,
    this.unitName,
    this.unitSymbol,
  });

  final String? minValue;
  final String? maxValue;
  final String? stepValue;
  final String? defaultValue;
  final int decimalPlaces;
  final String? unitName;
  final String? unitSymbol;

  factory NumericRule.fromJson(Map<String, dynamic>? json) {
    if (json == null) return const NumericRule();
    final unit = json['measurement_unit'];
    return NumericRule(
      minValue: json['min_value'] as String?,
      maxValue: json['max_value'] as String?,
      stepValue: json['step_value'] as String?,
      defaultValue: json['default_value'] as String?,
      decimalPlaces: (json['decimal_places'] as num?)?.toInt() ?? 0,
      unitName: unit is Map<String, dynamic> ? unit['name'] as String? : null,
      unitSymbol: unit is Map<String, dynamic>
          ? unit['symbol'] as String?
          : null,
    );
  }

  double? get min => double.tryParse(minValue ?? '');

  double? get max => double.tryParse(maxValue ?? '');

  String get displayUnit {
    final symbol = unitSymbol?.trim();
    if (symbol != null && symbol.isNotEmpty) return symbol;
    final name = unitName?.trim();
    if (name != null && name.isNotEmpty) return name.toLowerCase();
    return '';
  }

  String formatDefault() {
    final raw = defaultValue ?? minValue;
    if (raw == null) return '';
    return formatCatalogMoney(raw, decimalPlaces);
  }
}

class ServiceOption {
  const ServiceOption({
    required this.uuid,
    required this.code,
    required this.name,
    required this.kind,
    required this.isRequired,
    this.description = '',
    this.numeric,
    this.minSelections,
    this.maxSelections,
    this.choices = const [],
  });

  final String uuid;
  final String code;
  final String name;
  final String description;
  final ServiceOptionKind kind;
  final bool isRequired;
  final NumericRule? numeric;
  final int? minSelections;
  final int? maxSelections;
  final List<OptionChoice> choices;

  factory ServiceOption.fromJson(Map<String, dynamic> json) {
    final kind = ServiceOptionKind.parse(json['type'] as String?);
    final selection = json['selection_rule'];
    return ServiceOption(
      uuid: json['uuid'] as String? ?? '',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      description: json['description'] as String? ?? '',
      kind: kind,
      isRequired: json['is_required'] == true,
      numeric: kind == ServiceOptionKind.number
          ? NumericRule.fromJson(json['numeric_rule'] as Map<String, dynamic>?)
          : null,
      minSelections: selection is Map<String, dynamic>
          ? (selection['minimum_selections'] as num?)?.toInt()
          : null,
      maxSelections: selection is Map<String, dynamic>
          ? (selection['maximum_selections'] as num?)?.toInt()
          : null,
      choices: (json['choices'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(OptionChoice.fromJson)
          .toList(),
    );
  }
}

class ServiceDetail {
  const ServiceDetail({
    required this.uuid,
    required this.code,
    required this.slug,
    required this.name,
    required this.shortDescription,
    required this.description,
    required this.category,
    required this.pricing,
    this.media = const [],
    this.options = const [],
    this.capabilities = const [],
    this.contentSections = const [],
    this.checkpointCategories = const [],
  });

  final String uuid;
  final String code;
  final String slug;
  final String name;
  final String shortDescription;
  final String description;
  final ServiceCategory category;
  final List<ServiceMedia> media;
  final DetailPricing pricing;
  final List<ServiceOption> options;
  final List<String> capabilities;
  final List<ServiceContentSection> contentSections;
  final List<ServiceCheckpointCategory> checkpointCategories;

  bool get isBestseller => capabilities.contains('BESTSELLER');

  bool get requiresInAppPayment =>
      capabilities.contains('REQUIRES_IN_APP_PAYMENT');

  bool get isInspectionDeposit => capabilities.contains('INSPECTION_DEPOSIT');

  bool get quantityLocked => capabilities.contains('QUANTITY_LOCKED');

  factory ServiceDetail.fromJson(Map<String, dynamic> json) {
    return ServiceDetail(
      uuid: json['uuid'] as String? ?? '',
      code: json['code'] as String? ?? '',
      slug: json['slug'] as String? ?? '',
      name: json['name'] as String? ?? '',
      shortDescription: json['short_description'] as String? ?? '',
      description: json['description'] as String? ?? '',
      category: json['category'] is Map<String, dynamic>
          ? ServiceCategory.fromJson(json['category'] as Map<String, dynamic>)
          : const ServiceCategory(id: 0, code: '', name: ''),
      media: (json['media'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(ServiceMedia.fromJson)
          .toList(),
      pricing: DetailPricing.fromJson(
        json['pricing_preview'] as Map<String, dynamic>?,
      ),
      options: (json['options'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(ServiceOption.fromJson)
          .toList(),
      capabilities: (json['capabilities'] as List<dynamic>? ?? const [])
          .map((item) => '$item')
          .where((item) => item.isNotEmpty)
          .toList(),
      contentSections: (json['content_sections'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(ServiceContentSection.fromJson)
          .toList(),
      checkpointCategories:
          (json['checkpoint_categories'] as List<dynamic>? ?? const [])
              .whereType<Map<String, dynamic>>()
              .map(ServiceCheckpointCategory.fromJson)
              .toList(),
    );
  }

  ServiceDetail withPricing(DetailPricing pricing) {
    return ServiceDetail(
      uuid: uuid,
      code: code,
      slug: slug,
      name: name,
      shortDescription: shortDescription,
      description: description,
      category: category,
      media: media,
      pricing: pricing,
      options: options,
      capabilities: capabilities,
      contentSections: contentSections,
      checkpointCategories: checkpointCategories,
    );
  }
}

class ServiceContentSection {
  const ServiceContentSection({
    required this.code,
    required this.type,
    required this.title,
    this.body,
    this.statValue,
  });

  final String code;
  final String type;
  final String title;
  final String? body;
  final String? statValue;

  factory ServiceContentSection.fromJson(Map<String, dynamic> json) {
    return ServiceContentSection(
      code: json['code'] as String? ?? '',
      type: json['type'] as String? ?? '',
      title: json['title'] as String? ?? '',
      body: json['body'] as String?,
      statValue: json['stat_value'] as String?,
    );
  }
}

class ServiceCheckpointCategory {
  const ServiceCheckpointCategory({
    required this.uuid,
    required this.code,
    required this.name,
    this.checkpointCount,
    this.items = const [],
  });

  final String uuid;
  final String code;
  final String name;
  final int? checkpointCount;
  final List<ServiceCheckpoint> items;

  factory ServiceCheckpointCategory.fromJson(Map<String, dynamic> json) {
    return ServiceCheckpointCategory(
      uuid: json['uuid'] as String? ?? '',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      checkpointCount: (json['checkpoint_count'] as num?)?.toInt(),
      items: (json['items'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(ServiceCheckpoint.fromJson)
          .toList(),
    );
  }

  String get countLabel {
    if (checkpointCount == null) return 'N/A';
    return '${checkpointCount!}';
  }
}

class ServiceCheckpoint {
  const ServiceCheckpoint({
    required this.uuid,
    required this.name,
    this.actionLabel,
  });

  final String uuid;
  final String name;
  final String? actionLabel;

  factory ServiceCheckpoint.fromJson(Map<String, dynamic> json) {
    return ServiceCheckpoint(
      uuid: json['uuid'] as String? ?? '',
      name: json['name'] as String? ?? '',
      actionLabel: json['action_label'] as String?,
    );
  }
}
