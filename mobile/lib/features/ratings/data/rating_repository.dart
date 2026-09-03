import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';

class RatingResult {
  const RatingResult({
    required this.bookingUuid,
    required this.ratingValue,
    this.comment,
    this.createdAt,
  });

  final String bookingUuid;
  final int ratingValue;
  final String? comment;
  final String? createdAt;
}

class RatingRepository {
  RatingRepository(this._client);

  final ApiClient _client;

  Future<RatingResult> submit({
    required String bookingUuid,
    required int ratingValue,
    String? comment,
  }) async {
    final body = <String, dynamic>{'rating_value': ratingValue};
    if (comment != null && comment.trim().isNotEmpty) {
      body['comment'] = comment.trim();
    }

    final data = await _client.post(
      '/bookings/${Uri.encodeComponent(bookingUuid)}/rating',
      body: body,
      auth: true,
    );
    final raw = data?['rating'];
    if (raw is! Map<String, dynamic>) {
      throw const ApiException(message: 'Failed to submit rating.');
    }
    return RatingResult(
      bookingUuid: raw['booking_uuid'] as String? ?? bookingUuid,
      ratingValue: (raw['rating_value'] as num?)?.toInt() ?? ratingValue,
      comment: raw['comment'] as String?,
      createdAt: raw['created_at'] as String?,
    );
  }
}
