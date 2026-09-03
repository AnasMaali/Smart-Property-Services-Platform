import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../session/auth_session.dart';
import '../session/session_store.dart';
import 'api_exception.dart';

typedef TokenRefresher = Future<AuthSession?> Function(String refreshToken);

class ApiClient {
  ApiClient({
    required this.baseUrl,
    required this.sessionStore,
    http.Client? httpClient,
    this.onRefresh,
    this.timeout = const Duration(seconds: 20),
  }) : _http = httpClient ?? http.Client();

  final String baseUrl;
  final SessionStore sessionStore;
  TokenRefresher? onRefresh;
  final http.Client _http;
  final Duration timeout;

  Future<Map<String, dynamic>?> post(
    String path, {
    Map<String, dynamic>? body,
    bool auth = false,
    Map<String, String>? headers,
  }) {
    return send('POST', path, body: body, auth: auth, headers: headers);
  }

  Future<Map<String, dynamic>?> get(
    String path, {
    bool auth = false,
    Map<String, String>? query,
  }) {
    final encoded = _withQuery(path, query);
    return send('GET', encoded, auth: auth);
  }

  Future<Map<String, dynamic>?> patch(
    String path, {
    Map<String, dynamic>? body,
    bool auth = false,
  }) {
    return send('PATCH', path, body: body, auth: auth);
  }

  Future<Map<String, dynamic>?> put(
    String path, {
    Map<String, dynamic>? body,
    bool auth = false,
  }) {
    return send('PUT', path, body: body, auth: auth);
  }

  Future<Map<String, dynamic>?> delete(
    String path, {
    Map<String, dynamic>? body,
    bool auth = false,
  }) {
    return send('DELETE', path, body: body, auth: auth);
  }

  String _withQuery(String path, Map<String, String>? query) {
    if (query == null || query.isEmpty) return path;
    final filtered = <String, String>{};
    query.forEach((key, value) {
      if (value.isNotEmpty) filtered[key] = value;
    });
    if (filtered.isEmpty) return path;
    return '$path?${Uri(queryParameters: filtered).query}';
  }

  Future<Map<String, dynamic>?> send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    bool auth = false,
    Map<String, String>? headers,
  }) async {
    var response = await _request(
      method,
      path,
      body: body,
      auth: auth,
      headers: headers,
    );

    if (auth && response.statusCode == 401 && onRefresh != null) {
      final refreshToken = sessionStore.current?.refreshToken;
      if (refreshToken != null && refreshToken.isNotEmpty) {
        final next = await onRefresh!(refreshToken);
        if (next != null) {
          response = await _request(
            method,
            path,
            body: body,
            auth: auth,
            headers: headers,
          );
        }
      }
    }

    return _decode(response);
  }

  Future<http.Response> _request(
    String method,
    String path, {
    Map<String, dynamic>? body,
    required bool auth,
    Map<String, String>? headers,
  }) async {
    final uri = Uri.parse('$baseUrl$path');
    final requestHeaders = <String, String>{
      'Accept': 'application/json',
      if (body != null) 'Content-Type': 'application/json',
      ...?headers,
    };
    if (auth) {
      final token = sessionStore.current?.accessToken;
      if (token != null && token.isNotEmpty) {
        requestHeaders['Authorization'] = 'Bearer $token';
      }
    }

    try {
      final request = http.Request(method, uri)..headers.addAll(requestHeaders);
      if (body != null) {
        request.body = jsonEncode(body);
      }
      final streamed = await _http.send(request).timeout(timeout);
      return http.Response.fromStream(streamed).timeout(timeout);
    } on TimeoutException {
      throw const ApiException(
        message: 'The server took too long to respond. Try again.',
      );
    } on http.ClientException {
      throw const ApiException(
        message: "Can't reach the server. Make sure the backend is running.",
      );
    } on ApiException {
      rethrow;
    } catch (_) {
      throw const ApiException(
        message: "Can't reach the server. Make sure the backend is running.",
      );
    }
  }

  Map<String, dynamic>? _decode(http.Response response) {
    if (response.body.isEmpty) {
      if (response.statusCode >= 200 && response.statusCode < 300) {
        return null;
      }
      throw ApiException(
        statusCode: response.statusCode,
        message: 'Something went wrong. Please try again.',
      );
    }

    late final Map<String, dynamic> json;
    try {
      final decoded = jsonDecode(response.body);
      if (decoded is! Map<String, dynamic>) {
        throw const FormatException('Unexpected payload');
      }
      json = decoded;
    } on FormatException {
      throw ApiException(
        statusCode: response.statusCode,
        message: 'Something went wrong. Please try again.',
      );
    }

    final success = json['success'] == true;
    final message = (json['message'] as String?)?.trim();
    final errors = _fieldErrors(json['errors']);

    if (response.statusCode >= 400 || !success) {
      throw ApiException(
        statusCode: response.statusCode,
        message: (message == null || message.isEmpty)
            ? 'Something went wrong. Please try again.'
            : message,
        fieldErrors: errors,
      );
    }

    final data = json['data'];
    if (data == null) return null;
    if (data is Map<String, dynamic>) return data;
    throw const ApiException(
      message: 'Something went wrong. Please try again.',
    );
  }

  Map<String, List<String>> _fieldErrors(Object? raw) {
    if (raw is! Map) return const {};
    final out = <String, List<String>>{};
    raw.forEach((key, value) {
      if (value is List) {
        out['$key'] = value.map((item) => '$item').toList();
      } else if (value != null) {
        out['$key'] = ['$value'];
      }
    });
    return out;
  }
}
