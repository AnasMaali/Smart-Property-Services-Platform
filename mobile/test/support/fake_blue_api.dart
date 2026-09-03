import 'dart:async';
import 'dart:convert';

import 'package:blue_customer/app/app_scope.dart';
import 'package:blue_customer/app/shell_controller.dart';
import 'package:blue_customer/core/api/api_client.dart';
import 'package:blue_customer/core/session/session_store.dart';
import 'package:blue_customer/features/auth/data/auth_repository.dart';
import 'package:blue_customer/features/auth/data/reference_data_repository.dart';
import 'package:blue_customer/features/bookings/data/booking_repository.dart';
import 'package:blue_customer/features/cart/data/cart_repository.dart';
import 'package:blue_customer/features/checkout/data/checkout_repository.dart';
import 'package:blue_customer/features/contracts/data/contract_repository.dart';
import 'package:blue_customer/features/home/data/service_catalog_repository.dart';
import 'package:blue_customer/features/payment/data/payment_repository.dart';
import 'package:blue_customer/features/profile/data/profile_repository.dart';
import 'package:blue_customer/features/properties/data/property_repository.dart';
import 'package:blue_customer/features/ratings/data/rating_repository.dart';
import 'package:blue_customer/features/support/data/support_repository.dart';
import 'package:http/http.dart' as http;

AppDependencies buildTestDependencies({http.Client? httpClient}) {
  final store = MemorySessionStore();
  final client = ApiClient(
    baseUrl: 'http://test.local/api/v1',
    sessionStore: store,
    httpClient: httpClient ?? FakeBlueApi(),
    timeout: const Duration(milliseconds: 100),
  );
  return AppDependencies(
    auth: AuthRepository(client: client, store: store),
    referenceData: ReferenceDataRepository(client),
    catalog: ServiceCatalogRepository(client),
    profile: ProfileRepository(client),
    cart: CartRepository(client),
    bookings: BookingRepository(client),
    contracts: ContractRepository(client),
    checkout: CheckoutRepository(client),
    payment: PaymentRepository(client),
    properties: PropertyRepository(client),
    support: SupportRepository(client),
    ratings: RatingRepository(client),
    shell: ShellController(),
  );
}

class FakeBlueApi extends http.BaseClient {
  FakeBlueApi({
    this.delay = const Duration(milliseconds: 80),
    this.readyCheckout = false,
    this.failBookings = false,
    this.failContracts = false,
    this.failContractDetail = false,
    this.failProperties = false,
    this.failProfile = false,
    this.failProfileSave = false,
    List<Map<String, dynamic>>? bookings,
    List<Map<String, dynamic>>? contracts,
    List<Map<String, dynamic>>? properties,
  }) : _bookings = bookings ?? const [],
       _contracts = List<Map<String, dynamic>>.from(contracts ?? const []),
       _properties = List<Map<String, dynamic>>.from(
         properties ?? _defaultProperties(),
       ),
       _supportRequests = List<Map<String, dynamic>>.from(
         _defaultSupportRequests(),
       );

  final Duration delay;
  final bool readyCheckout;
  final bool failBookings;
  final bool failContracts;
  final bool failContractDetail;
  final bool failProperties;
  final bool failProfile;
  final bool failProfileSave;
  final List<Map<String, dynamic>> _bookings;
  final List<Map<String, dynamic>> _contracts;
  final List<Map<String, dynamic>> _properties;
  final List<Map<String, dynamic>> _supportRequests;
  int _supportSeq = 2482;
  String _otpUuid = '5b0e7a11-1111-1111-1111-111111111111';
  String _phoneChangeUuid = 'phone-change-1';
  int _phoneChangeSeq = 1;
  String? _pendingNewPhone;
  final List<Map<String, dynamic>> _cartItems = [];
  Map<String, dynamic>? _checkoutLocation;
  Map<String, dynamic>? _checkoutHold;
  Map<String, dynamic>? _payment;
  final Map<String, dynamic> _profile = _defaultProfile();

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    if (delay > Duration.zero) {
      await Future<void>.delayed(delay);
    }
    final path = request.url.path;
    final body = request is http.Request && request.body.isNotEmpty
        ? jsonDecode(request.body) as Map<String, dynamic>
        : const <String, dynamic>{};

    if (path.endsWith('/reference-data/registration')) {
      return _json(200, {
        'success': true,
        'message': 'Reference data retrieved successfully.',
        'data': {
          'cities': [
            {
              'id': 2,
              'code': 'DUBAI',
              'name': 'Dubai',
              'areas': [
                {'id': 8, 'code': 'DUBAI_MARINA', 'name': 'Dubai Marina'},
                {'id': 9, 'code': 'DOWNTOWN_DUBAI', 'name': 'Downtown Dubai'},
                {'id': 10, 'code': 'BUSINESS_BAY', 'name': 'Business Bay'},
                {'id': 11, 'code': 'PALM_JUMEIRAH', 'name': 'Palm Jumeirah'},
              ],
            },
            {
              'id': 3,
              'code': 'ABU_DHABI',
              'name': 'Abu Dhabi',
              'areas': [
                {'id': 20, 'code': 'AL_REEM', 'name': 'Al Reem Island'},
              ],
            },
          ],
          'property_relationship_types': [
            {'id': 1, 'code': 'OWNER', 'name': 'Owner'},
            {'id': 2, 'code': 'TENANT', 'name': 'Tenant'},
            {'id': 4, 'code': 'FAMILY_MEMBER', 'name': 'Family member'},
            {'id': 3, 'code': 'MANAGER', 'name': 'Property manager'},
          ],
          'property_types': [
            {'id': 1, 'code': 'APARTMENT', 'name': 'Apartment'},
            {'id': 2, 'code': 'VILLA', 'name': 'Villa'},
            {'id': 3, 'code': 'OFFICE', 'name': 'Office'},
            {'id': 4, 'code': 'OTHER', 'name': 'Other'},
          ],
          'service_categories': [
            {'id': 1, 'code': 'CLEANING', 'name': 'Cleaning'},
            {'id': 2, 'code': 'AC', 'name': 'AC services'},
            {'id': 3, 'code': 'HANDYMAN', 'name': 'Maintenance'},
            {'id': 4, 'code': 'PAINTING', 'name': 'Renovation'},
            {'id': 5, 'code': 'PLUMBING', 'name': 'Plumbing'},
            {'id': 6, 'code': 'ELECTRICAL', 'name': 'Electrical'},
            {'id': 7, 'code': 'PEST_CONTROL', 'name': 'Pest control'},
            {'id': 8, 'code': 'MASONRY', 'name': 'Masonry'},
          ],
        },
      });
    }

    if (path.endsWith('/auth/login/request-otp') ||
        path.endsWith('/auth/login/resend-otp')) {
      return _json(200, {
        'success': true,
        'message':
            'If an eligible account exists for this phone number, a login code has been sent.',
        'data': null,
      });
    }

    if (path.endsWith('/auth/login/verify-otp')) {
      final code = '${body['otp_code']}';
      if (code == '123456') {
        return _json(200, {
          'success': true,
          'message': 'Login successful.',
          'data': {
            'user_uuid': '3f2a1c9e-1111-1111-1111-111111111111',
            'full_name': 'Layla Hassan',
            'phone_number': body['phone_number'] ?? '+971501234567',
            'email': 'layla@example.com',
            'role': 'CUSTOMER',
            'session_uuid': '7e1f4b02-1111-1111-1111-111111111111',
            'access_token': 'test-access-token',
            'access_token_expires_at': DateTime.now()
                .toUtc()
                .add(const Duration(minutes: 15))
                .toIso8601String(),
            'refresh_token': 'a' * 64,
            'session_expires_at': DateTime.now()
                .toUtc()
                .add(const Duration(days: 30))
                .toIso8601String(),
          },
        });
      }
      return _json(422, {
        'success': false,
        'message': 'Invalid or expired verification code.',
        'data': null,
      });
    }

    if (path.endsWith('/auth/register')) {
      return _json(201, {
        'success': true,
        'message':
            'Registration successful. Please verify your phone number using the OTP sent to it.',
        'data': {
          'user_uuid': '3f2a1c9e-1111-1111-1111-111111111111',
          'full_name': body['full_name'],
          'phone_number': body['phone_number'],
          'email': body['email'],
          'account_status': 'PENDING_VERIFICATION',
          'phone_verified': false,
          'otp_verification_uuid': _otpUuid,
          'otp_expires_at': DateTime.now()
              .toUtc()
              .add(const Duration(minutes: 5))
              .toIso8601String(),
        },
      });
    }

