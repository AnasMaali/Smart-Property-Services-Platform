import 'package:blue/core/network/auth_interceptor.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../support/fake_http_client_adapter.dart';
import '../../support/fake_token_store.dart';

const _refreshPath = '/v1/auth/refresh';

const _tokenPairBody = '''
{
  "success": true,
  "message": "Access token refreshed successfully.",
  "data": {
    "access_token": "new-access-token",
    "access_token_expires_at": "2026-01-01T00:15:00+00:00",
    "refresh_token": "new-refresh-token",
    "session_uuid": "session-uuid",
    "session_expires_at": "2026-01-31T00:00:00+00:00"
  }
}
''';

const _refreshRejectedBody = '''
{
  "success": false,
  "message": "This refresh token is invalid or has expired.",
  "data": null
}
''';

const _unauthorizedBody = '''
{ "success": false, "message": "This session is invalid or has expired." }
''';

/// Wires a fresh [FakeHttpClientAdapter] + [FakeTokenStore] + [AuthInterceptor]
/// triple, mirroring exactly how [ApiClient] composes them - a "main" Dio
/// with the interceptor attached (used for real requests and retries) and a
/// separate, interceptor-free "refresh" Dio (used only for the refresh
/// call itself).
class _Harness {
  _Harness({required void Function() onSessionExpired}) {
    mainDio = Dio(BaseOptions(baseUrl: 'https://blue.test'))
      ..httpClientAdapter = adapter;
    final refreshDio = Dio(BaseOptions(baseUrl: 'https://blue.test'))
      ..httpClientAdapter = adapter;

    mainDio.interceptors.add(
      AuthInterceptor(
        tokenStore: tokenStore,
        refreshDio: refreshDio,
        retryDio: mainDio,
        onSessionExpired: onSessionExpired,
      ),
    );
  }

  final adapter = FakeHttpClientAdapter();
  final tokenStore = FakeTokenStore();
  late final Dio mainDio;
}

void main() {
  group('AuthInterceptor - single-flight refresh', () {
    test(
      'N concurrent 401s trigger exactly one refresh call, '
      'all requests eventually succeed, and the new token pair is stored',
      () async {
        var sessionExpiredCount = 0;
        final harness = _Harness(onSessionExpired: () => sessionExpiredCount++);
        await harness.tokenStore.saveTokenPair(
          accessToken: 'old-access-token',
          refreshToken: 'old-refresh-token',
        );

        const paths = ['/a', '/b', '/c', '/d', '/e'];
        for (final path in paths) {
          harness.adapter.onPath(
            path,
            (callNumber) => callNumber == 1
                ? const CannedResponse(401, _unauthorizedBody)
                : const CannedResponse(
                    200,
                    '{"success":true,"message":"ok","data":{"value":"done"}}',
                  ),
          );
        }
        harness.adapter.onPath(
          _refreshPath,
          (_) => const CannedResponse(200, _tokenPairBody),
        );

        final responses = await Future.wait(
          paths.map((path) => harness.mainDio.get<dynamic>(path)),
        );

        expect(responses, hasLength(paths.length));
        for (final response in responses) {
          expect(response.statusCode, 200);
        }

        expect(harness.adapter.hitCounts[_refreshPath], 1);
        expect(await harness.tokenStore.readAccessToken(), 'new-access-token');
        expect(
          await harness.tokenStore.readRefreshToken(),
          'new-refresh-token',
        );
        expect(sessionExpiredCount, 0);
      },
    );

    test('a request that still 401s after the retry fails as a hard failure '
        'without triggering a second refresh', () async {
      var sessionExpiredCount = 0;
      final harness = _Harness(onSessionExpired: () => sessionExpiredCount++);
      await harness.tokenStore.saveTokenPair(
        accessToken: 'old-access-token',
        refreshToken: 'old-refresh-token',
      );

      harness.adapter.onPath(
        '/still-unauthorized',
        (_) => const CannedResponse(401, _unauthorizedBody),
      );
      harness.adapter.onPath(
        _refreshPath,
        (_) => const CannedResponse(200, _tokenPairBody),
      );

      DioException? caught;
      try {
        await harness.mainDio.get<dynamic>('/still-unauthorized');
      } on DioException catch (e) {
        caught = e;
      }

      expect(caught, isNotNull);
      expect(caught!.response?.statusCode, 401);
      // Original call + exactly one retry - never more.
      expect(harness.adapter.hitCounts['/still-unauthorized'], 2);
      // The refresh itself is never attempted a second time for this
      // already-retried request.
      expect(harness.adapter.hitCounts[_refreshPath], 1);
      expect(sessionExpiredCount, 0);
    });

    test(
      'a failed refresh clears stored tokens, notifies exactly once even '
      'with several requests queued behind it, and rejects every request',
      () async {
        var sessionExpiredCount = 0;
        final harness = _Harness(onSessionExpired: () => sessionExpiredCount++);
        await harness.tokenStore.saveTokenPair(
          accessToken: 'old-access-token',
          refreshToken: 'old-refresh-token',
        );

        const paths = ['/x', '/y', '/z'];
        for (final path in paths) {
          harness.adapter.onPath(
            path,
            (_) => const CannedResponse(401, _unauthorizedBody),
          );
        }
        harness.adapter.onPath(
          _refreshPath,
          (_) => const CannedResponse(422, _refreshRejectedBody),
        );

        final statusCodes = await Future.wait(
          paths.map((path) async {
            try {
              await harness.mainDio.get<dynamic>(path);
              return null;
            } on DioException catch (e) {
              return e.response?.statusCode;
            }
          }),
        );

        expect(statusCodes, [401, 401, 401]);
        expect(harness.adapter.hitCounts[_refreshPath], 1);
        expect(sessionExpiredCount, 1);
        expect(await harness.tokenStore.readAccessToken(), isNull);
        expect(await harness.tokenStore.readRefreshToken(), isNull);
      },
    );
  });
}
