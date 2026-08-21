import '../core/config/app_config.dart';

/// Resolves and validates the app's [AppConfig] once, before [runApp] is
/// ever called. A misconfigured production build (see [AppConfigError])
/// must fail loudly here, at process start - never silently degrade or
/// boot into an app pointed at the wrong backend.
AppConfig bootstrap() {
  return AppConfig.fromEnvironment();
}