    if (path.endsWith('/auth/verify-phone')) {
      final code = '${body['otp_code']}';
      if (code == '123456') {
        return _json(200, {
          'success': true,
          'message': 'Phone number verified successfully.',
          'data': {
            'user_uuid': '3f2a1c9e-1111-1111-1111-111111111111',
            'phone_number': '+971501234567',
            'account_status': 'ACTIVE',
            'phone_verified': true,
            'phone_verified_at': DateTime.now().toUtc().toIso8601String(),
          },
        });
      }
      return _json(422, {
        'success': false,
        'message': 'The verification code you entered is incorrect.',
        'data': null,
      });
    }

    if (path.endsWith('/auth/resend-otp')) {
      _otpUuid = '9c3d2e88-2222-2222-2222-222222222222';
      return _json(200, {
        'success': true,
        'message': 'A new verification code has been sent.',
        'data': {
          'otp_verification_uuid': _otpUuid,
          'otp_expires_at': DateTime.now()
              .toUtc()
              .add(const Duration(minutes: 5))
              .toIso8601String(),
          'resend_available_at': DateTime.now()
              .toUtc()
              .add(const Duration(seconds: 60))
              .toIso8601String(),
          'phone_number': '+971501234567',
        },
      });
    }

    if (path.endsWith('/service-categories')) {
      return _json(200, {
        'success': true,
        'message': 'Service categories retrieved successfully.',
        'data': {
          'service_categories': [
            {'id': 1, 'code': 'AC', 'name': 'AC cleaning', 'description': ''},
            {
              'id': 2,
              'code': 'CLEANING',
              'name': 'Deep cleaning',
              'description': '',
            },
            {
              'id': 3,
              'code': 'PLUMBING',
              'name': 'Plumbing',
              'description': '',
            },
            {
              'id': 4,
              'code': 'ELECTRICAL',
              'name': 'Electrical',
              'description': '',
            },
            {
              'id': 5,
              'code': 'HANDYMAN',
              'name': 'General maintenance',
              'description': '',
            },
            {
              'id': 6,
              'code': 'PAINTING',
              'name': 'Painting',
              'description': '',
            },
          ],
        },
      });
    }

    if (path.endsWith('/profile')) {
      if (request.method == 'PATCH') {
        if (failProfileSave) {
          return _json(500, {
            'success': false,
            'message': 'Unable to save profile.',
            'data': null,
          });
        }
        if (body['full_name'] is String) {
          _profile['full_name'] = body['full_name'];
        }
        if (body['email'] is String) {
          _profile['email'] = body['email'];
        }
        if (body.containsKey('service_interests')) {
          final ids = (body['service_interests'] as List<dynamic>? ?? const [])
              .map((id) => (id as num).toInt())
              .toList();
          final catalogue = <int, Map<String, dynamic>>{
            1: {'id': 1, 'code': 'CLEANING', 'name': 'Cleaning'},
            2: {'id': 2, 'code': 'AC', 'name': 'AC services'},
            3: {'id': 3, 'code': 'HANDYMAN', 'name': 'Maintenance'},
            4: {'id': 4, 'code': 'PAINTING', 'name': 'Renovation'},
            5: {'id': 5, 'code': 'PLUMBING', 'name': 'Plumbing'},
            6: {'id': 6, 'code': 'ELECTRICAL', 'name': 'Electrical'},
            7: {'id': 7, 'code': 'PEST_CONTROL', 'name': 'Pest control'},
            8: {'id': 8, 'code': 'MASONRY', 'name': 'Masonry'},
          };
          _profile['service_interests'] = [
            for (final id in ids)
              if (catalogue[id] != null) catalogue[id],
          ];
        }
        return _json(200, {
          'success': true,
          'message': 'Profile updated successfully.',
          'data': _profile,
        });
      }
      if (failProfile) {
        return _json(500, {
          'success': false,
          'message': 'Unable to load profile.',
          'data': null,
        });
      }
      return _json(200, {
        'success': true,
        'message': 'Profile retrieved successfully.',
        'data': _profile,
      });
    }

    if (path.endsWith('/auth/logout')) {
      return _json(200, {
        'success': true,
        'message': 'Logged out successfully.',
      });
    }

    if (path.endsWith('/auth/account/request-otp') ||
        path.endsWith('/auth/account/resend-otp')) {
      return _json(200, {
        'success': true,
        'message': 'A verification code has been sent to your phone number.',
        'data': null,
      });
    }

    if (path.endsWith('/auth/change-phone-number')) {
      final next = '${body['new_phone_number'] ?? ''}';
      final current = '${_profile['phone_number']}';
      if (!RegExp(r'^\+?[0-9]{8,20}$').hasMatch(next)) {
        return _json(422, {
          'success': false,
          'message': 'The new phone number format is invalid.',
          'data': null,
        });
      }
      if (next == current) {
        return _json(422, {
          'success': false,
          'message':
              'The new phone number must be different from your current phone number.',
          'data': null,
        });
      }
      _phoneChangeUuid = 'phone-change-${_phoneChangeSeq++}';
      _pendingNewPhone = next;
      return _json(200, {
        'success': true,
        'message': 'A verification code has been sent to the new phone number.',
        'data': {
          'otp_verification_uuid': _phoneChangeUuid,
          'new_phone_number': next,
          'otp_expires_at': DateTime.now()
              .toUtc()
              .add(const Duration(minutes: 5))
              .toIso8601String(),
          'resend_available_at': DateTime.now()
              .toUtc()
              .add(const Duration(seconds: 60))
              .toIso8601String(),
        },
      });
    }

    if (path.endsWith('/auth/resend-phone-number-change-otp')) {
      if (_pendingNewPhone == null) {
        return _json(422, {
          'success': false,
          'message':
              'This verification request is invalid or no longer active.',
          'data': null,
        });
      }
      _phoneChangeUuid = 'phone-change-${_phoneChangeSeq++}';
      return _json(200, {
        'success': true,
        'message': 'A new verification code has been sent.',
        'data': {
          'otp_verification_uuid': _phoneChangeUuid,
          'new_phone_number': _pendingNewPhone,
          'otp_expires_at': DateTime.now()
              .toUtc()
              .add(const Duration(minutes: 5))
              .toIso8601String(),
          'resend_available_at': DateTime.now()
              .toUtc()
              .add(const Duration(seconds: 60))
              .toIso8601String(),
        },
      });
    }

    if (path.endsWith('/auth/verify-phone-number-change-otp')) {
      final code = '${body['otp_code'] ?? ''}';
      if (code != '123456' || _pendingNewPhone == null) {
        return _json(422, {
          'success': false,
          'message': 'The verification code you entered is incorrect.',
          'data': null,
        });
      }
      _profile['phone_number'] = _pendingNewPhone;
      _profile['phone_verified'] = true;
      final now = DateTime.now().toUtc().toIso8601String();
      _pendingNewPhone = null;
      return _json(200, {
        'success': true,
        'message': 'Phone number changed successfully.',
        'data': {
          'phone_number': _profile['phone_number'],
          'phone_verified': true,
          'phone_verified_at': now,
        },
      });
    }

    if (path.endsWith('/auth/account') && request.method == 'DELETE') {
      final code = '${body['otp_code'] ?? ''}';
      if (code != '123456') {
        return _json(422, {
          'success': false,
          'message': 'Invalid or expired verification code.',
          'data': null,
        });
      }
      return _json(200, {
        'success': true,
        'message': 'Account deleted successfully.',
        'data': null,
      });
    }

    if (path.endsWith('/cart/items') && request.method == 'POST') {
      final serviceUuid = body['service_uuid'] as String? ?? '';
      final quantity = (body['quantity'] as num?)?.toInt() ?? 1;
      final options = (body['options'] as List<dynamic>? ?? const [])
          .whereType<Map<String, dynamic>>()
          .toList();
      _cartItems.add(
        _buildCartItem(
          uuid: 'cart-item-${_cartItems.length + 1}',
          serviceUuid: serviceUuid,
          quantity: quantity,
          options: options,
        ),
      );
      if (readyCheckout) _seedReadyCheckout();
      return _json(201, {
        'success': true,
        'message': 'Item added to cart.',
        'data': _cartPayload(),
      });
    }

