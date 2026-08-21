/// The app-wide error taxonomy every API response is mapped into before any
/// repository or UI code sees it (blueprint §16). No screen should ever
/// pattern-match on a raw HTTP status code itself.
///
/// One subtype per taxonomy entry, matching the verified backend contract
/// documented in docs/api-contracts/*.md - nothing here is invented.
sealed class ApiFailure {
  const ApiFailure();
}

/// HTTP 401: the bearer token is missing/invalid/expired/revoked, or the
/// session/role/client-type check behind it failed. By the time this
/// reaches UI code, a silent refresh attempt has already been tried and
/// failed (see AuthInterceptor) - this is always the final, unrecoverable
/// outcome.
final class SessionExpired extends ApiFailure {
  const SessionExpired();
}

/// HTTP 404: the resource doesn't exist, or exists but belongs to another
/// customer - the backend deliberately never distinguishes the two
/// (ownership-safe not-found, verified throughout docs/api-contracts/*.md).
final class NotFound extends ApiFailure {
  const NotFound();
}

/// HTTP 409: a business-lifecycle conflict (wrong status for the requested
/// transition, an in-progress payment already exists, archived-property
/// immutability, etc). [message] is the backend's own customer-safe text.
final class Conflict extends ApiFailure {
  const Conflict(this.message);

  final String message;
}

/// HTTP 422: either Laravel FormRequest field validation (`errors` present)
/// or a business-rule rejection (`message` only, no `errors`). [fieldErrors]
/// is null when the response carried no `errors` object.
final class ValidationOrBusinessRejection extends ApiFailure {
  const ValidationOrBusinessRejection(this.message, this.fieldErrors);

  final String message;
  final Map<String, List<String>>? fieldErrors;
}

/// HTTP 429: one of the backend's documented rate-limit buckets (identity or
/// IP) was exceeded.
final class RateLimited extends ApiFailure {
  const RateLimited();
}

/// HTTP 500/503, or any response shape this app doesn't recognize. Never
/// exposes raw exception text to the UI.
final class ServerError extends ApiFailure {
  const ServerError();
}

/// No response was ever received because connectivity itself failed (DNS,
/// TLS, connection refused/reset) - distinct from a slow-but-reachable
/// server (see [TimeoutFailure]).
final class NetworkUnavailable extends ApiFailure {
  const NetworkUnavailable();
}

/// The request was sent but no response arrived within the configured
/// connect/send/receive timeout.
final class TimeoutFailure extends ApiFailure {
  const TimeoutFailure();
}

/// The exception type [ApiClient] actually throws, carrying a typed
/// [ApiFailure] rather than a raw Dio/HTTP exception past the network layer.
class ApiException implements Exception {
  const ApiException(this.failure);

  final ApiFailure failure;

  @override
  String toString() => 'ApiException(${failure.runtimeType})';
}
