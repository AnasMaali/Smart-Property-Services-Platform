import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app_config.dart';

/// The single [AppConfig] instance for the app's lifetime. Overridden in
/// tests/widget tests via `ProviderScope(overrides: [...])` when a
/// non-default environment needs to be exercised - never mutated in place.
final appConfigProvider = Provider<AppConfig>((ref) {
  return AppConfig.fromEnvironment();
});