    if (path.contains('/cart/items/')) {
      final uuid = path.split('/').last;
      final index = _cartItems.indexWhere((item) => item['uuid'] == uuid);
      if (index < 0) {
        return _json(404, {
          'success': false,
          'message': 'Not found.',
          'data': null,
        });
      }
      if (request.method == 'DELETE') {
        _cartItems.removeAt(index);
        return _json(200, {
          'success': true,
          'message': 'Item removed from cart.',
          'data': _cartPayload(),
        });
      }
      if (request.method == 'PATCH') {
        final current = Map<String, dynamic>.from(_cartItems[index]);
        final quantity =
            (body['quantity'] as num?)?.toInt() ??
            (current['quantity'] as num?)?.toInt() ??
            1;
        final options = body['options'] is List
            ? (body['options'] as List)
                  .whereType<Map<String, dynamic>>()
                  .toList()
            : (current['options'] as List<dynamic>? ?? const [])
                  .whereType<Map<String, dynamic>>()
                  .toList();
        final service = current['service'] as Map<String, dynamic>? ?? const {};
        _cartItems[index] = _buildCartItem(
          uuid: uuid,
          serviceUuid: service['uuid'] as String? ?? '',
          quantity: quantity,
          options: options,
        );
        return _json(200, {
          'success': true,
          'message': 'Cart item updated successfully.',
          'data': _cartPayload(),
        });
      }
    }

    if (path.endsWith('/checkout/location') && request.method == 'PUT') {
      final areaId = (body['area_id'] as num?)?.toInt() ?? 8;
      final typeId = (body['property_type_id'] as num?)?.toInt() ?? 1;
      const types = {
        1: {'id': 1, 'code': 'APARTMENT', 'name': 'Apartment'},
        2: {'id': 2, 'code': 'VILLA', 'name': 'Villa'},
        3: {'id': 3, 'code': 'OFFICE', 'name': 'Office'},
        4: {'id': 4, 'code': 'OTHER', 'name': 'Other'},
      };
      _checkoutLocation = {
        'property_type': types[typeId] ?? types[1],
        'other_property_type_name': body['other_property_type_name'],
        'area': {'id': areaId, 'code': 'DUBAI_MARINA', 'name': 'Dubai Marina'},
        'city': {'id': 2, 'code': 'DUBAI', 'name': 'Dubai'},
        'street_name': body['street_name'] ?? '',
        'address_line': body['address_line'] ?? '',
        'building_name_or_number': body['building_name_or_number'] ?? '',
        'floor_number': body['floor_number'],
        'unit_number': body['unit_number'],
        'nearby_landmark': body['nearby_landmark'],
        'additional_location_notes': body['additional_location_notes'],
        'visit_contact_phone': body['visit_contact_phone'] ?? '',
      };
      return _json(200, {
        'success': true,
        'message': 'Checkout location saved successfully.',
        'data': _checkoutPayload(),
      });
    }

    if (path.endsWith('/appointment-slots') ||
        path.endsWith('/checkout/appointment-slots')) {
      return _json(200, {
        'success': true,
        'message': 'Appointment slots retrieved successfully.',
        'data': {'appointment_slots': _fakeAppointmentSlots()},
      });
    }

    if (path.endsWith('/checkout/appointment-hold') &&
        request.method == 'POST') {
      final start = DateTime.now().toUtc().add(const Duration(days: 2));
      final slotStart = DateTime.utc(start.year, start.month, start.day, 9);
      final uuid = body['appointment_slot_uuid'] as String? ?? 'slot-9';
      final hour = int.tryParse(uuid.replaceAll('slot-', '')) ?? 9;
      _checkoutHold = {
        'hold_uuid': 'hold-1',
        'slot': {
          'uuid': uuid,
          'starts_at': DateTime.utc(
            slotStart.year,
            slotStart.month,
            slotStart.day,
            hour,
          ).toIso8601String(),
          'ends_at': DateTime.utc(
            slotStart.year,
            slotStart.month,
            slotStart.day,
            hour,
          ).add(const Duration(hours: 2)).toIso8601String(),
          'time_window': {'code': 'STANDARD', 'name': 'Standard Hours'},
        },
        'expires_at': DateTime.now()
            .toUtc()
            .add(const Duration(minutes: 10))
            .toIso8601String(),
      };
      return _json(201, {
        'success': true,
        'message': 'Appointment hold created successfully.',
        'data': _checkoutPayload(),
      });
    }

    if (path.endsWith('/checkout/appointment-hold') &&
        request.method == 'DELETE') {
      _checkoutHold = null;
      return _json(200, {
        'success': true,
        'message': 'Appointment hold released successfully.',
        'data': _checkoutPayload(),
      });
    }

    if (path.endsWith('/checkout')) {
      return _json(200, {
        'success': true,
        'message': 'Checkout retrieved successfully.',
        'data': _checkoutPayload(),
      });
    }

    if (path.endsWith('/payments') && request.method == 'POST') {
      final key =
          request.headers['Idempotency-Key'] ??
          request.headers['idempotency-key'];
      if (key == null || key.isEmpty) {
        return _json(422, {
          'success': false,
          'message': 'The Idempotency-Key header is required.',
          'data': null,
        });
      }
      _payment = {
        'uuid': 'pay-1',
        'checkout_reference': 'BLU-4827-QK',
        'status': 'PENDING',
        'requested_amount': '120.000000',
        'currency': {'code': 'AED', 'symbol': 'AED', 'decimal_places': 2},
        'expires_at': DateTime.now()
            .toUtc()
            .add(const Duration(minutes: 10))
            .toIso8601String(),
        'provider': 'FAKE',
      };
      return _json(201, {
        'success': true,
        'message': 'Payment attempt created.',
        'data': {'payment': _payment},
      });
    }

    if (path.contains('/payments/') && request.method == 'GET') {
      final payment = _payment;
      if (payment == null) {
        return _json(404, {
          'success': false,
          'message': 'Not found.',
          'data': null,
        });
      }
      return _json(200, {
        'success': true,
        'message': 'Payment retrieved successfully.',
        'data': {
          'payment': {...payment, 'status': 'SUCCESSFUL'},
        },
      });
    }

    if (path.endsWith('/properties') && request.method == 'GET') {
      if (failProperties) {
        return _json(500, {
          'success': false,
          'message': 'Unable to load properties.',
          'data': null,
        });
      }
      return _json(200, {
        'success': true,
        'message': 'Properties retrieved successfully.',
        'data': {
          'properties': _properties
              .where((row) => row['is_active'] != false)
              .toList(),
        },
      });
    }

    if (path.endsWith('/properties') && request.method == 'POST') {
      final created = _propertyFromBody(body, 'prop-${_properties.length + 1}');
      _properties.insert(0, created);
      return _json(201, {
        'success': true,
        'message': 'Property created successfully.',
        'data': {'property': created},
      });
    }

    final propertyMatch = RegExp(r'/properties/([^/]+)$').firstMatch(path);
    if (propertyMatch != null) {
      final uuid = propertyMatch.group(1)!;
      final index = _properties.indexWhere((row) => row['uuid'] == uuid);
      if (index < 0) {
        return _json(404, {
          'success': false,
          'message': 'Property not found.',
          'data': null,
        });
      }
      if (request.method == 'GET') {
        return _json(200, {
          'success': true,
          'message': 'Property retrieved successfully.',
          'data': {
            'property': _properties[index],
            'contracts': const <Map<String, dynamic>>[],
          },
        });
      }
      if (request.method == 'PATCH') {
        final current = Map<String, dynamic>.from(_properties[index]);
        if (current['is_active'] == false) {
          return _json(409, {
            'success': false,
            'message': 'An archived property cannot be edited.',
            'data': null,
          });
        }
        final next = _propertyFromBody(body, uuid, current: current);
        _properties[index] = next;
        return _json(200, {
          'success': true,
          'message': 'Property updated successfully.',
          'data': {'property': next},
        });
      }
      if (request.method == 'DELETE') {
        final current = Map<String, dynamic>.from(_properties[index]);
        current['is_active'] = false;
        _properties[index] = current;
        return _json(200, {
          'success': true,
          'message': 'Property archived successfully.',
          'data': {
            'property': {'uuid': uuid, 'is_active': false},
          },
        });
      }
    }

    if (path.endsWith('/bookings')) {
      if (failBookings) {
        return _json(500, {
          'success': false,
          'message': 'Unable to load bookings.',
          'data': null,
        });
      }
      return _json(200, {
        'success': true,
        'message': 'Bookings retrieved successfully.',
        'data': {'bookings': _bookings},
      });
    }

    final bookingCancelPreview = RegExp(
      r'/bookings/([^/]+)/cancellation-preview$',
    ).firstMatch(path);
    if (bookingCancelPreview != null) {
      return _json(200, {
        'success': true,
        'message': 'Cancellation preview retrieved successfully.',
        'data': {
          'preview': {
            'cancellable': true,
            'reason_code': 'APPOINTMENT_DAY_BEFORE_START',
            'paid_amount': '120.000000',
            'appointment': {
              'starts_at': DateTime.now()
                  .toUtc()
                  .add(const Duration(days: 2))
                  .toIso8601String(),
            },
            'refund': {
              'percentage': 100,
              'execution': 'AUTOMATIC',
              'method': 'ORIGINAL_PAYMENT_METHOD',
            },
          },
        },
      });
    }

