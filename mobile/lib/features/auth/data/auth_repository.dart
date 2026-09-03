import 'package:flutter/foundation.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_config.dart';
import '../../../core/session/auth_session.dart';
import '../../../core/session/session_store.dart';
import 'auth_models.dart';

class AuthRepository {
  AuthRepository({required this._client, required this._store}) {
    _session.value = _store.current;
    _client.onRefresh = _refreshFromToken;
  }

  final ApiClient _client;
  final SessionStore _store;
  final ValueNotifier<AuthSession?> _session = ValueNotifier<AuthSession?>(
    null,
  );

  ValueListenable<AuthSession?> get listenable => _session;

  AuthSession? get current => _session.value;

  Future<void> restore() async {
    await _store.load();
    final stored = _store.current;
    if (stored == null) {
      _session.value = null;
      return;
    }
    _session.value = stored;
    if (stored.accessTokenFresh) return;
    try {
      await _refreshFromToken(stored.refreshToken);
    } catch (_) {
      await _store.clear();
      _session.value = null;
    }
  }

  Future<void> requestLoginOtp({required String phoneNumber}) async {
    await _client.post(
      '/auth/login/request-otp',
      body: {'phone_number': phoneNumber},
    );
  }

  Future<void> resendLoginOtp({required String phoneNumber}) async {
    await _client.post(
      '/auth/login/resend-otp',
      body: {'phone_number': phoneNumber},
    );
  }

  Future<void> verifyLoginOtp({
    required String phoneNumber,
    required String otpCode,
  }) async {
    final data = await _client.post(
      '/auth/login/verify-otp',
      body: {
        'phone_number': phoneNumber,
        'otp_code': otpCode,
        'client_type': ApiConfig.clientType,
        'app_version': ApiConfig.appVersion,
      },
    );
    if (data == null) {
      throw StateError('Login response was empty.');
    }
    final session = AuthSession.fromJson(data);
    await _store.save(session);
    _session.value = session;
  }

  Future<RegisterResult> register({
    required String fullName,
    required String phoneNumber,
    required String email,
    required int cityId,
    required int areaId,
    required int propertyRelationshipTypeId,
    required List<int> serviceInterests,
  }) async {
    final data = await _client.post(
      '/auth/register',
      body: {
        'full_name': fullName,
        'phone_number': phoneNumber,
        'email': email,
        'city_id': cityId,
        'area_id': areaId,
        'property_relationship_type_id': propertyRelationshipTypeId,
        'service_interests': serviceInterests,
      },
    );
    if (data == null) {
      throw StateError('Register response was empty.');
    }
    return RegisterResult.fromJson(data);
  }

  Future<void> verifyPhone({
    required String otpVerificationUuid,
    required String otpCode,
  }) async {
    await _client.post(
      '/auth/verify-phone',
      body: {'otp_verification_uuid': otpVerificationUuid, 'otp_code': otpCode},
    );
  }

  Future<ResendOtpResult> resendOtp({
    required String otpVerificationUuid,
  }) async {
    final data = await _client.post(
      '/auth/resend-otp',
      body: {'otp_verification_uuid': otpVerificationUuid},
    );
    if (data == null) {
      throw StateError('Resend OTP response was empty.');
    }
    return ResendOtpResult.fromJson(data);
  }

  Future<void> syncIdentity({
    String? fullName,
    String? email,
    String? phoneNumber,
  }) async {
    final current = _session.value;
    if (current == null) return;
    final next = current.copyWith(
      fullName: fullName,
      email: email,
      phoneNumber: phoneNumber,
    );
    await _store.save(next);
    _session.value = next;
  }

  Future<PhoneNumberChangeRequestResult> requestPhoneNumberChange({
    required String newPhoneNumber,
  }) async {
    final data = await _client.post(
      '/auth/change-phone-number',
      auth: true,
      body: {'new_phone_number': newPhoneNumber},
    );
    if (data == null) {
      throw StateError('Phone number change response was empty.');
    }
    return PhoneNumberChangeRequestResult.fromJson(data);
  }

  Future<PhoneNumberChangeRequestResult> resendPhoneNumberChangeOtp({
    required String otpVerificationUuid,
  }) async {
    final data = await _client.post(
      '/auth/resend-phone-number-change-otp',
      auth: true,
      body: {'otp_verification_uuid': otpVerificationUuid},
    );
    if (data == null) {
      throw StateError('Phone number change resend response was empty.');
    }
    return PhoneNumberChangeRequestResult.fromJson(data);
  }

  Future<PhoneNumberChangeVerifyResult> verifyPhoneNumberChangeOtp({
    required String otpVerificationUuid,
    required String otpCode,
  }) async {
    final data = await _client.post(
      '/auth/verify-phone-number-change-otp',
      auth: true,
      body: {'otp_verification_uuid': otpVerificationUuid, 'otp_code': otpCode},
    );
    if (data == null) {
      throw StateError('Phone number change verify response was empty.');
    }
    return PhoneNumberChangeVerifyResult.fromJson(data);
  }

  Future<void> logout() async {
    try {
      await _client.post('/auth/logout', auth: true);
    } catch (_) {
      // Local sign-out still has to succeed if the token is already dead.
    }
    await _store.clear();
    _session.value = null;
  }

  Future<void> requestAccountDeletionOtp() async {
    await _client.post('/auth/account/request-otp', auth: true);
  }

  Future<void> resendAccountDeletionOtp() async {
    await _client.post('/auth/account/resend-otp', auth: true);
  }

  Future<AccountDeletionResult> deleteAccount({required String otpCode}) async {
    final data = await _client.delete(
      '/auth/account',
      auth: true,
      body: {'otp_code': otpCode},
    );
    final pending = data?['deletion_status'] == 'PENDING';
    if (!pending) {
      await _store.clear();
      _session.value = null;
    }
    return AccountDeletionResult(
      pending: pending,
      requestedAt: data?['requested_at'] as String?,
    );
  }

  Future<AuthSession?> _refreshFromToken(String refreshToken) async {
    final data = await _client.post(
      '/auth/refresh',
      body: {'refresh_token': refreshToken},
    );
    if (data == null) return null;
    final current = _store.current;
    if (current == null) return null;
    final next = current.copyWith(
      accessToken: data['access_token'] as String,
      accessTokenExpiresAt: DateTime.parse(
        data['access_token_expires_at'] as String,
      ),
      refreshToken: data['refresh_token'] as String,
      sessionUuid: data['session_uuid'] as String? ?? current.sessionUuid,
      sessionExpiresAt: data['session_expires_at'] is String
          ? DateTime.parse(data['session_expires_at'] as String)
          : current.sessionExpiresAt,
    );
    await _store.save(next);
    _session.value = next;
    return next;
  }
}
