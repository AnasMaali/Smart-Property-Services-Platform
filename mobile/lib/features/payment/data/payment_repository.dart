import 'dart:math';

import '../../../core/api/api_client.dart';
import 'payment_models.dart';

class PaymentRepository {
  PaymentRepository(this._client);

  final ApiClient _client;

  Future<PaymentAttempt> create({required String idempotencyKey}) async {
    final data = await _client.post(
      '/payments',
      auth: true,
      body: const <String, dynamic>{},
      headers: {'Idempotency-Key': idempotencyKey},
    );
    return PaymentAttempt.fromJson(data);
  }

  Future<PaymentAttempt> get(String uuid) async {
    final data = await _client.get('/payments/$uuid', auth: true);
    return PaymentAttempt.fromJson(data);
  }
}

String newPaymentKey() {
  final random = Random.secure();
  final bytes = List<int>.generate(16, (_) => random.nextInt(256));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  String hex(int i) => bytes[i].toRadixString(16).padLeft(2, '0');
  return '${hex(0)}${hex(1)}${hex(2)}${hex(3)}-'
      '${hex(4)}${hex(5)}-'
      '${hex(6)}${hex(7)}-'
      '${hex(8)}${hex(9)}-'
      '${hex(10)}${hex(11)}${hex(12)}${hex(13)}${hex(14)}${hex(15)}';
}