    final bookingCancel = RegExp(r'/bookings/([^/]+)/cancel$').firstMatch(path);
    if (bookingCancel != null && request.method == 'POST') {
      final uuid = bookingCancel.group(1)!;
      for (var i = 0; i < _bookings.length; i++) {
        if (_bookings[i]['uuid'] == uuid) {
          _bookings[i] = {
            ...Map<String, dynamic>.from(_bookings[i]),
            'status': 'CANCELLED',
          };
          break;
        }
      }
      return _json(200, {
        'success': true,
        'message': 'Booking cancelled successfully.',
        'data': {'booking_uuid': uuid, 'status': 'CANCELLED'},
      });
    }

    final bookingRating = RegExp(r'/bookings/([^/]+)/rating$').firstMatch(path);
    if (bookingRating != null && request.method == 'POST') {
      final uuid = bookingRating.group(1)!;
      final ratingValue = (body['rating_value'] as num?)?.toInt() ?? 5;
      return _json(201, {
        'success': true,
        'message': 'Rating submitted successfully.',
        'data': {
          'rating': {
            'booking_uuid': uuid,
            'rating_value': ratingValue,
            'comment': body['comment'],
            'created_at': DateTime.now().toUtc().toIso8601String(),
          },
        },
      });
    }

    if (path.endsWith('/support-requests') && request.method == 'GET') {
      return _json(200, {
        'success': true,
        'message': 'Support requests retrieved successfully.',
        'data': {
          'support_requests': [
            for (final row in _supportRequests) _supportListItem(row),
          ],
        },
      });
    }

    if (path.endsWith('/support-requests') && request.method == 'POST') {
      final subject = '${body['subject'] ?? ''}'.trim();
      final message = '${body['message'] ?? ''}'.trim();
      if (subject.isEmpty || message.isEmpty) {
        return _json(422, {
          'success': false,
          'message': 'Validation failed.',
          'data': null,
        });
      }
      final now = DateTime.now().toUtc();
      final created = {
        'uuid': 'req-${_supportSeq++}',
        'request_number': 'REQ-${_supportSeq - 1}',
        'subject': subject,
        'status': 'OPEN',
        'created_at': now.toIso8601String(),
        'updated_at': now.toIso8601String(),
        'message_count': 1,
        'messages': [
          {
            'uuid': 'msg-${_supportSeq}-1',
            'from_support': false,
            'message_body': message,
            'created_at': now.toIso8601String(),
          },
        ],
      };
      _supportRequests.insert(0, created);
      return _json(201, {
        'success': true,
        'message': 'Support request created successfully.',
        'data': {'support_request': _supportDetail(created)},
      });
    }

    final supportDetailMatch = RegExp(r'/support-requests/([^/]+)$').firstMatch(
      path,
    );
    if (supportDetailMatch != null && request.method == 'GET') {
      final row = _findSupportRequest(supportDetailMatch.group(1)!);
      if (row == null) {
        return _json(404, {
          'success': false,
          'message': 'Support request not found.',
          'data': null,
        });
      }
      return _json(200, {
        'success': true,
        'message': 'Support request retrieved successfully.',
        'data': {'support_request': _supportDetail(row)},
      });
    }

    final supportMessageMatch = RegExp(
      r'/support-requests/([^/]+)/messages$',
    ).firstMatch(path);
    if (supportMessageMatch != null && request.method == 'POST') {
      final row = _findSupportRequest(supportMessageMatch.group(1)!);
      if (row == null) {
        return _json(404, {
          'success': false,
          'message': 'Support request not found.',
          'data': null,
        });
      }
      final message = '${body['message'] ?? ''}'.trim();
      if (message.isEmpty) {
        return _json(422, {
          'success': false,
          'message': 'Validation failed.',
          'data': null,
        });
      }
      final now = DateTime.now().toUtc();
      final messages = List<Map<String, dynamic>>.from(
        row['messages'] as List<dynamic>? ?? const [],
      );
      messages.add({
        'uuid': 'msg-${row['uuid']}-${messages.length + 1}',
        'from_support': false,
        'message_body': message,
        'created_at': now.toIso8601String(),
      });
      row['messages'] = messages;
      row['message_count'] = messages.length;
      row['updated_at'] = now.toIso8601String();
      if (row['status'] == 'OPEN') {
        row['status'] = 'IN_PROGRESS';
      }
      return _json(201, {
        'success': true,
        'message': 'Message sent successfully.',
        'data': {
          'message': {
            'uuid': messages.last['uuid'],
            'from_support': false,
            'message_body': message,
            'created_at': now.toIso8601String(),
          },
        },
      });
    }

    final bookingMatch = RegExp(r'/bookings/([^/]+)$').firstMatch(path);
    if (bookingMatch != null) {
      if (failBookings) {
        return _json(500, {
          'success': false,
          'message': 'Unable to load this booking.',
          'data': null,
        });
      }
      final uuid = bookingMatch.group(1)!;
      Map<String, dynamic>? found;
      for (final row in _bookings) {
        if (row['uuid'] == uuid) {
          found = row;
          break;
        }
      }
      if (found == null) {
        return _json(404, {
          'success': false,
          'message': 'Booking not found.',
          'data': null,
        });
      }
      return _json(200, {
        'success': true,
        'message': 'Booking retrieved successfully.',
        'data': {'booking': found},
      });
    }

    if (path.contains('/contracts/')) {
      if (path.endsWith('/contracts/requests') && request.method == 'POST') {
        final propertyUuid = body['property_uuid'] as String? ?? '';
        Map<String, dynamic>? property;
        for (final row in _properties) {
          if (row['uuid'] == propertyUuid) {
            property = row;
            break;
          }
        }
        if (property == null) {
          return _json(422, {
            'success': false,
            'message': 'The selected property is invalid.',
            'data': null,
          });
        }
        final allServices = body['all_services'] as bool? ?? false;
        final serviceUuids =
            (body['service_uuids'] as List<dynamic>? ?? const [])
                .whereType<String>()
                .toList();
        if (!allServices && serviceUuids.isEmpty) {
          return _json(422, {
            'success': false,
            'message': 'Select at least one service.',
            'data': null,
          });
        }
        final now = DateTime.now();
        final seq = _contracts.length + 1;
        final uuid = 'c-req-$seq';
        DateTime? desiredStart;
        final desiredRaw = body['desired_start_date'] as String?;
        if (desiredRaw != null && desiredRaw.isNotEmpty) {
          desiredStart = DateTime.tryParse(desiredRaw);
        }
        final contract = fakeContract(
          uuid: uuid,
          status: 'REQUESTED',
          name: allServices ? 'All eligible services' : 'Selected services',
          startsAt: desiredStart ?? now.add(const Duration(days: 14)),
          createdAt: now,
        );
        contract['requested_all_services'] = allServices;
        contract['covered_services'] = const [];
        contract['customer_note'] = body['customer_note'];
        contract['property'] = {
          'uuid': property['uuid'],
          'label': property['label'],
        };
        _contracts.insert(0, contract);
        return _json(201, {
          'success': true,
          'message': 'Contract request submitted successfully.',
          'data': {'contract': contract},
        });
      }

      final checkout = RegExp(
        r'/contracts/([^/]+)/billing/checkout$',
      ).firstMatch(path);
      if (checkout != null) {
        final contract = _findContract(checkout.group(1)!);
        if (contract == null) {
          return _json(404, {
            'success': false,
            'message': 'Service contract not found.',
            'data': null,
          });
        }
        return _json(201, {
          'success': true,
          'message': 'Billing checkout session created.',
          'data': {
            'billing': {
              'checkout_session_id': 'cs_test',
              'checkout_url': 'https://checkout.stripe.com/c/pay/cs_test',
            },
          },
        });
      }

      final accept = RegExp(r'/contracts/([^/]+)/accept$').firstMatch(path);
      if (accept != null) {
        final contract = _findContract(accept.group(1)!);
        if (contract == null) {
          return _json(404, {
            'success': false,
            'message': 'Service contract not found.',
            'data': null,
          });
        }
        contract['status'] = 'PENDING_PAYMENT';
        contract['acceptance'] = {
          'accepted': true,
          'accepted_at': DateTime.now().toUtc().toIso8601String(),
        };
        final billing = contract['billing'];
        if (billing is Map<String, dynamic>) {
          billing['status'] = 'PENDING_CHECKOUT';
        }
        return _json(200, {
          'success': true,
          'message': 'Service contract accepted.',
          'data': {'contract': contract},
        });
      }

      final book = RegExp(
        r'/contracts/([^/]+)/services/([^/]+)/book$',
      ).firstMatch(path);
      if (book != null && request.method == 'POST') {
        final contract = _findContract(book.group(1)!);
        if (contract == null) {
          return _json(404, {
            'success': false,
            'message': 'Service contract not found.',
            'data': null,
          });
        }
        final slotUuid = body['appointment_slot_uuid'] as String? ?? '';
        if (slotUuid.isEmpty) {
          return _json(422, {
            'success': false,
            'message': 'The given data was invalid.',
            'data': {
              'errors': {
                'appointment_slot_uuid': [
                  'The appointment slot uuid field is required.',
                ],
              },
            },
          });
        }
        return _json(201, {
          'success': true,
          'message': 'Booking created from service contract entitlement.',
          'data': {
            'booking': {
              'uuid': 'booking-contract-1',
              'booking_number': 'BKG-CTR-1',
            },
          },
        });
      }

      final uuid = path.split('/').last;
      final contract = _findContract(uuid);
      if (contract == null) {
        return _json(404, {
          'success': false,
          'message': 'Service contract not found.',
          'data': null,
        });
      }
      if (failContractDetail) {
        return _json(500, {
          'success': false,
          'message': 'Unable to load this contract.',
          'data': null,
        });
      }
      return _json(200, {
        'success': true,
        'message': 'Service contract retrieved successfully.',
        'data': {'contract': contract},
      });
    }

