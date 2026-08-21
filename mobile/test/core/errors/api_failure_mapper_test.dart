import 'package:blue/core/errors/api_failure.dart';
import 'package:blue/core/errors/api_failure_mapper.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

RequestOptions _options() => RequestOptions(path: '/v1/test');

DioException _withResponse(int statusCode, Map<String, dynamic> data) {
  final options = _options();

  return DioException(
    requestOptions: options,
    type: DioExceptionType.badResponse,
    response: Response<dynamic>(
      requestOptions: options,
      statusCode: statusCode,
      data: data,
    ),
  );
}

void main() {
  group('mapDioExceptionToApiFailure - status codes', () {
    test('401 maps to SessionExpired', () {
      final failure = mapDioExceptionToApiFailure(
        _withResponse(401, {'success': false, 'message': 'unauthenticated'}),
      );

      expect(failure, isA<SessionExpired>());
    });

    test('404 maps to NotFound', () {
      final failure = mapDioExceptionToApiFailure(
        _withResponse(404, {'success': false, 'message': 'not found'}),
      );

      expect(failure, isA<NotFound>());
    });

    test('409 maps to Conflict with the backend message', () {
      final failure = mapDioExceptionToApiFailure(
        _withResponse(409, {
          'success': false,
          'message': 'A payment is already in progress for this checkout.',
        }),
      );

      expect(failure, isA<Conflict>());
      expect(
        (failure as Conflict).message,
        'A payment is already in progress for this checkout.',
      );
    });

    test(
      '422 with errors maps to ValidationOrBusinessRejection with fieldErrors',
      () {
        final failure = mapDioExceptionToApiFailure(
          _withResponse(422, {
            'success': false,
            'message': 'The given data was invalid.',
            'errors': {
              'email': ['The email has already been taken.'],
            },
          }),
        );

        expect(failure, isA<ValidationOrBusinessRejection>());
        final rejection = failure as ValidationOrBusinessRejection;
        expect(rejection.message, 'The given data was invalid.');
        expect(rejection.fieldErrors, {
          'email': ['The email has already been taken.'],
        });
      },
    );

    test('422 with only a message maps with null fieldErrors', () {
      final failure = mapDioExceptionToApiFailure(
        _withResponse(422, {
          'success': false,
          'message': 'The current password you entered is incorrect.',
        }),
      );

      final rejection = failure as ValidationOrBusinessRejection;
      expect(rejection.fieldErrors, isNull);
    });

    test('429 maps to RateLimited', () {
      final failure = mapDioExceptionToApiFailure(_withResponse(429, {}));

      expect(failure, isA<RateLimited>());
    });

    test('500 maps to ServerError', () {
      final failure = mapDioExceptionToApiFailure(_withResponse(500, {}));

      expect(failure, isA<ServerError>());
    });

    test('503 maps to ServerError', () {
      final failure = mapDioExceptionToApiFailure(_withResponse(503, {}));

      expect(failure, isA<ServerError>());
    });
  });

  group('mapDioExceptionToApiFailure - transport-level failures', () {
    test('connectionError maps to NetworkUnavailable', () {
      final options = _options();
      final failure = mapDioExceptionToApiFailure(
        DioException(
          requestOptions: options,
          type: DioExceptionType.connectionError,
        ),
      );

      expect(failure, isA<NetworkUnavailable>());
    });

    test('connectionTimeout maps to TimeoutFailure', () {
      final options = _options();
      final failure = mapDioExceptionToApiFailure(
        DioException(
          requestOptions: options,
          type: DioExceptionType.connectionTimeout,
        ),
      );

      expect(failure, isA<TimeoutFailure>());
    });

    test('receiveTimeout maps to TimeoutFailure', () {
      final options = _options();
      final failure = mapDioExceptionToApiFailure(
        DioException(
          requestOptions: options,
          type: DioExceptionType.receiveTimeout,
        ),
      );

      expect(failure, isA<TimeoutFailure>());
    });

    test(
      'an unrecognized response shape falls back to ServerError, never throws',
      () {
        final options = _options();
        final failure = mapDioExceptionToApiFailure(
          DioException(
            requestOptions: options,
            type: DioExceptionType.badResponse,
            response: Response<dynamic>(
              requestOptions: options,
              statusCode: 418,
              data: 'not a json object',
            ),
          ),
        );

        expect(failure, isA<ServerError>());
      },
    );
  });
}
