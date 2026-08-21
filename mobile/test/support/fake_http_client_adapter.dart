import 'dart:typed_data';

import 'package:dio/dio.dart';

/// A canned response for one call to a registered path.
class CannedResponse {
  const CannedResponse(this.statusCode, this.body);

  final int statusCode;
  final String body;
}

/// A hand-rolled fake [HttpClientAdapter] for testing [AuthInterceptor]'s
/// concurrency behavior without any real network call. Responses are
/// resolved per-path via a callback given the 1-indexed call number for
/// that path, and every call is counted so tests can assert exactly how
/// many times a path (most importantly `/v1/auth/refresh`) was hit.
class FakeHttpClientAdapter implements HttpClientAdapter {
  final Map<String, int> hitCounts = {};
  final Map<String, CannedResponse Function(int callNumber)> _responders = {};

  void onPath(String path, CannedResponse Function(int callNumber) responder) {
    _responders[path] = responder;
  }

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final path = options.path;
    final callNumber = (hitCounts[path] ?? 0) + 1;
    hitCounts[path] = callNumber;

    final responder = _responders[path];

    if (responder == null) {
      throw StateError(
        'FakeHttpClientAdapter: no responder registered for $path',
      );
    }

    final canned = responder(callNumber);

    return ResponseBody.fromString(
      canned.body,
      canned.statusCode,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