    if (path.endsWith('/contracts')) {
      if (failContracts) {
        return _json(500, {
          'success': false,
          'message': 'Unable to load contracts.',
          'data': null,
        });
      }
      return _json(200, {
        'success': true,
        'message': 'Service contracts retrieved successfully.',
        'data': {
          'contracts': [
            for (final contract in _contracts)
              {
                'uuid': contract['uuid'],
                'contract_number': contract['contract_number'],
                'status': contract['status'],
                'starts_at':
                    (contract['term'] as Map<String, dynamic>?)?['starts_at'],
                'ends_at':
                    (contract['term'] as Map<String, dynamic>?)?['ends_at'],
                'requested_all_services':
                    contract['requested_all_services'] ?? false,
                'created_at': contract['created_at'],
              },
          ],
        },
      });
    }

    if (path.endsWith('/cart') && request.method == 'DELETE') {
      _cartItems.clear();
      _checkoutLocation = null;
      _checkoutHold = null;
      return _json(200, {
        'success': true,
        'message': 'Cart cleared successfully.',
        'data': _cartPayload(),
      });
    }

    if (path.endsWith('/cart')) {
      return _json(200, {
        'success': true,
        'message': 'Cart retrieved successfully.',
        'data': _cartPayload(),
      });
    }

    final pricingPreviewMatch = RegExp(
      r'/services/([^/]+)/pricing-preview$',
    ).firstMatch(path);
    if (pricingPreviewMatch != null && request.method == 'POST') {
      final slug = pricingPreviewMatch.group(1)!;
      Map<String, dynamic>? listed;
      for (final service in _serviceList) {
        if (service['slug'] == slug) {
          listed = service;
          break;
        }
      }
      listed ??= _serviceDetails[slug] as Map<String, dynamic>?;
      if (listed == null) {
        return _json(404, {
          'success': false,
          'message': 'Service not found.',
          'data': null,
        });
      }
      final preview =
          listed['pricing_preview'] as Map<String, dynamic>? ??
          const {
            'pricing_status': 'PRICED',
            'unit_total': '120.000000',
            'currency': {'code': 'AED', 'symbol': 'AED', 'minor_unit': 2},
          };
      return _json(200, {
        'success': true,
        'message': 'Pricing preview retrieved successfully.',
        'data': {'pricing_preview': preview},
      });
    }

    final serviceDetailMatch = RegExp(r'/services/([^/]+)$').firstMatch(path);
    if (serviceDetailMatch != null) {
      final slug = serviceDetailMatch.group(1)!;
      final detail = _serviceDetails[slug];
      if (detail == null) {
        return _json(404, {
          'success': false,
          'message': 'Service not found.',
          'data': null,
        });
      }
      return _json(200, {
        'success': true,
        'message': 'Service details retrieved successfully.',
        'data': detail,
      });
    }

    if (path.endsWith('/services')) {
      final capability = request.url.queryParameters['capability'];
      var services = List<Map<String, dynamic>>.from(_serviceList);
      if (capability != null && capability.isNotEmpty) {
        services = [
          for (final service in services)
            if ((service['capabilities'] as List<dynamic>? ?? const [])
                .contains(capability))
              service,
        ];
      }
      return _json(200, {
        'success': true,
        'message': 'Services retrieved successfully.',
        'data': {
          'query': request.url.queryParameters['q'],
          'category': null,
          'services': services,
        },
      });
    }

