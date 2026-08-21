import 'package:blue/core/config/app_config.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('AppConfig', () {
    test('dev environment accepts a local http URL', () {
      final config = AppConfig(
        environment: Environment.dev,
        apiBaseUrl: 'http://10.0.2.2:8000/api',
      );

      expect(config.environment, Environment.dev);
      expect(config.isProduction, isFalse);
    });

    test('staging environment accepts a local http URL', () {
      expect(
        () => AppConfig(
          environment: Environment.staging,
          apiBaseUrl: 'http://127.0.0.1:8000/api',
        ),
        returnsNormally,
      );
    });

    test('production environment accepts a real https URL', () {
      final config = AppConfig(
        environment: Environment.production,
        apiBaseUrl: 'https://api.blue.example.com/api',
      );

      expect(config.isProduction, isTrue);
    });

    test('production environment rejects a plain http URL', () {
      expect(
        () => AppConfig(
          environment: Environment.production,
          apiBaseUrl: 'http://api.blue.example.com/api',
        ),
        throwsA(isA<AppConfigError>()),
      );
    });

    test('production environment rejects 127.0.0.1', () {
      expect(
        () => AppConfig(
          environment: Environment.production,
          apiBaseUrl: 'https://127.0.0.1:8000/api',
        ),
        throwsA(isA<AppConfigError>()),
      );
    });

    test('production environment rejects the Android emulator host alias', () {
      expect(
        () => AppConfig(
          environment: Environment.production,
          apiBaseUrl: 'https://10.0.2.2:8000/api',
        ),
        throwsA(isA<AppConfigError>()),
      );
    });

    test('production environment rejects localhost', () {
      expect(
        () => AppConfig(
          environment: Environment.production,
          apiBaseUrl: 'https://localhost:8000/api',
        ),
        throwsA(isA<AppConfigError>()),
      );
    });
  });

  group('Environment.parse', () {
    test('parses known values case-insensitively', () {
      expect(Environment.parse('production'), Environment.production);
      expect(Environment.parse('PROD'), Environment.production);
      expect(Environment.parse('staging'), Environment.staging);
      expect(Environment.parse('stage'), Environment.staging);
      expect(Environment.parse('dev'), Environment.dev);
    });

    test('falls back to dev for an unknown value', () {
      expect(Environment.parse('nonsense'), Environment.dev);
    });
  });
}
