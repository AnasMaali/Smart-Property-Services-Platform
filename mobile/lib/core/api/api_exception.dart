class ApiException implements Exception {
  const ApiException({
    required this.message,
    this.statusCode = 0,
    this.fieldErrors = const {},
  });

  final String message;
  final int statusCode;
  final Map<String, List<String>> fieldErrors;

  String get displayMessage {
    for (final messages in fieldErrors.values) {
      if (messages.isNotEmpty && messages.first.trim().isNotEmpty) {
        return messages.first;
      }
    }
    return message;
  }

  String? field(String name) {
    final messages = fieldErrors[name];
    if (messages == null || messages.isEmpty) return null;
    return messages.first;
  }

  bool get isNetwork => statusCode == 0;

  @override
  String toString() => 'ApiException($statusCode: $message)';
}
