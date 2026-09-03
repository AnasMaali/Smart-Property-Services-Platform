import '../../../core/api/api_client.dart';
import 'cart_models.dart';

class CartRepository {
  CartRepository(this._client);

  final ApiClient _client;

  Future<CartSnapshot> get() async {
    final data = await _client.get('/cart', auth: true);
    return CartSnapshot.fromJson(data);
  }

  Future<int> itemCount() async {
    final cart = await get();
    return cart.serviceCount;
  }

  Future<int> addItem({
    required String serviceUuid,
    List<Map<String, dynamic>> options = const [],
  }) async {
    final data = await _client.post(
      '/cart/items',
      auth: true,
      body: {'service_uuid': serviceUuid, 'quantity': 1, 'options': options},
    );
    return CartSnapshot.fromJson(data).serviceCount;
  }

  Future<CartSnapshot> updateItem({
    required String itemUuid,
    int? quantity,
    List<Map<String, dynamic>>? options,
  }) async {
    final body = <String, dynamic>{'quantity': ?quantity, 'options': ?options};
    final data = await _client.patch(
      '/cart/items/${Uri.encodeComponent(itemUuid)}',
      auth: true,
      body: body,
    );
    return CartSnapshot.fromJson(data);
  }

  Future<CartSnapshot> removeItem(String itemUuid) async {
    final data = await _client.delete(
      '/cart/items/${Uri.encodeComponent(itemUuid)}',
      auth: true,
    );
    return CartSnapshot.fromJson(data);
  }

  Future<CartSnapshot> clear() async {
    final data = await _client.delete('/cart', auth: true);
    return CartSnapshot.fromJson(data);
  }
}
