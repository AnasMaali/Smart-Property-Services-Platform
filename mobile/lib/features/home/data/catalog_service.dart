import 'service_category.dart';

enum CatalogPricingStatus {
  priced,
  quoteRequired,
  missingContext,
  unavailable;

  static CatalogPricingStatus parse(String? raw) {
    return switch (raw) {
      'PRICED' => CatalogPricingStatus.priced,
      'QUOTE_REQUIRED' => CatalogPricingStatus.quoteRequired,
      'MISSING_CONTEXT' => CatalogPricingStatus.missingContext,
      _ => CatalogPricingStatus.unavailable,
    };
  }
}

class CatalogCurrency {
  const CatalogCurrency({
    required this.code,
    required this.symbol,
    required this.minorUnit,
  });

  final String code;
  final String symbol;
  final int minorUnit;

  factory CatalogCurrency.fromJson(Map<String, dynamic> json) {
    return CatalogCurrency(
      code: json['code'] as String? ?? '',
      symbol: json['symbol'] as String? ?? '',
      minorUnit: (json['minor_unit'] as num?)?.toInt() ?? 2,
    );
  }
}

class CatalogPricingPreview {
  const CatalogPricingPreview({
    required this.status,
    this.unitTotal,
    this.currency,
  });

  final CatalogPricingStatus status;
  final String? unitTotal;
  final CatalogCurrency? currency;

  factory CatalogPricingPreview.fromJson(Map<String, dynamic>? json) {
    if (json == null) {
      return const CatalogPricingPreview(
        status: CatalogPricingStatus.unavailable,
      );
    }
    return CatalogPricingPreview(
      status: CatalogPricingStatus.parse(json['pricing_status'] as String?),
      unitTotal: json['unit_total'] as String?,
      currency: json['currency'] is Map<String, dynamic>
          ? CatalogCurrency.fromJson(json['currency'] as Map<String, dynamic>)
          : null,
    );
  }

  bool get isUnavailable => status == CatalogPricingStatus.unavailable;

  bool get showAsSentence {
    if (status != CatalogPricingStatus.priced) return false;
    final amount = _decimalAmount(unitTotal);
    return amount != null && amount > 0;
  }

  String get label {
    if (showAsSentence) {
      final code = currency?.code;
      final money = formatCatalogMoney(unitTotal!, currency?.minorUnit ?? 2);
      if (code == null || code.isEmpty) return 'From $money';
      return 'From $code $money';
    }
    return switch (status) {
      CatalogPricingStatus.quoteRequired => 'Quote required',
      CatalogPricingStatus.missingContext => 'Price after details',
      CatalogPricingStatus.priced ||
      CatalogPricingStatus.unavailable => 'Unavailable',
    };
  }

  String get homePrice {
    if (showAsSentence) {
      final code = currency?.code;
      final money = formatCatalogMoney(unitTotal!, currency?.minorUnit ?? 2);
      if (code == null || code.isEmpty) return money;
      return '$code $money';
    }
    return label;
  }
}

class CatalogServiceImage {
  const CatalogServiceImage({required this.storageKey, this.altText = ''});

  final String storageKey;
  final String altText;

  factory CatalogServiceImage.fromJson(Map<String, dynamic> json) {
    return CatalogServiceImage(
      storageKey: json['storage_key'] as String? ?? '',
      altText: json['alt_text'] as String? ?? '',
    );
  }

  String? get networkUrl {
    final key = storageKey.trim();
    if (key.startsWith('http://') || key.startsWith('https://')) return key;
    return null;
  }
}

class CatalogService {
  const CatalogService({
    required this.uuid,
    required this.code,
    required this.slug,
    required this.name,
    required this.shortDescription,
    required this.pricing,
    this.image,
    this.capabilities = const [],
  });

  final String uuid;
  final String code;
  final String slug;
  final String name;
  final String shortDescription;
  final CatalogServiceImage? image;
  final CatalogPricingPreview pricing;
  final List<String> capabilities;

  bool get enabled => !pricing.isUnavailable;

  bool get isBestseller => capabilities.contains('BESTSELLER');

  factory CatalogService.fromJson(Map<String, dynamic> json) {
    return CatalogService(
      uuid: json['uuid'] as String? ?? '',
      code: json['code'] as String? ?? '',
      slug: json['slug'] as String? ?? '',
      name: json['name'] as String? ?? '',
      shortDescription: json['short_description'] as String? ?? '',
      image: json['primary_image'] is Map<String, dynamic>
          ? CatalogServiceImage.fromJson(
              json['primary_image'] as Map<String, dynamic>,
            )
          : null,
      pricing: CatalogPricingPreview.fromJson(
        json['pricing_preview'] as Map<String, dynamic>?,
      ),
      capabilities: (json['capabilities'] as List<dynamic>? ?? const [])
          .map((item) => '$item')
          .where((item) => item.isNotEmpty)
          .toList(),
    );
  }
}

class CatalogSearchResult {
  const CatalogSearchResult({
    required this.services,
    this.query,
    this.category,
  });

  final List<CatalogService> services;
  final String? query;
  final ServiceCategory? category;

  factory CatalogSearchResult.fromJson(Map<String, dynamic>? json) {
    final raw = json?['services'] as List<dynamic>? ?? const [];
    return CatalogSearchResult(
      query: json?['query'] as String?,
      category: json?['category'] is Map<String, dynamic>
          ? ServiceCategory.fromJson(json!['category'] as Map<String, dynamic>)
          : null,
      services: raw
          .whereType<Map<String, dynamic>>()
          .map(CatalogService.fromJson)
          .toList(),
    );
  }
}

String formatCatalogMoney(String raw, int minorUnit) {
  final parts = raw.trim().split('.');
  if (parts.isEmpty || parts.first.isEmpty) return raw.trim();
  if (parts.length == 1 || minorUnit <= 0) return parts.first;
  var fraction = parts[1].padRight(minorUnit, '0');
  if (fraction.length > minorUnit) {
    fraction = fraction.substring(0, minorUnit);
  }
  fraction = fraction.replaceFirst(RegExp(r'0+$'), '');
  if (fraction.isEmpty) return parts.first;
  return '${parts.first}.$fraction';
}

double? _decimalAmount(String? raw) {
  if (raw == null) return null;
  return double.tryParse(raw.trim());
}
