import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/app_config_provider.dart';
import '../storage/token_store_provider.dart';
import 'api_client.dart';
import 'session_status.dart';

/// The composition root wiring [AppConfig], [TokenStore], and
/// [sessionStatusProvider] together into one [ApiClient] instance
/// (blueprint §12) - the only place `core/network` reads a provider that
/// isn't its own, and the only place [SessionStatus] is written to from the
/// network layer.
final apiClientProvider = Provider<ApiClient>((ref) {
  final config = ref.watch(appConfigProvider);
  final tokenStore = ref.watch(tokenStoreProvider);

  return ApiClient(
    baseUrl: config.apiBaseUrl,
    tokenStore: tokenStore,
    onSessionExpired: () {
      ref
          .read(sessionStatusProvider.notifier)
          .update(SessionStatus.unauthenticated);
    },
  );
});
