import '../../../core/api/api_client.dart';
import 'reference_data.dart';

class ReferenceDataRepository {
  ReferenceDataRepository(this._client);

  final ApiClient? _client;
  RegistrationReferenceData? _cache;

  Future<RegistrationReferenceData> load({bool force = false}) async {
    if (!force && _cache != null) return _cache!;
    final client = _client;
    if (client == null) {
      throw StateError('Reference data client is not configured.');
    }
    final data = await client.get('/reference-data/registration');
    if (data == null) {
      throw StateError('Reference data response was empty.');
    }
    return _cache = RegistrationReferenceData.fromJson(data);
  }
}