    return _json(404, {
      'success': false,
      'message': 'Not found.',
      'data': null,
    });
  }

  void _seedReadyCheckout() {
    _checkoutLocation = {
      'property_type': {'id': 1, 'code': 'APARTMENT', 'name': 'Apartment'},
      'other_property_type_name': null,
      'area': {'id': 8, 'code': 'DUBAI_MARINA', 'name': 'Dubai Marina'},
      'city': {'id': 2, 'code': 'DUBAI', 'name': 'Dubai'},
      'street_name': 'Al Marsa Street',
      'address_line': 'Marina Tower 4, Unit 1204',
      'building_name_or_number': 'Marina Tower 4',
      'floor_number': '12',
      'unit_number': '1204',
      'nearby_landmark': null,
      'additional_location_notes': null,
      'visit_contact_phone': '501234567',
    };
    final start = DateTime.now().toUtc().add(const Duration(days: 1));
    final slotStart = DateTime.utc(start.year, start.month, start.day, 16);
    _checkoutHold = {
      'hold_uuid': 'hold-1',
      'slot': {
        'uuid': 'slot-16',
        'starts_at': slotStart.toIso8601String(),
        'ends_at': slotStart.add(const Duration(hours: 2)).toIso8601String(),
        'time_window': {'code': 'STANDARD', 'name': 'Standard Hours'},
      },
      'expires_at': DateTime.now()
          .toUtc()
          .add(const Duration(minutes: 10))
          .toIso8601String(),
    };
  }

  Map<String, dynamic> _cartPayload() {
    var status = 'PRICED';
    var requiresQuote = false;
    var total = 0.0;
    for (final item in _cartItems) {
      final pricing = item['pricing'] as Map<String, dynamic>? ?? const {};
      final itemStatus = pricing['pricing_status'] as String? ?? 'PRICED';
      if (itemStatus == 'QUOTE_REQUIRED') {
        status = 'QUOTE_REQUIRED';
        requiresQuote = true;
      } else if (itemStatus == 'MISSING_CONTEXT' && status == 'PRICED') {
        status = 'MISSING_CONTEXT';
      } else if (itemStatus == 'UNAVAILABLE' && status == 'PRICED') {
        status = 'UNAVAILABLE';
      }
      if (itemStatus == 'PRICED') {
        total += double.tryParse('${pricing['line_total']}') ?? 0;
      }
    }
    return {
      'cart': {
        'uuid': _cartItems.isEmpty ? null : 'cart-uuid',
        'currency': {'code': 'AED', 'symbol': 'AED', 'decimal_places': 2},
        'pricing_status': _cartItems.isEmpty ? 'PRICED' : status,
        'required_context': const <String>[],
        'requires_quote': requiresQuote,
        'items': List<Map<String, dynamic>>.from(_cartItems),
        'total': status == 'PRICED' ? total.toStringAsFixed(6) : null,
      },
    };
  }

  Map<String, dynamic>? _findSupportRequest(String uuid) {
    for (final row in _supportRequests) {
      if (row['uuid'] == uuid) {
        return row;
      }
    }
    return null;
  }

  Map<String, dynamic> _supportListItem(Map<String, dynamic> row) {
    return {
      'uuid': row['uuid'],
      'request_number': row['request_number'],
      'subject': row['subject'],
      'status': row['status'],
      'created_at': row['created_at'],
      'updated_at': row['updated_at'],
      'message_count': row['message_count'] ?? 0,
      if (row['messages'] != null) 'messages': row['messages'],
    };
  }

  Map<String, dynamic> _supportDetail(Map<String, dynamic> row) {
    final messages = row['messages'] as List<dynamic>? ?? const [];
    return {
      'uuid': row['uuid'],
      'request_number': row['request_number'],
      'subject': row['subject'],
      'status': row['status'],
      'created_at': row['created_at'],
      'updated_at': row['updated_at'],
      'message_count': row['message_count'] ?? messages.length,
      'messages': messages,
    };
  }

  static List<Map<String, dynamic>> _defaultSupportRequests() {
    return [
      {
        'uuid': 'req-2481',
        'request_number': 'REQ-2481',
        'subject': 'AC service didn’t cover the second unit',
        'status': 'IN_PROGRESS',
        'created_at': '2026-08-24T10:00:00.000Z',
        'updated_at': '2026-08-30T10:00:00.000Z',
        'message_count': 3,
        'messages': [
          {
            'uuid': 'msg-2481-1',
            'from_support': false,
            'message_body':
                'The technician cleaned the living room unit but said the bedroom unit wasn’t on the job sheet. Both units were included when I booked.',
            'created_at': '2026-08-24T10:05:00.000Z',
          },
          {
            'uuid': 'msg-2481-2',
            'from_support': true,
            'message_body':
                'Thanks for flagging this. I can see both units on the booking and I’m checking with the field team now.',
            'created_at': '2026-08-24T14:20:00.000Z',
          },
          {
            'uuid': 'msg-2481-3',
            'from_support': true,
            'message_body':
                'We’ve asked the supervisor to revisit and finish the bedroom unit at no extra charge.',
            'created_at': '2026-08-25T09:15:00.000Z',
          },
        ],
      },
      {
        'uuid': 'req-invoice',
        'request_number': 'REQ-2408',
        'subject': 'Invoice for August cleaning contract',
        'status': 'OPEN',
        'created_at': '2026-08-24T09:00:00.000Z',
        'updated_at': '2026-08-24T09:00:00.000Z',
        'message_count': 1,
        'messages': [
          {
            'uuid': 'msg-invoice-1',
            'from_support': false,
            'message_body':
                'Could you resend the August invoice for my cleaning contract?',
            'created_at': '2026-08-24T09:00:00.000Z',
          },
        ],
      },
      {
        'uuid': 'req-plumb',
        'request_number': 'REQ-2311',
        'subject': 'Reschedule a plumbing visit',
        'status': 'RESOLVED',
        'created_at': '2026-08-05T11:00:00.000Z',
        'updated_at': '2026-08-11T14:00:00.000Z',
        'message_count': 2,
        'messages': [
          {
            'uuid': 'msg-plumb-1',
            'from_support': false,
            'message_body': 'I need to move my plumbing visit to next week.',
            'created_at': '2026-08-05T11:00:00.000Z',
          },
          {
            'uuid': 'msg-plumb-2',
            'from_support': true,
            'message_body': 'Done — we moved the visit to 18 Aug.',
            'created_at': '2026-08-11T14:00:00.000Z',
          },
        ],
      },
    ];
  }

  Map<String, dynamic> _checkoutPayload() {
    final cart = _cartPayload()['cart'] as Map<String, dynamic>;
    final items = <Map<String, dynamic>>[];
    for (final item in _cartItems) {
      items.add({...item, 'cart_item_uuid': item['uuid']});
    }
    final status = cart['pricing_status'] as String? ?? 'PRICED';
    return {
      'checkout': {
        'cart_uuid': cart['uuid'],
        'location': _checkoutLocation,
        'appointment': _checkoutHold,
        'pricing_status': status,
        'required_context': cart['required_context'],
        'requires_quote': cart['requires_quote'] == true,
        'ready_for_payment':
            items.isNotEmpty &&
            _checkoutLocation != null &&
            _checkoutHold != null &&
            status == 'PRICED',
        'currency': cart['currency'],
        'items': items,
        'total': cart['total'],
      },
    };
  }

  Map<String, dynamic> _buildCartItem({
    required String uuid,
    required String serviceUuid,
    required int quantity,
    required List<Map<String, dynamic>> options,
  }) {
    Map<String, dynamic>? listed;
    for (final service in _serviceList) {
      if (service['uuid'] == serviceUuid) {
        listed = service;
        break;
      }
    }
    listed ??= _serviceList.first;
    final preview =
        listed['pricing_preview'] as Map<String, dynamic>? ?? const {};
    final status = preview['pricing_status'] as String? ?? 'PRICED';
    final unit = preview['unit_total'] as String?;
    String? line;
    if (unit != null) {
      final amount = (double.tryParse(unit) ?? 0) * quantity;
      line = amount.toStringAsFixed(6);
    }
    return {
      'uuid': uuid,
      'service': {
        'uuid': listed['uuid'],
        'slug': listed['slug'],
        'name': listed['name'],
        'primary_image': listed['primary_image'],
      },
      'quantity': quantity,
      'options': options,
      'pricing': {
        'pricing_status': status,
        'currency': 'AED',
        'unit_total': unit,
        'line_total': line,
        'quantity': quantity,
        'required_context': const <String>[],
      },
    };
  }

  static List<Map<String, dynamic>> _fakeAppointmentSlots() {
    final start = DateTime.now().toUtc().add(const Duration(days: 2));
    final day = DateTime.utc(start.year, start.month, start.day);
    Map<String, dynamic> slot(int hour) {
      final slotStart = DateTime.utc(day.year, day.month, day.day, hour);
      return {
        'uuid': 'slot-$hour',
        'starts_at': slotStart.toIso8601String(),
        'ends_at': slotStart.add(const Duration(hours: 2)).toIso8601String(),
        'remaining_capacity': 3,
        'time_window': {'code': 'STANDARD', 'name': 'Standard Hours'},
      };
    }

    return [slot(9), slot(11), slot(14), slot(16), slot(18)];
  }

  static const _serviceList = [
    {
      'uuid': 'svc-ac-deep',
      'code': 'AC_DEEP_CLEAN',
      'slug': 'ac-deep-clean',
      'name': 'AC deep cleaning',
      'short_description':
          'Professional deep cleaning for residential AC systems.',
      'category': {'id': 2, 'code': 'AC', 'name': 'AC services'},
      'capabilities': ['CART_ELIGIBLE', 'SUBSCRIPTION'],
      'primary_image': null,
      'pricing_preview': {
        'pricing_status': 'PRICED',
        'unit_total': '120.000000',
        'currency': {'code': 'AED', 'symbol': 'AED', 'minor_unit': 2},
      },
    },
    {
      'uuid': 'svc-elec',
      'code': 'ELECTRICAL_REPAIR',
      'slug': 'electrical-repair',
      'name': 'Electrical repair',
      'short_description':
          'Certified electricians for sockets, lighting and distribution panels.',
      'category': {'id': 3, 'code': 'ELECTRICAL', 'name': 'Electrical'},
      'capabilities': ['CART_ELIGIBLE'],
      'primary_image': null,
      'pricing_preview': {
        'pricing_status': 'QUOTE_REQUIRED',
        'unit_total': null,
        'currency': null,
      },
    },
  ];

  static const _serviceDetails = {
    'ac-deep-clean': {
      'uuid': 'svc-ac-deep',
      'code': 'AC_DEEP_CLEAN',
      'slug': 'ac-deep-clean',
      'name': 'AC deep cleaning',
      'short_description':
          'Professional deep cleaning for residential AC systems.',
      'description':
          'Our technicians open each indoor unit, remove and wash the filters, deep clean the cooling coil and blower wheel, sanitise the drain pan and clear the drain line. Units are reassembled and tested for airflow and cooling before the visit ends. Expect around 45 minutes per unit and a short report at the end of the job.',
      'category': {
        'id': 2,
        'code': 'AC',
        'name': 'AC services',
        'description': '',
      },
      'media': <Map<String, dynamic>>[],
      'pricing_preview': {
        'pricing_status': 'PRICED',
        'currency': 'AED',
        'unit_total': '120.000000',
        'line_total': '120.000000',
        'quantity': 1,
        'required_context': <String>[],
      },
      'options': [
        {
          'uuid': 'opt-unit-type',
          'code': 'UNIT_TYPE',
          'name': 'Unit type',
          'description': '',
          'type': 'SINGLE_SELECT',
          'is_required': true,
          'selection_rule': {'minimum_selections': 1, 'maximum_selections': 1},
          'choices': [
            {
              'uuid': 'ch-split',
              'code': 'SPLIT',
              'name': 'Split',
              'description': null,
            },
            {
              'uuid': 'ch-window',
              'code': 'WINDOW',
              'name': 'Window',
              'description': null,
            },
            {
              'uuid': 'ch-ducted',
              'code': 'DUCTED',
              'name': 'Ducted',
              'description': null,
            },
          ],
        },
        {
          'uuid': 'opt-units',
          'code': 'NUM_AC_UNITS',
          'name': 'Number of AC units',
          'description':
              '1 to 20 units. This is how many units we service, not how many bookings.',
          'type': 'NUMBER',
          'is_required': true,
          'numeric_rule': {
            'min_value': '1.000000',
            'max_value': '20.000000',
            'step_value': '1.000000',
            'default_value': '1.000000',
            'decimal_places': 0,
            'measurement_unit': {
              'id': 1,
              'code': 'UNIT',
              'name': 'Unit',
              'symbol': 'unit',
            },
          },
        },
        {
          'uuid': 'opt-addons',
          'code': 'ADDONS',
          'name': 'Add-ons',
          'description': 'Select all that apply.',
          'type': 'MULTI_SELECT',
          'is_required': false,
          'selection_rule': {'minimum_selections': 0, 'maximum_selections': 3},
          'choices': [
            {
              'uuid': 'ch-filter',
              'code': 'FILTER',
              'name': 'Premium filter',
              'description': null,
            },
            {
              'uuid': 'ch-coil',
              'code': 'COIL',
              'name': 'Coil sanitisation',
              'description': null,
            },
            {
              'uuid': 'ch-drain',
              'code': 'DRAIN',
              'name': 'Drain line flush',
              'description': null,
            },
          ],
        },
        {
          'uuid': 'opt-height',
          'code': 'ABOVE_3M',
          'name': 'Any unit mounted above 3 m?',
          'description': 'We bring a taller ladder and a second technician.',
          'type': 'BOOLEAN',
          'is_required': true,
        },
        {
          'uuid': 'opt-notes',
          'code': 'NOTES',
          'name': 'Additional instructions',
          'description': '',
          'type': 'TEXT',
          'is_required': false,
        },
      ],
    },
    'electrical-repair': {
      'uuid': 'svc-elec',
      'code': 'ELECTRICAL_REPAIR',
      'slug': 'electrical-repair',
      'name': 'Electrical repair',
      'short_description':
          'Certified electricians for sockets, lighting and distribution panels.',
      'description':
          'A licensed electrician diagnoses the fault on site and repairs it where parts allow. Work covers sockets, switches, light fittings, breakers and distribution panels in residential properties. Scope and materials are confirmed with you before any work starts.',
      'category': {
        'id': 4,
        'code': 'ELECTRICAL',
        'name': 'Electrical services',
        'description': '',
      },
      'media': <Map<String, dynamic>>[],
      'pricing_preview': {
        'pricing_status': 'QUOTE_REQUIRED',
        'currency': 'AED',
        'unit_total': null,
        'line_total': null,
        'quantity': 1,
        'required_context': <String>[],
      },
      'options': [
        {
          'uuid': 'opt-fault',
          'code': 'FAULT',
          'name': 'What needs attention?',
          'description':
              'A short description helps us send the right electrician.',
          'type': 'TEXT',
          'is_required': true,
        },
        {
          'uuid': 'opt-areas',
          'code': 'AREAS',
          'name': 'Areas affected',
          'description': 'Select all that apply.',
          'type': 'MULTI_SELECT',
          'is_required': false,
          'selection_rule': {'minimum_selections': 0, 'maximum_selections': 3},
          'choices': [
            {
              'uuid': 'ch-sockets',
              'code': 'SOCKETS',
              'name': 'Sockets and switches',
              'description': null,
            },
            {
              'uuid': 'ch-lighting',
              'code': 'LIGHTING',
              'name': 'Lighting',
              'description': null,
            },
            {
              'uuid': 'ch-panel',
              'code': 'PANEL',
              'name': 'Distribution panel',
              'description': null,
            },
          ],
        },
      ],
    },
  };

  Map<String, dynamic>? _findContract(String uuid) {
    for (final row in _contracts) {
      if (row['uuid'] == uuid) return row;
    }
    return null;
  }

  Map<String, dynamic> _propertyFromBody(
    Map<String, dynamic> body,
    String uuid, {
    Map<String, dynamic>? current,
  }) {
    final typeId =
        (body['property_type_id'] as num?)?.toInt() ??
        ((current?['property_type'] as Map?)?['id'] as num?)?.toInt() ??
        1;
    final relationId =
        (body['property_relationship_type_id'] as num?)?.toInt() ??
        ((current?['relationship_type'] as Map?)?['id'] as num?)?.toInt() ??
        1;
    final areaId =
        (body['area_id'] as num?)?.toInt() ??
        ((current?['area'] as Map?)?['id'] as num?)?.toInt() ??
        8;
    final types = {
      1: {'id': 1, 'code': 'APARTMENT', 'name': 'Apartment'},
      2: {'id': 2, 'code': 'VILLA', 'name': 'Villa'},
      3: {'id': 3, 'code': 'OFFICE', 'name': 'Office'},
      4: {'id': 4, 'code': 'OTHER', 'name': 'Other'},
    };
    final relations = {
      1: {'id': 1, 'code': 'OWNER', 'name': 'Owner'},
      2: {'id': 2, 'code': 'TENANT', 'name': 'Tenant'},
      3: {'id': 3, 'code': 'MANAGER', 'name': 'Property manager'},
      4: {'id': 4, 'code': 'FAMILY_MEMBER', 'name': 'Family member'},
    };
    final areas = {
      8: {
        'id': 8,
        'name': 'Dubai Marina',
        'city_name': 'Dubai',
        'country_name': 'United Arab Emirates',
      },
      9: {
        'id': 9,
        'name': 'Downtown Dubai',
        'city_name': 'Dubai',
        'country_name': 'United Arab Emirates',
      },
      10: {
        'id': 10,
        'name': 'Business Bay',
        'city_name': 'Dubai',
        'country_name': 'United Arab Emirates',
      },
      11: {
        'id': 11,
        'name': 'Palm Jumeirah',
        'city_name': 'Dubai',
        'country_name': 'United Arab Emirates',
      },
      20: {
        'id': 20,
        'name': 'Al Reem Island',
        'city_name': 'Abu Dhabi',
        'country_name': 'United Arab Emirates',
      },
    };
    return {
      'uuid': uuid,
      'label': body['label'] ?? current?['label'] ?? 'Property',
      'relationship_type': relations[relationId] ?? relations[1],
      'property_type': types[typeId] ?? types[1],
      'other_property_type_name': body.containsKey('other_property_type_name')
          ? body['other_property_type_name']
          : current?['other_property_type_name'],
      'area': areas[areaId] ?? areas[8],
      'street_name': body['street_name'] ?? current?['street_name'] ?? '',
      'address_line': body['address_line'] ?? current?['address_line'] ?? '',
      'building_name_or_number':
          body['building_name_or_number'] ??
          current?['building_name_or_number'] ??
          '',
      'floor_number': body.containsKey('floor_number')
          ? body['floor_number']
          : current?['floor_number'],
      'unit_number': body.containsKey('unit_number')
          ? body['unit_number']
          : current?['unit_number'],
      'nearby_landmark': current?['nearby_landmark'],
      'additional_location_notes': current?['additional_location_notes'],
      'visit_contact_phone':
          body['visit_contact_phone'] ??
          current?['visit_contact_phone'] ??
          '+971501234567',
      'is_active': current?['is_active'] ?? true,
      'created_at':
          current?['created_at'] ?? DateTime.now().toUtc().toIso8601String(),
      'updated_at': DateTime.now().toUtc().toIso8601String(),
    };
  }

  http.StreamedResponse _json(int status, Map<String, dynamic> body) {
    final encoded = utf8.encode(jsonEncode(body));
    return http.StreamedResponse(
      Stream<List<int>>.fromIterable([encoded]),
      status,
      headers: const {'content-type': 'application/json'},
    );
  }
}

