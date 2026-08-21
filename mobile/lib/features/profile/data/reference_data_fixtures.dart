import 'reference_data.dart';

/// Deterministic reference-data fixture for UI review and previews - the
/// real value comes from `GET /v1/reference-data/registration`. Exists
/// only so screens can be built/reviewed before a repository layer is
/// wired up; never a production data source.
abstract final class PreviewReferenceData {
  static const registration = RegistrationReferenceData(
    cities: [
      City(
        id: 2,
        code: 'DUBAI',
        name: 'Dubai',
        areas: [
          ReferenceOption(id: 8, code: 'DUBAI_MARINA', name: 'Dubai Marina'),
          ReferenceOption(id: 9, code: 'JUMEIRAH', name: 'Jumeirah'),
          ReferenceOption(
            id: 10,
            code: 'DOWNTOWN_DUBAI',
            name: 'Downtown Dubai',
          ),
          ReferenceOption(id: 11, code: 'BUSINESS_BAY', name: 'Business Bay'),
        ],
      ),
      City(
        id: 3,
        code: 'ABU_DHABI',
        name: 'Abu Dhabi',
        areas: [
          ReferenceOption(
            id: 20,
            code: 'AL_REEM_ISLAND',
            name: 'Al Reem Island',
          ),
          ReferenceOption(id: 21, code: 'KHALIFA_CITY', name: 'Khalifa City'),
        ],
      ),
      City(
        id: 4,
        code: 'SHARJAH',
        name: 'Sharjah',
        areas: [
          ReferenceOption(id: 30, code: 'AL_NAHDA_SHJ', name: 'Al Nahda'),
        ],
      ),
    ],
    propertyRelationshipTypes: [
      ReferenceOption(id: 1, code: 'PROPERTY_OWNER', name: 'Property Owner'),
      ReferenceOption(id: 2, code: 'TENANT', name: 'Tenant'),
      ReferenceOption(
        id: 3,
        code: 'PROPERTY_MANAGER',
        name: 'Property Manager',
      ),
    ],
    serviceCategories: [
      ReferenceOption(id: 1, code: 'AC', name: 'AC'),
      ReferenceOption(id: 2, code: 'CLEANING', name: 'Cleaning'),
      ReferenceOption(id: 3, code: 'PEST_CONTROL', name: 'Pest Control'),
      ReferenceOption(
        id: 4,
        code: 'MAINTENANCE',
        name: 'Maintenance & Handyman',
      ),
      ReferenceOption(id: 5, code: 'PLUMBING', name: 'Plumbing'),
    ],
    propertyTypes: [
      ReferenceOption(id: 1, code: 'APARTMENT', name: 'Apartment'),
      ReferenceOption(id: 2, code: 'VILLA', name: 'Villa'),
      ReferenceOption(id: 3, code: 'TOWNHOUSE', name: 'Townhouse'),
      ReferenceOption(id: 4, code: 'OFFICE', name: 'Office'),
      ReferenceOption(id: 5, code: 'OTHER', name: 'Other'),
    ],
  );
}
