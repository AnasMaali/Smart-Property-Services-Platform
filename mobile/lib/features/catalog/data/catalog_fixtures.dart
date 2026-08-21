import 'package:flutter/material.dart';

import '../../../core/money/money.dart';
import '../../profile/data/reference_data.dart';
import 'catalog_models.dart';

/// Deterministic catalog fixture for UI review and previews - the real
/// values come from `GET /v1/service-categories`,
/// `GET /v1/service-categories/{category}/services`, and
/// `GET /v1/services/{service}`. Exists only so screens can be built and
/// reviewed before `ServiceCatalogRepository` is wired up.
abstract final class PreviewCatalog {
  static const categories = [
    ServiceCategory(
      id: 1,
      code: 'AC',
      name: 'AC',
      description: 'Air-conditioning cleaning, repair, installation, and maintenance services.',
      icon: Icons.ac_unit_rounded,
    ),
    ServiceCategory(
      id: 2,
      code: 'CLEANING',
      name: 'Cleaning',
      description:
          'Deep cleaning and regular housekeeping for homes and offices.',
      icon: Icons.cleaning_services_rounded,
    ),
    ServiceCategory(
      id: 3,
      code: 'PEST_CONTROL',
      name: 'Pest Control',
      description: 'General and targeted pest treatment for your property.',
      icon: Icons.pest_control_rounded,
    ),
    ServiceCategory(
      id: 4,
      code: 'MAINTENANCE',
      name: 'Maintenance & Handyman',
      description: 'General repairs and upkeep around the home.',
      icon: Icons.handyman_rounded,
    ),
    ServiceCategory(
      id: 5,
      code: 'PLUMBING',
      name: 'Plumbing',
      description: 'Leak repair, fixture installation, and drainage services.',
      icon: Icons.plumbing_rounded,
    ),
  ];

  static final Map<int, List<ServiceSummary>> servicesByCategory = {
    1: const [
      ServiceSummary(
        uuid: 'svc-ac-deep-clean',
        slug: 'ac-deep-clean',
        name: 'AC Deep Cleaning',
        shortDescription: 'Deep cleaning for split AC units.',
        pricingPreview: PricingPreview(
          status: PricingStatus.priced,
          amount: Money('120.000000', Currency.aed),
        ),
      ),
      ServiceSummary(
        uuid: 'svc-ac-repair',
        slug: 'ac-repair',
        name: 'AC Repair',
        shortDescription: 'Diagnosis and repair for cooling issues.',
        pricingPreview: PricingPreview(status: PricingStatus.quoteRequired),
      ),
    ],
    2: const [
      ServiceSummary(
        uuid: 'svc-deep-clean-home',
        slug: 'full-home-deep-cleaning',
        name: 'Full Home Deep Cleaning',
        shortDescription: 'Top-to-bottom deep clean for your entire home.',
        pricingPreview: PricingPreview(
          status: PricingStatus.priced,
          amount: Money('350.000000', Currency.aed),
        ),
      ),
      ServiceSummary(
        uuid: 'svc-sofa-carpet',
        slug: 'sofa-carpet-shampooing',
        name: 'Sofa & Carpet Shampooing',
        shortDescription: 'Deep shampoo cleaning for upholstery and carpets.',
        pricingPreview: PricingPreview(
          status: PricingStatus.priced,
          amount: Money('90.000000', Currency.aed),
        ),
      ),
    ],
    3: const [
      ServiceSummary(
        uuid: 'svc-pest-general',
        slug: 'pest-control-general',
        name: 'Pest Control — General Treatment',
        shortDescription: 'Routine treatment for common household pests.',
        pricingPreview: PricingPreview(
          status: PricingStatus.priced,
          amount: Money('140.000000', Currency.aed),
        ),
      ),
    ],
    4: const [
      ServiceSummary(
        uuid: 'svc-handyman',
        slug: 'handyman-general-maintenance',
        name: 'Handyman — General Maintenance',
        shortDescription: 'Small repairs and fixes around the home.',
        pricingPreview: PricingPreview(status: PricingStatus.missingContext),
      ),
    ],
    5: const [
      ServiceSummary(
        uuid: 'svc-tank-cleaning',
        slug: 'water-tank-cleaning',
        name: 'Water Tank Cleaning',
        shortDescription:
            'Cleaning and disinfection for residential water tanks.',
        pricingPreview: PricingPreview(
          status: PricingStatus.priced,
          amount: Money('280.000000', Currency.aed),
        ),
      ),
    ],
  };

  static final Map<String, ServiceDetail> serviceDetails = {
    'ac-deep-clean': ServiceDetail(
      uuid: 'svc-ac-deep-clean',
      slug: 'ac-deep-clean',
      name: 'AC Deep Cleaning',
      shortDescription: 'Deep cleaning for split AC units.',
      description:
          'A thorough clean of your split AC unit, including the filter, coils, and drainage - '
          'improves cooling performance and indoor air quality. Recommended every 3-6 months.',
      category: categories[0],
      pricingPreview: const PricingPreview(
        status: PricingStatus.priced,
        amount: Money('120.000000', Currency.aed),
      ),
      options: const [
        ServiceOption(
          uuid: 'opt-unit-count',
          code: 'UNIT_COUNT',
          name: 'Number of AC units',
          description: 'How many split units need cleaning.',
          type: ServiceOptionType.number,
          isRequired: true,
          numericRule: NumericRule(
            minValue: 1,
            maxValue: 10,
            step: 1,
            defaultValue: 1,
            unit: 'units',
          ),
        ),
        ServiceOption(
          uuid: 'opt-ac-type',
          code: 'AC_TYPE',
          name: 'AC type',
          description: 'Select the type of unit being serviced.',
          type: ServiceOptionType.singleSelect,
          isRequired: true,
          selectionRule: SelectionRule(
            minimumSelections: 1,
            maximumSelections: 1,
          ),
          choices: [
            ReferenceOption(id: 1, code: 'SPLIT', name: 'Split unit'),
            ReferenceOption(id: 2, code: 'WINDOW', name: 'Window unit'),
            ReferenceOption(id: 3, code: 'CENTRAL', name: 'Central/ducted'),
          ],
        ),
        ServiceOption(
          uuid: 'opt-add-sanitize',
          code: 'ADD_SANITIZE',
          name: 'Add sanitization treatment',
          description: 'Antibacterial coil treatment for improved air quality.',
          type: ServiceOptionType.boolean,
          isRequired: false,
        ),
        ServiceOption(
          uuid: 'opt-notes',
          code: 'ACCESS_NOTES',
          name: 'Access notes',
          description:
              'Anything the technician should know to access the unit(s).',
          type: ServiceOptionType.text,
          isRequired: false,
        ),
      ],
    ),
  };
}