Map<String, dynamic> _defaultProfile() {
  return {
    'user_uuid': '3f2a1c9e-1111-1111-1111-111111111111',
    'full_name': 'Layla Hassan',
    'email': 'layla@example.com',
    'phone_number': '+971501234567',
    'phone_verified': true,
    'account_status': 'ACTIVE',
    'location': {
      'city': {'id': 2, 'code': 'DUBAI', 'name': 'Dubai'},
      'area': {'id': 8, 'code': 'DUBAI_MARINA', 'name': 'Dubai Marina'},
    },
    'property_relationship': {'id': 1, 'code': 'OWNER', 'name': 'Owner'},
    'service_interests': [
      {'id': 1, 'code': 'CLEANING', 'name': 'Cleaning'},
      {'id': 2, 'code': 'AC', 'name': 'AC services'},
    ],
  };
}

List<Map<String, dynamic>> _defaultProperties() {
  return [
    {
      'uuid': 'prop-apt',
      'label': 'Apartment',
      'relationship_type': {'id': 1, 'code': 'OWNER', 'name': 'Owner'},
      'property_type': {'id': 1, 'code': 'APARTMENT', 'name': 'Apartment'},
      'other_property_type_name': null,
      'area': {
        'id': 8,
        'name': 'Dubai Marina',
        'city_name': 'Dubai',
        'country_name': 'United Arab Emirates',
      },
      'street_name': 'Al Marsa Street',
      'address_line': 'Marina Tower 4',
      'building_name_or_number': 'Marina Tower 4',
      'floor_number': '12',
      'unit_number': '1204',
      'nearby_landmark': null,
      'additional_location_notes': null,
      'visit_contact_phone': '+971501234567',
      'is_active': true,
      'created_at': '2026-01-01T10:00:00.000Z',
      'updated_at': '2026-01-01T10:00:00.000Z',
    },
    {
      'uuid': 'prop-villa',
      'label': 'Villa',
      'relationship_type': {'id': 1, 'code': 'OWNER', 'name': 'Owner'},
      'property_type': {'id': 2, 'code': 'VILLA', 'name': 'Villa'},
      'other_property_type_name': null,
      'area': {
        'id': 11,
        'name': 'Palm Jumeirah',
        'city_name': 'Dubai',
        'country_name': 'United Arab Emirates',
      },
      'street_name': 'Palm Jumeirah',
      'address_line': 'Villa 18',
      'building_name_or_number': 'Villa 18',
      'floor_number': null,
      'unit_number': null,
      'nearby_landmark': null,
      'additional_location_notes': null,
      'visit_contact_phone': '+971501234567',
      'is_active': true,
      'created_at': '2026-01-01T09:00:00.000Z',
      'updated_at': '2026-01-01T09:00:00.000Z',
    },
    {
      'uuid': 'prop-office',
      'label': 'Office',
      'relationship_type': {'id': 2, 'code': 'TENANT', 'name': 'Tenant'},
      'property_type': {'id': 3, 'code': 'OFFICE', 'name': 'Office'},
      'other_property_type_name': null,
      'area': {
        'id': 10,
        'name': 'Business Bay',
        'city_name': 'Dubai',
        'country_name': 'United Arab Emirates',
      },
      'street_name': 'Business Bay',
      'address_line': 'Bay Square',
      'building_name_or_number': 'Bay Square',
      'floor_number': null,
      'unit_number': '204',
      'nearby_landmark': null,
      'additional_location_notes': null,
      'visit_contact_phone': '+971501234567',
      'is_active': true,
      'created_at': '2026-01-01T08:00:00.000Z',
      'updated_at': '2026-01-01T08:00:00.000Z',
    },
  ];
}

