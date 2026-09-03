import '../../../core/api/api_client.dart';
import 'checkout_models.dart';

class CheckoutLocationInput {
  const CheckoutLocationInput({
    required this.propertyTypeId,
    required this.areaId,
    required this.streetName,
    required this.addressLine,
    required this.building,
    required this.visitPhone,
    this.otherPropertyTypeName,
    this.floorNumber,
    this.unitNumber,
    this.nearbyLandmark,
    this.notes,
  });

  final int propertyTypeId;
  final String? otherPropertyTypeName;
  final int areaId;
  final String streetName;
  final String addressLine;
  final String building;
  final String? floorNumber;
  final String? unitNumber;
  final String? nearbyLandmark;
  final String? notes;
  final String visitPhone;

  Map<String, dynamic> toJson() {
    return {
      'property_type_id': propertyTypeId,
      'other_property_type_name':
          (otherPropertyTypeName == null ||
              otherPropertyTypeName!.trim().isEmpty)
          ? null
          : otherPropertyTypeName!.trim(),
      'area_id': areaId,
      'street_name': streetName.trim(),
      'address_line': addressLine.trim(),
      'building_name_or_number': building.trim(),
      'floor_number': _optional(floorNumber),
      'unit_number': _optional(unitNumber),
      'nearby_landmark': _optional(nearbyLandmark),
      'additional_location_notes': _optional(notes),
      'visit_contact_phone': visitPhone.trim(),
    };
  }

  String? _optional(String? value) {
    final trimmed = value?.trim() ?? '';
    return trimmed.isEmpty ? null : trimmed;
  }
}

class CheckoutRepository {
  CheckoutRepository(this._client);

  final ApiClient _client;

  Future<CheckoutSnapshot> get() async {
    final data = await _client.get('/checkout', auth: true);
    return CheckoutSnapshot.fromJson(data);
  }

  Future<CheckoutSnapshot> saveLocation(CheckoutLocationInput input) async {
    final data = await _client.put(
      '/checkout/location',
      auth: true,
      body: input.toJson(),
    );
    return CheckoutSnapshot.fromJson(data);
  }

  Future<List<CheckoutSlot>> slots() async {
    final data = await _client.get('/checkout/appointment-slots', auth: true);
    final rows = data?['appointment_slots'] as List<dynamic>? ?? const [];
    return rows
        .whereType<Map<String, dynamic>>()
        .map(CheckoutSlot.fromJson)
        .toList();
  }

  Future<CheckoutSnapshot> holdSlot(String slotUuid) async {
    final data = await _client.post(
      '/checkout/appointment-hold',
      auth: true,
      body: {'appointment_slot_uuid': slotUuid},
    );
    return CheckoutSnapshot.fromJson(data);
  }

  Future<CheckoutSnapshot> releaseHold() async {
    final data = await _client.delete('/checkout/appointment-hold', auth: true);
    return CheckoutSnapshot.fromJson(data);
  }
}
