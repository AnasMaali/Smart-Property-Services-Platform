/// The deployment environment this build was configured for.
///
/// Set at compile time via `--dart-define=APP_ENV=...` - never read from a
/// runtime file, since a committed secret-bearing .env is explicitly
/// disallowed for this app (see docs/flutter/flutter-integration-blueprint-v1.md
/// §7/§18).
enum Environment {
  dev,
  staging,
  production;

  static Environment parse(String raw) {
    return switch (raw.trim().toLowerCase()) {
      'production' || 'prod' => Environment.production,
      'staging' || 'stage' => Environment.staging,
      _ => Environment.dev,
    };
  }
}

/// Thrown when the application is configured in a way that must never be
/// allowed to boot - most importantly, a production build silently pointed
/// at a local development backend. Deliberately a plain [Error] (not an
/// [Exception]): this represents a build/deploy mistake, not a recoverable
/// runtime condition, and is intended to crash the app loudly at startup
/// rather than be caught and degrade silently.
class AppConfigError extends Error {
  AppConfigError(this.message);

  final String message;

  @override
  String toString() => 'AppConfigError: $message';
}

/// Centralizes every environment-dependent value the app needs, so no
/// feature/repository ever reads `String.fromEnvironment` or hardcodes a
/// base URL itself (blueprint §7/§8).
///
/// Two construction paths, deliberately kept separate:
///  - [AppConfig.new] is a plain constructor that validates its arguments.
///    Tests use this directly with literal strings.
///  - [AppConfig.fromEnvironment] is the only caller of
///    `String.fromEnvironment`, which is compile-time-only and can't be
///    varied per test case.
class AppConfig {
  AppConfig({required this.environment, required this.apiBaseUrl}) {
    _validate();
  }

  factory AppConfig.fromEnvironment() {
    const environmentName = String.fromEnvironment(
      'APP_ENV',
      defaultValue: 'dev',
    );
    const apiBaseUrl = String.fromEnvironment(
      'API_BASE_URL',
      defaultValue: 'http://10.0.2.2:8000/api',
    );

    return AppConfig(
      environment: Environment.parse(environmentName),
      apiBaseUrl: apiBaseUrl,
    );
  }

  final Environment environment;

  /// The backend API base URL, e.g. `http://10.0.2.2:8000/api` in local
  /// development. Every repository appends `/v1/...` to this - never a
  /// second, independently-hardcoded base URL anywhere in the app.
  final String apiBaseUrl;

  bool get isProduction => environment == Environment.production;

  static const _localHostFragments = <String>[
    'localhost',
    '127.0.0.1',
    '10.0.2.2',
  ];

  void _validate() {
    if (!isProduction) {
      return;
    }

    if (!apiBaseUrl.startsWith('https://')) {
      throw AppConfigError(
        'Production builds must use an https:// API base URL. '
        'Got: "$apiBaseUrl".',
      );
    }

    final lowerUrl = apiBaseUrl.toLowerCase();

    for (final fragment in _localHostFragments) {
      if (lowerUrl.contains(fragment)) {
        throw AppConfigError(
          'Production builds must never point at a local development host '
          '("$fragment" found in "$apiBaseUrl").',
        );
      }
    }
  }
}
