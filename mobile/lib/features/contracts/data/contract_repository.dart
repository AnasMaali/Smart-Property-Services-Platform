import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import '../../checkout/data/checkout_models.dart';
import 'contract_models.dart';

class ContractRepository {
  ContractRepository(this._client);

  final ApiClient _client;

  Future<List<Contract>> list() async {
    final data = await _client.get('/contracts', auth: true);
    final rows = data?['contracts'] as List<dynamic>? ?? const [];
    final summaries = rows.whereType<Map<String, dynamic>>().toList();
    if (summaries.isEmpty) return const [];
    return Future.wait(
      summaries.map((row) async {
        final uuid = row['uuid'] as String? ?? '';
        if (uuid.isEmpty) return Contract.fromJson(row);
        try {
          return await get(uuid);
        } on ApiException {
          return Contract.fromJson(row);
        }
      }),
    );
  }

  Future<Contract> get(String uuid) async {
    final data = await _client.get(
      '/contracts/${Uri.encodeComponent(uuid)}',
      auth: true,
    );
    final raw = data?['contract'];
    if (raw is! Map<String, dynamic>) {
      throw const ApiException(message: 'Contract not found.', statusCode: 404);
    }
    return Contract.fromJson(raw);
  }

  Future<Contract> accept(String uuid) async {
    final data = await _client.post(
      '/contracts/${Uri.encodeComponent(uuid)}/accept',
      auth: true,
    );
    final raw = data?['contract'];
    if (raw is Map<String, dynamic>) return Contract.fromJson(raw);
    return get(uuid);
  }

  Future<String> createBillingCheckout(String uuid) async {
    final data = await _client.post(
      '/contracts/${Uri.encodeComponent(uuid)}/billing/checkout',
      auth: true,
    );
    final billing = data?['billing'];
    final url = billing is Map<String, dynamic>
        ? (billing['checkout_url'] as String? ?? '').trim()
        : '';
    if (url.isEmpty) {
      throw const ApiException(
        message: 'Unable to start billing checkout.',
        statusCode: 409,
      );
    }
    return url;
  }

  /// Customer contract request (`POST /contracts/requests`).
  Future<Contract> request({
    required String propertyUuid,
    bool allServices = false,
    List<String>? serviceUuids,
    String? desiredStartDate,
    String? customerNote,
  }) async {
    final body = <String, dynamic>{
      'property_uuid': propertyUuid,
      'all_services': allServices,
    };
    if (!allServices && serviceUuids != null) {
      body['service_uuids'] = serviceUuids;
    }
    if (desiredStartDate != null && desiredStartDate.isNotEmpty) {
      body['desired_start_date'] = desiredStartDate;
    }
    if (customerNote != null && customerNote.trim().isNotEmpty) {
      body['customer_note'] = customerNote.trim();
    }
    final data = await _client.post(
      '/contracts/requests',
      body: body,
      auth: true,
    );
    final raw = data?['contract'];
    if (raw is Map<String, dynamic>) return Contract.fromJson(raw);
    throw const ApiException(message: 'Failed to request contract.');
  }

  Future<Map<String, dynamic>> createBooking({
    required String contractUuid,
    required String contractItemUuid,
    required String appointmentSlotUuid,
  }) async {
    final data = await _client.post(
      '/contracts/${Uri.encodeComponent(contractUuid)}/services/${Uri.encodeComponent(contractItemUuid)}/book',
      body: {'appointment_slot_uuid': appointmentSlotUuid},
      auth: true,
    );
    return data ?? const {};
  }

  /// Cart-free bookable slots for contract visit booking.
  Future<List<CheckoutSlot>> listAppointmentSlots() async {
    final data = await _client.get('/appointment-slots', auth: true);
    final rows = data?['appointment_slots'] as List<dynamic>? ?? const [];
    return rows
        .whereType<Map<String, dynamic>>()
        .map(CheckoutSlot.fromJson)
        .toList();
  }
}
