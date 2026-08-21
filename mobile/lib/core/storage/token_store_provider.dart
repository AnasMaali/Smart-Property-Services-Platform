import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'secure_token_store.dart';
import 'token_store.dart';

final tokenStoreProvider = Provider<TokenStore>((ref) {
  return SecureTokenStore();
});
