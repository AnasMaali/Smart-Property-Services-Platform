import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../errors/api_failure.dart';
import '../errors/api_failure_mapper.dart';
import '../storage/token_store.dart';
import 'auth_interceptor.dart';

/// The single HTTP client every future repository builds on
/// (blueprint §14). Owns base URL, default headers, timeouts, bearer-token
/// attachment (via [AuthInterceptor]), and centralized error translation
/// into [ApiFailure] - no feature endpoint methods live here yet (F1 has no
/// repositories).
class ApiClient {
  ApiClient({
    required String baseUrl,
    required TokenStore tokenStore,
    required void Function() onSessionExpired,
  }) : _dio = Dio(
         BaseOptions(
           baseUrl: baseUrl,
           connectTimeout: const Duration(seconds: 10),
           sendTimeout: const Duration(seconds: 15),
           receiveTimeout: const Duration(seconds: 15),
           headers: const {
             'Content-Type': 'application/json',
             'Accept': 'application/json',
           },
         ),
       ) {
    _dio.interceptors.add(
      AuthInterceptor(
        tokenStore: tokenStore,
        // A bare Dio instance with no interceptors, used only for the
        // refresh call itself - see AuthInterceptor's class doc for why
        // this must never be `_dio`.
        refreshDio: Dio(BaseOptions(baseUrl: baseUrl)),
        retryDio: _dio,
        onSessionExpired: onSessionExpired,
      ),
    );

    if (kDebugMode) {
      _dio.interceptors.add(
        LogInterceptor(
          requestHeader: false,
          responseHeader: false,
          requestBody: false,
          responseBody: false,
        ),
      );
    }
  }

  final Dio _dio;

  Future<Response<dynamic>> get(
    String path, {
    Map<String, dynamic>? queryParameters,
  }) {
    return _run(
      () => _dio.get<dynamic>(path, queryParameters: queryParameters),
    );
  }

  Future<Response<dynamic>> post(
    String path, {
    Object? data,
    Options? options,
  }) {
    return _run(() => _dio.post<dynamic>(path, data: data, options: options));
  }

  Future<Response<dynamic>> patch(String path, {Object? data}) {
    return _run(() => _dio.patch<dynamic>(path, data: data));
  }

  Future<Response<dynamic>> delete(String path, {Object? data}) {
    return _run(() => _dio.delete<dynamic>(path, data: data));
  }

  Future<Response<dynamic>> _run(
    Future<Response<dynamic>> Function() request,
  ) async {
    try {
      return await request();
    } on DioException catch (exception) {
      throw ApiException(mapDioExceptionToApiFailure(exception));
    }
  }
}
