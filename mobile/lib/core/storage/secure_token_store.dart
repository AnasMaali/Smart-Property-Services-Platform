import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'token_store.dart';

/// [TokenStore] backed by the OS Keychain (iOS) / Keystore (Android) via
/// `flutter_secure_storage` - never `SharedPreferences` (blueprint §3/§18).
class SecureTokenStore implements TokenStore {
  SecureTokenStore({FlutterSecureStorage? storage})
    : _storage = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _storage;

  static const _accessTokenKey = 'blue.auth.access_token';
  static const _refreshTokenKey = 'blue.auth.refresh_token';

  @override
  Future<void> saveTokenPair({
    required String accessToken,
    required String refreshToken,
  }) async {
    // `flutter_secure_storage` has no native multi-key transaction, so
    // "atomic" here means: if either write fails, clear both keys rather
    // than risk leaving one new token paired with one stale token.
    try {
      await _storage.write(key: _refreshTokenKey, value: refreshToken);
      await _storage.write(key: _accessTokenKey, value: accessToken);
    } catch (_) {
      await clear();
      rethrow;
    }
  }

  @override
  Future<String?> readAccessToken() {
    return _storage.read(key: _accessTokenKey);
  }

  @override
  Future<String?> readRefreshToken() {
    return _storage.read(key: _refreshTokenKey);
  }

  @override
  Future<void> clear() async {
    await _storage.delete(key: _accessTokenKey);
    await _storage.delete(key: _refreshTokenKey);
  }
}
