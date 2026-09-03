import '../../../core/api/api_client.dart';
import '../../services/data/service_detail.dart';
import 'catalog_service.dart';
import 'service_category.dart';

class ServiceCatalogRepository {
  ServiceCatalogRepository(this._client);

  final ApiClient _client;

  Future<List<ServiceCategory>> listCategories() async {
    final data = await _client.get('/service-categories');
    final raw = data?['service_categories'] as List<dynamic>? ?? const [];
    return raw
        .whereType<Map<String, dynamic>>()
        .map(ServiceCategory.fromJson)
        .toList();
  }

  Future<CatalogSearchResult> search({
    String query = '',
    int? categoryId,
    String? capability,
  }) async {
    final data = await _client.get(
      '/services',
      query: {
        if (query.trim().isNotEmpty) 'q': query.trim(),
        if (categoryId != null) 'category': '$categoryId',
        if (capability != null && capability.trim().isNotEmpty)
          'capability': capability.trim(),
      },
    );
    return CatalogSearchResult.fromJson(data);
  }

  Future<ServiceDetail> getDetails(String slug) async {
    final data = await _client.get('/services/${Uri.encodeComponent(slug)}');
    return ServiceDetail.fromJson(data ?? const {});
  }

  Future<DetailPricing> previewPricing({
    required String slug,
    required List<Map<String, dynamic>> options,
  }) async {
    final data = await _client.post(
      '/services/${Uri.encodeComponent(slug)}/pricing-preview',
      body: {'options': options},
    );
    return DetailPricing.fromJson(
      data?['pricing_preview'] as Map<String, dynamic>?,
    );
  }
}
