import '../../../core/api/api_client.dart';
import 'customer_profile.dart';

class ProfileRepository {
  ProfileRepository(this._client);

  final ApiClient _client;
  CustomerProfile? _cache;

  CustomerProfile? get cached => _cache;

  Future<CustomerProfile> get({bool force = false}) async {
    if (!force && _cache != null) return _cache!;
    final data = await _client.get('/profile', auth: true);
    if (data == null) {
      throw StateError('Profile response was empty.');
    }
    return _cache = CustomerProfile.fromJson(data);
  }

  Future<CustomerProfile> update({
    String? fullName,
    String? email,
    List<int>? serviceInterests,
  }) async {
    final body = <String, dynamic>{};
    if (fullName != null) body['full_name'] = fullName;
    if (email != null) body['email'] = email;
    if (serviceInterests != null) body['service_interests'] = serviceInterests;
    final data = await _client.patch('/profile', body: body, auth: true);
    if (data == null) {
      throw StateError('Profile update response was empty.');
    }
    return _cache = CustomerProfile.fromJson(data);
  }

  void apply(CustomerProfile profile) => _cache = profile;

  void clear() => _cache = null;
}
