import 'package:flutter/foundation.dart';

abstract final class ApiConfig {
  /// Override at run time with
  /// `--dart-define=API_BASE_URL=http://192.168.x.x:8000/api/v1`.
  static const override = String.fromEnvironment('API_BASE_URL');

  static String get baseUrl {
    if (override.isNotEmpty) return _trimSlash(override);
    if (kIsWeb) return 'http://127.0.0.1:8000/api/v1';
    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
        return 'http://10.0.2.2:8000/api/v1';
      default:
        return 'http://127.0.0.1:8000/api/v1';
    }
  }

  static String get clientType {
    switch (defaultTargetPlatform) {
      case TargetPlatform.iOS:
        return 'MOBILE_IOS';
      default:
        return 'MOBILE_ANDROID';
    }
  }

  static const appVersion = '1.0.0';

  static String _trimSlash(String value) {
    return value.endsWith('/') ? value.substring(0, value.length - 1) : value;
  }
}