Map<String, dynamic> fakeBooking({
  required String uuid,
  required String number,
  required String status,
  required String service,
  required DateTime startsAt,
  DateTime? endsAt,
  DateTime? statusChangedAt,
  DateTime? completedAt,
  String area = 'Dubai Marina',
  String building = '',
  String propertyType = '',
  List<String> extraServices = const [],
  List<Map<String, dynamic>>? items,
  int quantity = 1,
  bool canRate = false,
  Map<String, dynamic>? rating,
}) {
  final end = endsAt ?? startsAt.add(const Duration(hours: 2));
  final doneAt = completedAt ?? (status == 'COMPLETED' ? startsAt : null);
  final builtItems =
      items ??
      <Map<String, dynamic>>[
        fakeBookingItem(
          uuid: '$uuid-item-0',
          name: service,
          status: status,
          quantity: quantity,
        ),
        for (var i = 0; i < extraServices.length; i++)
          fakeBookingItem(
            uuid: '$uuid-item-${i + 1}',
            name: extraServices[i],
            status: status,
          ),
      ];
  return {
    'uuid': uuid,
    'booking_number': number,
    'status': status,
    'location': {
      'area_name': area,
      'city_name': 'Dubai',
      'building_name_or_number': building,
      if (propertyType.isNotEmpty) 'property_type_name': propertyType,
    },
    'appointment': {
      'slot': {
        'uuid': '$uuid-slot',
        'starts_at': startsAt.toUtc().toIso8601String(),
        'ends_at': end.toUtc().toIso8601String(),
      },
    },
    'items': builtItems,
    'created_at': startsAt.toUtc().toIso8601String(),
    'status_changed_at': (statusChangedAt ?? startsAt)
        .toUtc()
        .toIso8601String(),
    if (doneAt != null) 'completed_at': doneAt.toUtc().toIso8601String(),
    'can_rate': canRate,
    if (rating != null) 'rating': rating,
  };
}

Map<String, dynamic> fakeBookingItem({
  required String uuid,
  required String name,
  required String status,
  int quantity = 1,
  bool canRate = false,
  Map<String, dynamic>? rating,
}) {
  return {
    'uuid': uuid,
    'service': {'uuid': '$uuid-svc', 'code': 'SVC', 'name': name},
    'quantity': quantity,
    'status': switch (status) {
      'IN_PROGRESS' => 'IN_PROGRESS',
      'COMPLETED' => 'COMPLETED',
      'CANCELLED' => 'CANCELLED',
      _ => 'PENDING_ASSIGNMENT',
    },
    'can_rate': canRate,
    if (rating != null) 'rating': rating,
  };
}

Map<String, dynamic> fakeContract({
  required String uuid,
  required String status,
  required String name,
  required DateTime startsAt,
  DateTime? endsAt,
  DateTime? createdAt,
  DateTime? updatedAt,
  String number = '',
  List<String> extraServices = const [],
  String billingStatus = 'ACTIVE',
  String billingAmount = '1200.00',
  DateTime? periodEnd,
  List<int?> visits = const [],
  List<int> used = const [],
  List<String> descriptions = const [],
  String termsReference = '',
  List<Map<String, dynamic>> bookings = const [],
  List<Map<String, dynamic>> bills = const [],
}) {
  final end =
      endsAt ?? DateTime(startsAt.year + 1, startsAt.month, startsAt.day);
  final services = [name, ...extraServices];
  return {
    'uuid': uuid,
    'contract_number': number.isEmpty ? 'CTR-$uuid' : number,
    'status': status,
    'term': {
      'starts_at': startsAt.toUtc().toIso8601String(),
      'ends_at': end.toUtc().toIso8601String(),
      'term_months': 12,
    },
    'quoted_amount': billingAmount,
    'currency': {'code': 'AED', 'symbol': 'AED', 'decimal_places': 2},
    'requested_all_services': false,
    'terms_reference': termsReference,
    'covered_services': [
      for (var i = 0; i < services.length; i++)
        {
          'contract_item_uuid': '$uuid-item-$i',
          'service': {
            'uuid': '$uuid-svc-$i',
            'code': 'SVC_$i',
            'name': services[i],
          },
          'description': i < descriptions.length ? descriptions[i] : '',
          'entitlement_mode': i < visits.length && visits[i] != null
              ? 'LIMITED_VISITS'
              : 'UNLIMITED',
          'included_visits': i < visits.length ? visits[i] : null,
          'used_visits': i < used.length ? used[i] : 0,
          'remaining_visits': i < visits.length && visits[i] != null
              ? visits[i]! - (i < used.length ? used[i] : 0)
              : null,
        },
    ],
    'acceptance': {
      'accepted':
          status != 'PENDING_CUSTOMER_ACCEPTANCE' &&
          status != 'REQUESTED' &&
          status != 'APPROVED',
      'accepted_at': createdAt?.toUtc().toIso8601String(),
    },
    'billing': status == 'EXPIRED' || status == 'CANCELLED'
        ? (bills.isEmpty
              ? null
              : {
                  'status': billingStatus,
                  'billing_interval': 'YEARLY',
                  'recurring_amount': billingAmount,
                  'currency': {
                    'code': 'AED',
                    'symbol': 'AED',
                    'decimal_places': 2,
                  },
                  'recent_bills': bills,
                })
        : {
            'status': billingStatus,
            'billing_interval': 'YEARLY',
            'recurring_amount': billingAmount,
            'currency': {'code': 'AED', 'symbol': 'AED', 'decimal_places': 2},
            'current_period_end': (periodEnd ?? end).toUtc().toIso8601String(),
            'cancel_at': null,
            'recent_bills': bills,
          },
    'bookings': bookings,
    'created_at': (createdAt ?? startsAt).toUtc().toIso8601String(),
    'updated_at': (updatedAt ?? createdAt ?? startsAt)
        .toUtc()
        .toIso8601String(),
  };
}
