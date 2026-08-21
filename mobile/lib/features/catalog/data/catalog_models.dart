import 'package:flutter/material.dart' show IconData;

import '../../../core/money/money.dart';
import '../../profile/data/reference_data.dart';

/// `pricing_status` as returned on every pricing-bearing response
/// (service preview, cart item, checkout, ...) - blueprint §6. Flutter
/// only ever renders this value; it never derives it.
enum PricingStatus { priced, quoteRequired, missingContext, unavailable }

PricingStatus pricingStatusFromCode(String code) => switch (code) {
  'PRICED' => PricingStatus.priced,
  'QUOTE_REQUIRED' => PricingStatus.quoteRequired,
  'MISSING_CONTEXT' => PricingStatus.missingContext,
  _ => PricingStatus.unavailable,
};

/// A lightweight price preview shown on category/service-list cards
/// (`GET /service-categories/{id}/services`) - `amount` is null unless
/// [status] is [PricingStatus.priced].
class PricingPreview {
  const PricingPreview({required this.status, this.amount});

  final PricingStatus status;
  final Money? amount;
}

class ServiceCategory {
  const ServiceCategory({
    required this.id,
    required this.code,
    required this.name,
    required this.description,
    required this.icon,
  });

  final int id;
  final String code;
  final String name;
  final String description;

  /// Not part of the API response - a local, deterministic icon mapping
  /// keyed by category code so category cards have a consistent visual
  /// without depending on a real image asset for every category.
  final IconData icon;
}

/// A service card as shown in a category's service list.
class ServiceSummary {
  const ServiceSummary({
    required this.uuid,
    required this.slug,
    required this.name,
    required this.shortDescription,
    required this.pricingPreview,
  });

  final String uuid;
  final String slug;
  final String name;
  final String shortDescription;
  final PricingPreview pricingPreview;
}

enum ServiceOptionType { text, number, boolean, singleSelect, multiSelect }

ServiceOptionType serviceOptionTypeFromCode(String code) => switch (code) {
  'TEXT' => ServiceOptionType.text,
  'NUMBER' => ServiceOptionType.number,
  'BOOLEAN' => ServiceOptionType.boolean,
  'SINGLE_SELECT' => ServiceOptionType.singleSelect,
  _ => ServiceOptionType.multiSelect,
};

class NumericRule {
  const NumericRule({
    required this.minValue,
    required this.maxValue,
    required this.step,
    required this.defaultValue,
    required this.unit,
  });

  final num minValue;
  final num maxValue;
  final num step;
  final num defaultValue;
  final String unit;
}

class SelectionRule {
  const SelectionRule({
    required this.minimumSelections,
    required this.maximumSelections,
  });

  final int minimumSelections;
  final int maximumSelections;
}

class ServiceOption {
  const ServiceOption({
    required this.uuid,
    required this.code,
    required this.name,
    required this.description,
    required this.type,
    required this.isRequired,
    this.numericRule,
    this.selectionRule,
    this.choices = const [],
  });

  final String uuid;
  final String code;
  final String name;
  final String description;
  final ServiceOptionType type;
  final bool isRequired;
  final NumericRule? numericRule;
  final SelectionRule? selectionRule;
  final List<ReferenceOption> choices;
}

/// Full `GET /services/{slug}` detail.
class ServiceDetail {
  const ServiceDetail({
    required this.uuid,
    required this.slug,
    required this.name,
    required this.shortDescription,
    required this.description,
    required this.category,
    required this.pricingPreview,
    required this.options,
  });

  final String uuid;
  final String slug;
  final String name;
  final String shortDescription;
  final String description;
  final ServiceCategory category;
  final PricingPreview pricingPreview;
  final List<ServiceOption> options;
}
