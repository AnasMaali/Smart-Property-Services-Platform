import 'package:blue/core/storage/token_store.dart';

/// In-memory [TokenStore] for tests - satisfies the same interface as
/// [SecureTokenStore] without touching a real OS Keychain/Keystore
/// (blueprint §18 test requirement).
class FakeTokenStore implements TokenStore {
  String? _accessToken;
  String? _refreshToken;

  @override
  Future<void> saveTokenPair({
    required String accessToken,
    required String refreshToken,
  }) async {
    _accessToken = accessToken;
    _refreshToken = refreshToken;
  }

  @override
  Future<String?> readAccessToken() async => _accessToken;

  @override
  Future<String?> readRefreshToken() async => _refreshToken;

  @override
  Future<void> clear() async {
    _accessToken = null;
    _refreshToken = null;
  }
}
