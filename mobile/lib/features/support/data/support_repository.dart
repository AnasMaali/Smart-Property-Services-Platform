import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import 'support_models.dart';

class SupportRepository {
  SupportRepository(this._client);

  final ApiClient _client;

  Future<List<SupportRequest>> list() async {
    final data = await _client.get('/support-requests', auth: true);
    final rows = data?['support_requests'] as List<dynamic>? ?? const [];
    return rows
        .whereType<Map<String, dynamic>>()
        .map(_requestFromJson)
        .toList();
  }

  Future<SupportRequest> get(String uuid) async {
    final data = await _client.get(
      '/support-requests/${Uri.encodeComponent(uuid)}',
      auth: true,
    );
    final raw = data?['support_request'];
    if (raw is! Map<String, dynamic>) {
      throw const ApiException(
        message: 'Support request not found.',
        statusCode: 404,
      );
    }
    return _requestFromJson(raw);
  }

  Future<SupportRequest> create({
    required String subject,
    required String message,
    String? bookingUuid,
  }) async {
    final body = <String, dynamic>{'subject': subject, 'message': message};
    if (bookingUuid != null && bookingUuid.isNotEmpty) {
      body['booking_uuid'] = bookingUuid;
    }
    final data = await _client.post(
      '/support-requests',
      body: body,
      auth: true,
    );
    final raw = data?['support_request'];
    if (raw is! Map<String, dynamic>) {
      throw const ApiException(message: 'Failed to create support request.');
    }
    return _requestFromJson(raw);
  }

  Future<SupportMessage> sendMessage({
    required String requestUuid,
    required String message,
  }) async {
    final data = await _client.post(
      '/support-requests/${Uri.encodeComponent(requestUuid)}/messages',
      body: {'message': message},
      auth: true,
    );
    final raw = data?['message'];
    if (raw is! Map<String, dynamic>) {
      throw const ApiException(message: 'Failed to send message.');
    }
    return _messageFromJson(raw);
  }

  static SupportRequest _requestFromJson(Map<String, dynamic> json) {
    final statusStr = json['status'] as String? ?? 'OPEN';
    final status = switch (statusStr) {
      'IN_PROGRESS' => SupportStatus.inProgress,
      'RESOLVED' || 'CLOSED' => SupportStatus.resolved,
      _ => SupportStatus.open,
    };

    final rawMessages = json['messages'] as List<dynamic>? ?? const [];
    final messages = rawMessages
        .whereType<Map<String, dynamic>>()
        .map(_messageFromJson)
        .toList();

    final createdAt = DateTime.tryParse(json['created_at'] as String? ?? '');
    final updatedAt = DateTime.tryParse(json['updated_at'] as String? ?? '');
    final messageCount =
        (json['message_count'] as num?)?.toInt() ?? messages.length;

    final openedLabel = createdAt != null
        ? 'opened ${blueSupportDay(createdAt.toLocal())}'
        : '';

    final listMeta = _buildListMeta(updatedAt?.toLocal(), messageCount, status);

    return SupportRequest(
      id: json['uuid'] as String? ?? '',
      number: json['request_number'] as String? ?? '',
      subject: json['subject'] as String? ?? '',
      status: status,
      listMeta: listMeta,
      openedLabel: openedLabel,
      messages: messages,
    );
  }

  static SupportMessage _messageFromJson(Map<String, dynamic> json) {
    final fromSupport = json['from_support'] == true;
    final createdAt = DateTime.tryParse(
      json['created_at'] as String? ?? '',
    )?.toLocal();
    final timeLabel = createdAt != null &&
            DateTime.now().difference(createdAt).inMinutes < 1
        ? 'Just now'
        : createdAt != null
        ? '${blueSupportDay(createdAt)} · ${_clock(createdAt)}'
        : 'Just now';

    return SupportMessage(
      fromSupport: fromSupport,
      time: timeLabel,
      text: json['message_body'] as String? ?? '',
    );
  }

  static String _clock(DateTime dt) {
    return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
  }

  static String _buildListMeta(
    DateTime? updatedAt,
    int messageCount,
    SupportStatus status,
  ) {
    if (status == SupportStatus.resolved) {
      if (updatedAt != null) {
        return 'Closed ${blueSupportDay(updatedAt)}';
      }
      return 'Closed';
    }

    if (status == SupportStatus.open) {
      if (updatedAt != null) {
        return 'Sent ${blueSupportDay(updatedAt.toLocal())} · awaiting reply';
      }
      return 'Awaiting reply';
    }

    final parts = <String>[];
    if (updatedAt != null) {
      final now = DateTime.now();
      final diff = now.difference(updatedAt);
      if (diff.inDays == 0) {
        parts.add('Updated today');
      } else if (diff.inDays == 1) {
        parts.add('Updated yesterday');
      } else {
        parts.add('Updated ${diff.inDays} days ago');
      }
    }
    if (messageCount > 0) {
      parts.add('$messageCount ${messageCount == 1 ? 'message' : 'messages'}');
    }
    return parts.isEmpty ? '' : parts.join(' · ');
  }
}
