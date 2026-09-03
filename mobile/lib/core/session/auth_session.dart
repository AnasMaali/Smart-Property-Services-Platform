class AuthSession {
  const AuthSession({
    required this.userUuid,
    required this.fullName,
    required this.phoneNumber,
    required this.email,
    required this.role,
    required this.sessionUuid,
    required this.accessToken,
    required this.accessTokenExpiresAt,
    required this.refreshToken,
    required this.sessionExpiresAt,
  });

  final String userUuid;
  final String fullName;
  final String phoneNumber;
  final String email;
  final String role;
  final String sessionUuid;
  final String accessToken;
  final DateTime accessTokenExpiresAt;
  final String refreshToken;
  final DateTime sessionExpiresAt;

  bool get accessTokenFresh {
    return DateTime.now().toUtc().isBefore(
      accessTokenExpiresAt.toUtc().subtract(const Duration(seconds: 30)),
    );
  }

  AuthSession copyWith({
    String? accessToken,
    DateTime? accessTokenExpiresAt,
    String? refreshToken,
    String? sessionUuid,
    DateTime? sessionExpiresAt,
    String? fullName,
    String? phoneNumber,
    String? email,
  }) {
    return AuthSession(
      userUuid: userUuid,
      fullName: fullName ?? this.fullName,
      phoneNumber: phoneNumber ?? this.phoneNumber,
      email: email ?? this.email,
      role: role,
      sessionUuid: sessionUuid ?? this.sessionUuid,
      accessToken: accessToken ?? this.accessToken,
      accessTokenExpiresAt: accessTokenExpiresAt ?? this.accessTokenExpiresAt,
      refreshToken: refreshToken ?? this.refreshToken,
      sessionExpiresAt: sessionExpiresAt ?? this.sessionExpiresAt,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'user_uuid': userUuid,
      'full_name': fullName,
      'phone_number': phoneNumber,
      'email': email,
      'role': role,
      'session_uuid': sessionUuid,
      'access_token': accessToken,
      'access_token_expires_at': accessTokenExpiresAt.toIso8601String(),
      'refresh_token': refreshToken,
      'session_expires_at': sessionExpiresAt.toIso8601String(),
    };
  }

  factory AuthSession.fromJson(Map<String, dynamic> json) {
    return AuthSession(
      userUuid: json['user_uuid'] as String,
      fullName: json['full_name'] as String,
      phoneNumber: json['phone_number'] as String,
      email: json['email'] as String,
      role: json['role'] as String? ?? 'CUSTOMER',
      sessionUuid: json['session_uuid'] as String,
      accessToken: json['access_token'] as String,
      accessTokenExpiresAt: DateTime.parse(
        json['access_token_expires_at'] as String,
      ),
      refreshToken: json['refresh_token'] as String,
      sessionExpiresAt: DateTime.parse(json['session_expires_at'] as String),
    );
  }
}
