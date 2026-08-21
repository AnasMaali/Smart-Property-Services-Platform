/// Storage boundary for the two credentials the app ever persists: the
/// access token and the refresh token (blueprint §3). Never stores
/// passwords, OTP codes, `current_password`, or a Stripe `client_secret` -
/// nothing in this app should ever call an implementation of this interface
/// with any of those values.
///
/// An abstract interface (rather than a concrete class used directly) so
/// tests can inject an in-memory fake instead of exercising a real OS
/// Keychain/Keystore (blueprint §18, Phase 18 test requirement).
abstract class TokenStore {
  /// Persists both tokens together. Callers must always pass both - there
  /// is no `saveAccessToken`/`saveRefreshToken` pair, specifically so a
  /// caller can never update one token while leaving the other stale.
  Future<void> saveTokenPair({
    required String accessToken,
    required String refreshToken,
  });

  Future<String?> readAccessToken();

  Future<String?> readRefreshToken();

  /// Removes both tokens. Called after an irrecoverable refresh failure or
  /// account deletion - must always leave the store in the same empty state
  /// as before any token was ever saved.
  Future<void> clear();
}
