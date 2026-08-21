import 'package:flutter_test/flutter_test.dart';

import '../../support/fake_token_store.dart';

void main() {
  group('TokenStore contract (via FakeTokenStore)', () {
    test('reads are null before any save', () async {
      final store = FakeTokenStore();

      expect(await store.readAccessToken(), isNull);
      expect(await store.readRefreshToken(), isNull);
    });

    test('saveTokenPair round-trips both tokens', () async {
      final store = FakeTokenStore();

      await store.saveTokenPair(
        accessToken: 'access-1',
        refreshToken: 'refresh-1',
      );

      expect(await store.readAccessToken(), 'access-1');
      expect(await store.readRefreshToken(), 'refresh-1');
    });

    test('saveTokenPair replaces both tokens together on rotation', () async {
      final store = FakeTokenStore();

      await store.saveTokenPair(
        accessToken: 'access-1',
        refreshToken: 'refresh-1',
      );
      await store.saveTokenPair(
        accessToken: 'access-2',
        refreshToken: 'refresh-2',
      );

      expect(await store.readAccessToken(), 'access-2');
      expect(await store.readRefreshToken(), 'refresh-2');
    });

    test('clear empties both tokens', () async {
      final store = FakeTokenStore();

      await store.saveTokenPair(
        accessToken: 'access-1',
        refreshToken: 'refresh-1',
      );
      await store.clear();

      expect(await store.readAccessToken(), isNull);
      expect(await store.readRefreshToken(), isNull);
    });
  });
}
