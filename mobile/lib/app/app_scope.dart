import 'package:flutter/material.dart';

import '../core/api/api_client.dart';
import '../core/api/api_config.dart';
import '../core/session/session_store.dart';
import '../features/auth/data/auth_repository.dart';
import '../features/auth/data/reference_data_repository.dart';
import '../features/bookings/data/booking_repository.dart';
import '../features/cart/data/cart_repository.dart';
import '../features/contracts/data/contract_repository.dart';
import '../features/checkout/data/checkout_repository.dart';
import '../features/home/data/service_catalog_repository.dart';
import '../features/payment/data/payment_repository.dart';
import '../features/profile/data/profile_repository.dart';
import '../features/properties/data/property_repository.dart';
import '../features/ratings/data/rating_repository.dart';
import '../features/support/data/support_repository.dart';
import 'shell_controller.dart';

class AppDependencies {
  const AppDependencies({
    required this.auth,
    required this.referenceData,
    required this.catalog,
    required this.profile,
    required this.cart,
    required this.bookings,
    required this.contracts,
    required this.checkout,
    required this.payment,
    required this.properties,
    required this.support,
    required this.ratings,
    required this.shell,
  });

  final AuthRepository auth;
  final ReferenceDataRepository referenceData;
  final ServiceCatalogRepository catalog;
  final ProfileRepository profile;
  final CartRepository cart;
  final BookingRepository bookings;
  final ContractRepository contracts;
  final CheckoutRepository checkout;
  final PaymentRepository payment;
  final PropertyRepository properties;
  final SupportRepository support;
  final RatingRepository ratings;
  final ShellController shell;

  static Future<AppDependencies> create({
    SessionStore? store,
    ApiClient? client,
    String? baseUrl,
  }) async {
    final sessionStore = store ?? SecureSessionStore();
    await sessionStore.load();
    final apiClient =
        client ??
        ApiClient(
          baseUrl: baseUrl ?? ApiConfig.baseUrl,
          sessionStore: sessionStore,
        );
    final auth = AuthRepository(client: apiClient, store: sessionStore);
    return AppDependencies(
      auth: auth,
      referenceData: ReferenceDataRepository(apiClient),
      catalog: ServiceCatalogRepository(apiClient),
      profile: ProfileRepository(apiClient),
      cart: CartRepository(apiClient),
      bookings: BookingRepository(apiClient),
      contracts: ContractRepository(apiClient),
      checkout: CheckoutRepository(apiClient),
      payment: PaymentRepository(apiClient),
      properties: PropertyRepository(apiClient),
      support: SupportRepository(apiClient),
      ratings: RatingRepository(apiClient),
      shell: ShellController(),
    );
  }
}

class AppScope extends InheritedWidget {
  const AppScope({super.key, required this.dependencies, required super.child});

  final AppDependencies dependencies;

  AuthRepository get auth => dependencies.auth;

  ReferenceDataRepository get referenceData => dependencies.referenceData;

  ServiceCatalogRepository get catalog => dependencies.catalog;

  ProfileRepository get profile => dependencies.profile;

  CartRepository get cart => dependencies.cart;

  BookingRepository get bookings => dependencies.bookings;

  ContractRepository get contracts => dependencies.contracts;

  CheckoutRepository get checkout => dependencies.checkout;

  PaymentRepository get payment => dependencies.payment;

  PropertyRepository get properties => dependencies.properties;

  SupportRepository get support => dependencies.support;

  RatingRepository get ratings => dependencies.ratings;

  ShellController get shell => dependencies.shell;

  static AppScope of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AppScope>();
    assert(scope != null, 'AppScope is missing.');
    return scope!;
  }

  @override
  bool updateShouldNotify(AppScope oldWidget) {
    return dependencies != oldWidget.dependencies;
  }
}
