import 'package:flutter_riverpod/flutter_riverpod.dart';

/// The app's global authentication state (blueprint §4/§12).
///
/// - [unknown]: startup has not yet determined whether a usable session
///   exists (splash).
/// - [authenticated]: a valid (or silently-refreshable) session exists.
/// - [unauthenticated]: no session exists, or the last refresh attempt
///   irrecoverably failed.
///
/// Deliberately lives in `core/network`, not `features/auth`: [AuthInterceptor]
/// needs to report "the session just died" without importing anything from
/// `features/`, and `core` must never depend on `features/` (that would be
/// the exact circular dependency this split avoids - `features/auth` is free
/// to build a richer notifier around this same provider once it exists).
enum SessionStatus { unknown, authenticated, unauthenticated }

class SessionStatusNotifier extends Notifier<SessionStatus> {
  @override
  SessionStatus build() => SessionStatus.unknown;

  void update(SessionStatus status) => state = status;
}

final sessionStatusProvider =
    NotifierProvider<SessionStatusNotifier, SessionStatus>(
      SessionStatusNotifier.new,
    );
