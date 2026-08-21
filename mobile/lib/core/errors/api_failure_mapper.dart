import 'package:dio/dio.dart';

import 'api_failure.dart';

/// The one place a [DioException] is translated into the app's
/// [ApiFailure] taxonomy (blueprint §16). Used by [ApiClient] and nowhere
/// else - repositories and UI code only ever see [ApiFailure].
ApiFailure mapDioExceptionToApiFailure(DioException exception) {
  switch (exception.type) {
    case DioExceptionType.connectionTimeout:
    case DioExceptionType.sendTimeout:
    case DioExceptionType.receiveTimeout:
    case DioExceptionType.transformTimeout:
      return const TimeoutFailure();
    case DioExceptionType.connectionError:
      return const NetworkUnavailable();
    case DioExceptionType.cancel:
      return const NetworkUnavailable();
    case DioExceptionType.badCertificate:
      return const NetworkUnavailable();
    case DioExceptionType.badResponse:
      return _mapResponse(exception.response);
    case DioExceptionType.unknown:
      return exception.response == null
          ? const NetworkUnavailable()
          : _mapResponse(exception.response);
  }
}

ApiFailure _mapResponse(Response<dynamic>? response) {
  final statusCode = response?.statusCode;

  if (statusCode == null) {
    return const NetworkUnavailable();
  }

  final body = response?.data;
  final envelope = body is Map ? body : const <String, dynamic>{};
  final message = envelope['message'] is String
      ? envelope['message'] as String
      : 'Something went wrong. Please try again.';

  switch (statusCode) {
    case 401:
      return const SessionExpired();
    case 404:
      return const NotFound();
    case 409:
      return Conflict(message);
    case 422:
      return ValidationOrBusinessRejection(
        message,
        _parseFieldErrors(envelope),
      );
    case 429:
      return const RateLimited();
    case 500:
    case 503:
      return const ServerError();
    default:
      return const ServerError();
  }
}

Map<String, List<String>>? _parseFieldErrors(Map<dynamic, dynamic> envelope) {
  final rawErrors = envelope['errors'];

  if (rawErrors is! Map) {
    return null;
  }

  final result = <String, List<String>>{};

  for (final entry in rawErrors.entries) {
    final field = entry.key.toString();
    final rawMessages = entry.value;

    result[field] = rawMessages is List
        ? rawMessages.map((message) => message.toString()).toList()
        : <String>[rawMessages.toString()];
  }

  return result;
}
