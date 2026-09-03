class RegisterResult {
  const RegisterResult({
    required this.userUuid,
    required this.phoneNumber,
    required this.otpVerificationUuid,
    required this.otpExpiresAt,
  });

  final String userUuid;
  final String phoneNumber;
  final String otpVerificationUuid;
  final DateTime? otpExpiresAt;

  factory RegisterResult.fromJson(Map<String, dynamic> json) {
    return RegisterResult(
      userUuid: json['user_uuid'] as String,
      phoneNumber: json['phone_number'] as String,
      otpVerificationUuid: json['otp_verification_uuid'] as String,
      otpExpiresAt: _parseTime(json['otp_expires_at']),
    );
  }
}

class ResendOtpResult {
  const ResendOtpResult({
    required this.otpVerificationUuid,
    required this.phoneNumber,
    required this.otpExpiresAt,
    required this.resendAvailableAt,
  });

  final String otpVerificationUuid;
  final String phoneNumber;
  final DateTime? otpExpiresAt;
  final DateTime? resendAvailableAt;

  factory ResendOtpResult.fromJson(Map<String, dynamic> json) {
    return ResendOtpResult(
      otpVerificationUuid: json['otp_verification_uuid'] as String,
      phoneNumber: json['phone_number'] as String? ?? '',
      otpExpiresAt: _parseTime(json['otp_expires_at']),
      resendAvailableAt: _parseTime(json['resend_available_at']),
    );
  }
}

class AccountDeletionResult {
  const AccountDeletionResult({required this.pending, this.requestedAt});

  final bool pending;
  final String? requestedAt;
}

class PhoneNumberChangeRequestResult {
  const PhoneNumberChangeRequestResult({
    required this.otpVerificationUuid,
    required this.newPhoneNumber,
    this.otpExpiresAt,
    this.resendAvailableAt,
  });

  final String otpVerificationUuid;
  final String newPhoneNumber;
  final DateTime? otpExpiresAt;
  final DateTime? resendAvailableAt;

  factory PhoneNumberChangeRequestResult.fromJson(Map<String, dynamic> json) {
    return PhoneNumberChangeRequestResult(
      otpVerificationUuid: json['otp_verification_uuid'] as String,
      newPhoneNumber: json['new_phone_number'] as String? ?? '',
      otpExpiresAt: _parseTime(json['otp_expires_at']),
      resendAvailableAt: _parseTime(json['resend_available_at']),
    );
  }
}

class PhoneNumberChangeVerifyResult {
  const PhoneNumberChangeVerifyResult({required this.phoneNumber});

  final String phoneNumber;

  factory PhoneNumberChangeVerifyResult.fromJson(Map<String, dynamic> json) {
    return PhoneNumberChangeVerifyResult(
      phoneNumber: json['phone_number'] as String? ?? '',
    );
  }
}

DateTime? _parseTime(Object? value) {
  if (value is String && value.isNotEmpty) {
    return DateTime.tryParse(value);
  }
  return null;
}
