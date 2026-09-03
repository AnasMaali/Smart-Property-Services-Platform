import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import 'booking_models.dart';

class BookingRepository {
  BookingRepository(this._client);

  final ApiClient _client;

  Future<List<Booking>> list() async {
    final data = await _client.get('/bookings', auth: true);
    final rows = data?['bookings'] as List<dynamic>? ?? const [];
    return rows
        .whereType<Map<String, dynamic>>()
        .map(Booking.fromJson)
        .toList();
  }

  Future<Booking> get(String uuid) async {
    final data = await _client.get(
      '/bookings/${Uri.encodeComponent(uuid)}',
      auth: true,
    );
    final raw = data?['booking'];
    if (raw is! Map<String, dynamic>) {
      throw const ApiException(message: 'Booking not found.', statusCode: 404);
    }
    return Booking.fromJson(raw);
  }

  Future<CancellationPreview> cancellationPreview(String uuid) async {
    final data = await _client.get(
      '/bookings/${Uri.encodeComponent(uuid)}/cancellation-preview',
      auth: true,
    );
    final raw = data?['preview'];
    if (raw is! Map<String, dynamic>) {
      throw const ApiException(
        message: 'Could not load cancellation preview.',
      );
    }
    return CancellationPreview.fromJson(raw);
  }

  Future<Map<String, dynamic>> cancel(String uuid) async {
    final data = await _client.post(
      '/bookings/${Uri.encodeComponent(uuid)}/cancel',
      auth: true,
    );
    return data ?? const {};
  }
}
