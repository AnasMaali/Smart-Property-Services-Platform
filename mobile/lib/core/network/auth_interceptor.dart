import 'dart:async';

import 'package:dio/dio.dart';

import '../storage/token_store.dart';

/// Implements the single-flight 401 -> refresh -> retry state machine from
/// blueprint §3, against the real contract in
/// docs/api-contracts/authentication-v1.md §5 (`POST /v1/auth/refresh`).
///
/// Deliberately plain Dart with zero Riverpod import - it only ever calls
/// the injected [onSessionExpired] callback, never reads/writes a provider
/// directly. This keeps it trivially unit-testable without a
/// `ProviderContainer` and is what keeps `core/network` from depending on
/// anything Riverpod-specific beyond its own provider file.
///
/// Rules this class exists to enforce (never violate any of these):
///  - exactly ONE refresh call may be in flight at a time; concurrent 401s
///    queue behind it (single-flight `Completer`)
///  - the original request is retried at most ONCE after a successful
///    refresh - a second 401 after that is a hard failure, never a loop
///  - the refresh call itself uses a bare Dio instance with NO interceptors,
///    so it can never recursively trigger this same 401 handling
///  - a successful refresh atomically replaces BOTH stored tokens together
///  - a failed refresh clears both stored tokens and notifies exactly once,
///    even when multiple requests were queued behind it
class AuthInterceptor extends Interceptor {
  AuthInterceptor({
    required this.tokenStore,
    required this.refreshDio,
    required this.retryDio,
    required this.onSessionExpired,
  });

  final TokenStore tokenStore;

  /// A `Dio` instance with no interceptors attached, used only to call
  /// `POST /v1/auth/refresh`. Never the same pipeline a normal request goes
  /// through - see the class doc for why this matters.
  final Dio refreshDio;

  /// The main, interceptor-attached `Dio` instance this interceptor itself
  /// is registered on, used only to retry an original request once after a
  /// successful refresh.
  final Dio retryDio;

  /// Invoked exactly once per irrecoverable refresh failure, however many
  /// requests were queued behind it. Wired to
  /// `sessionStatusProvider.notifier` at the provider-composition layer -
  /// never called directly from here.
  final void Function() onSessionExpired;

  static const _retriedFlag = 'blueAuthRetried';

  Completer<bool>? _refreshCompleter;

  @override
  void onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final accessToken = await tokenStore.readAccessToken();

    if (accessToken != null) {
      options.headers['Authorization'] = 'Bearer $accessToken';
    }

    handler.next(options);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) async {
    if (err.response?.statusCode != 401) {
      handler.next(err);
      return;
    }

    final alreadyRetried = err.requestOptions.extra[_retriedFlag] == true;

    if (alreadyRetried) {
      handler.next(err);
      return;
    }

    final refreshed = await _refreshTokenPair();

    if (!refreshed) {
      handler.next(err);
      return;
    }

    try {
      final retryOptions = err.requestOptions;
      retryOptions.extra[_retriedFlag] = true;
      final retryResponse = await retryDio.fetch<dynamic>(retryOptions);
      handler.resolve(retryResponse);
    } on DioException catch (retryError) {
      handler.next(retryError);
    }
  }

  /// Single-flight refresh: the check-and-set of [_refreshCompleter] below
  /// happens synchronously (no `await` in between), which is what lets
  /// Dart's single-threaded event loop guarantee two concurrent calls can
  /// never both start a refresh.
  Future<bool> _refreshTokenPair() {
    final existing = _refreshCompleter;

    if (existing != null) {
      return existing.future;
    }

    final completer = Completer<bool>();
    _refreshCompleter = completer;
    unawaited(_performRefresh(completer));

    return completer.future;
  }

  Future<void> _performRefresh(Completer<bool> completer) async {
    try {
      final refreshToken = await tokenStore.readRefreshToken();

      if (refreshToken == null) {
        await _fail();
        completer.complete(false);
        return;
      }

      final response = await refreshDio.post<Map<String, dynamic>>(
        '/v1/auth/refresh',
        data: {'refresh_token': refreshToken},
      );

      final data = response.data?['data'] as Map<String, dynamic>?;
      final newAccessToken = data?['access_token'] as String?;
      final newRefreshToken = data?['refresh_token'] as String?;

      if (newAccessToken == null || newRefreshToken == null) {
        await _fail();
        completer.complete(false);
        return;
      }

      await tokenStore.saveTokenPair(
        accessToken: newAccessToken,
        refreshToken: newRefreshToken,
      );
      completer.complete(true);
    } catch (_) {
      await _fail();
      completer.complete(false);
    } finally {
      _refreshCompleter = null;
    }
  }

  Future<void> _fail() async {
    await tokenStore.clear();
    onSessionExpired();
  }
}
