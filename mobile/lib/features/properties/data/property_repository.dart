import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import 'property_models.dart';

class PropertyRepository {
  PropertyRepository(this._client);

  final ApiClient _client;

  Future<List<SavedProperty>> list() async {
    final data = await _client.get('/properties', auth: true);
    final rows = data?['properties'] as List<dynamic>? ?? const [];
    return rows
        .whereType<Map<String, dynamic>>()
        .map(SavedProperty.fromJson)
        .toList();
  }

  Future<SavedProperty> get(String uuid) async {
    final data = await _client.get(
      '/properties/${Uri.encodeComponent(uuid)}',
      auth: true,
    );
    final raw = data?['property'];
    if (raw is! Map<String, dynamic>) {
      throw const ApiException(message: 'Property not found.', statusCode: 404);
    }
    return SavedProperty.fromJson(raw);
  }

  Future<SavedProperty> create(PropertyWrite input) async {
    final data = await _client.post(
      '/properties',
      auth: true,
      body: input.toCreateJson(),
    );
    final raw = data?['property'];
    if (raw is! Map<String, dynamic>) {
      throw const ApiException(message: 'Property was not created.');
    }
    return SavedProperty.fromJson(raw);
  }

  Future<SavedProperty> update(String uuid, PropertyWrite input) async {
    final data = await _client.patch(
      '/properties/${Uri.encodeComponent(uuid)}',
      auth: true,
      body: input.toPatchJson(),
    );
    final raw = data?['property'];
    if (raw is! Map<String, dynamic>) {
      throw const ApiException(message: 'Property was not updated.');
    }
    return SavedProperty.fromJson(raw);
  }

  Future<void> remove(String uuid) async {
    await _client.delete(
      '/properties/${Uri.encodeComponent(uuid)}',
      auth: true,
    );
  }
}
